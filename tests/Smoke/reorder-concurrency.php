<?php

// Orchestrate: setup, worker-a/worker-b concurrently, verify.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This smoke suite must run through WP-CLI.' );
}

$mode    = $args[0] ?? '';
$option  = 'stockino_phase5_concurrency_fixture';
$request = static function ( string $method, string $route, array $params = array() ) {
	$rest = new WP_REST_Request( $method, $route );
	$rest->set_body_params( $params );
	return rest_do_request( $rest );
};

wp_set_current_user( 1 );
global $wpdb;

if ( 'setup' === $mode ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Phase 5 concurrent reorder owner' );
	$product->set_sku( 'R5-CONCURRENT-' . wp_generate_password( 8, false, false ) );
	$product->set_status( 'publish' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 0 );
	$product->set_low_stock_amount( 10 );
	$product_id = $product->save();
	$supplier   = $request(
		'POST',
		'/stockino/v1/suppliers',
		array(
			'name' => 'Phase 5 concurrent supplier',
			'code' => 'R5C-' . wp_generate_password( 8, false, false ),
		)
	)->get_data();
	$link       = $request( 'POST', '/stockino/v1/suppliers/' . $supplier['id'] . '/products', array( 'product_id' => $product_id ) );
	if ( 201 !== $link->get_status() ) {
		WP_CLI::error( 'Could not create concurrent reorder relationship.' );
	}
	update_option(
		$option,
		array(
			'product_id'  => $product_id,
			'supplier_id' => (int) $supplier['id'],
		),
		false
	);
	WP_CLI::success( 'Concurrent reorder fixture ready.' );
	return;
}

$fixture = get_option( $option );
if ( ! is_array( $fixture ) ) {
	WP_CLI::error( 'Concurrent reorder fixture is missing.' );
}

if ( in_array( $mode, array( 'worker-a', 'worker-b' ), true ) ) {
	$response = $request( 'POST', '/stockino/v1/reorder/create-purchase-orders', array( 'stock_owner_ids' => array( $fixture['product_id'] ) ) );
	if ( 201 !== $response->get_status() ) {
		WP_CLI::error( "{$mode} failed with HTTP {$response->get_status()}." );
	}
	WP_CLI::success( "{$mode} completed." );
	return;
}

if ( 'verify' === $mode ) {
	$items     = $wpdb->prefix . 'stockino_purchase_order_items';
	$orders    = $wpdb->prefix . 'stockino_purchase_orders';
	$relations = $wpdb->prefix . 'stockino_supplier_products';
	$suppliers = $wpdb->prefix . 'stockino_suppliers';
	$count     = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM %i i INNER JOIN %i po ON po.id = i.purchase_order_id
			WHERE i.reorder_stock_owner_id = %d AND po.status = 'draft'",
			$items,
			$orders,
			$fixture['product_id']
		)
	);
	if ( 1 !== $count ) {
		WP_CLI::error( "Expected exactly one generated draft line; found {$count}." );
	}
	$order_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT purchase_order_id FROM %i WHERE reorder_stock_owner_id = %d', $items, $fixture['product_id'] ) );
	foreach ( $order_ids as $order_id ) {
		$wpdb->delete( $items, array( 'purchase_order_id' => $order_id ) );
		$wpdb->delete( $orders, array( 'id' => $order_id ) );
	}
	$wpdb->delete(
		$relations,
		array(
			'supplier_id' => $fixture['supplier_id'],
			'product_id'  => $fixture['product_id'],
		)
	);
	$wpdb->delete( $suppliers, array( 'id' => $fixture['supplier_id'] ) );
	wp_delete_post( $fixture['product_id'], true );
	delete_option( $option );
	WP_CLI::success( 'Concurrent recommendation requests produced exactly one replenishment draft.' );
	return;
}

WP_CLI::error( 'Use setup, worker-a, worker-b, or verify.' );
