<?php

// Orchestrated in three modes: setup, worker-a/worker-b concurrently, then verify.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This smoke suite must run through WP-CLI.' );
}

$mode    = $args[0] ?? '';
$option  = 'stockino_cost_concurrency_fixture';
$request = static function ( string $method, string $route, array $params = array() ) {
	$rest = new WP_REST_Request( $method, $route );
	$rest->set_body_params( $params );
	return rest_do_request( $rest );
};
wp_set_current_user( 1 );
global $wpdb;

if ( 'setup' === $mode ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Phase 4 concurrent owner' );
	$product->set_sku( 'COST-CONCURRENT-' . wp_generate_password( 8, false, false ) );
	$product->set_status( 'publish' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 0 );
	$product_id = $product->save();
	$supplier   = $request(
		'POST',
		'/stockino/v1/suppliers',
		array(
			'name' => 'Phase 4 concurrent supplier',
			'code' => 'CC-' . wp_generate_password( 8, false, false ),
		)
	)->get_data();
	$request( 'POST', "/stockino/v1/suppliers/{$supplier['id']}/products", array( 'product_id' => $product_id ) );
	$orders = array();
	foreach ( array( '10.000000', '20.000000' ) as $cost ) {
		$order = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier['id'] ) )->get_data();
		$line  = $request(
			'POST',
			"/stockino/v1/purchase-orders/{$order['id']}/items",
			array(
				'product_id'        => $product_id,
				'ordered_quantity'  => '1',
				'ordered_unit_cost' => $cost,
			)
		)->get_data();
		$request( 'POST', "/stockino/v1/purchase-orders/{$order['id']}/mark-ordered" );
		$orders[] = array(
			'id'      => $order['id'],
			'item_id' => $line['id'],
			'cost'    => $cost,
			'key'     => wp_generate_uuid4(),
		);
	}
	update_option(
		$option,
		array(
			'product_id'  => $product_id,
			'supplier_id' => $supplier['id'],
			'orders'      => $orders,
		),
		false
	);
	WP_CLI::success( 'Concurrent costing fixture ready.' );
	return;
}

$fixture = get_option( $option );
if ( ! is_array( $fixture ) ) {
	WP_CLI::error( 'Concurrent costing fixture is missing.' );
}

if ( in_array( $mode, array( 'worker-a', 'worker-b' ), true ) ) {
	$index    = 'worker-a' === $mode ? 0 : 1;
	$order    = $fixture['orders'][ $index ];
	$response = $request(
		'POST',
		"/stockino/v1/purchase-orders/{$order['id']}/receipts",
		array(
			'idempotency_key' => $order['key'],
			'items'           => array(
				array(
					'item_id'          => $order['item_id'],
					'quantity'         => '1',
					'actual_unit_cost' => $order['cost'],
				),
			),
		)
	);
	if ( 201 !== $response->get_status() ) {
		WP_CLI::error( sprintf( '%s failed with HTTP %d: %s', $mode, $response->get_status(), wp_json_encode( $response->get_data() ) ) );
	}
	WP_CLI::success( "{$mode} completed." );
	return;
}

if ( 'verify' === $mode ) {
	$costs         = $wpdb->prefix . 'stockino_inventory_costs';
	$cost_moves    = $wpdb->prefix . 'stockino_inventory_cost_movements';
	$receipts      = $wpdb->prefix . 'stockino_purchase_receipts';
	$receipt_items = $wpdb->prefix . 'stockino_purchase_receipt_items';
	$order_items   = $wpdb->prefix . 'stockino_purchase_order_items';
	$orders        = $wpdb->prefix . 'stockino_purchase_orders';
	$relations     = $wpdb->prefix . 'stockino_supplier_products';
	$suppliers     = $wpdb->prefix . 'stockino_suppliers';
	$product_id    = (int) $fixture['product_id'];
	$average       = $wpdb->get_var( $wpdb->prepare( 'SELECT average_unit_cost FROM %i WHERE stock_owner_id = %d', $costs, $product_id ) );
	$move_count    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE stock_owner_id = %d AND movement_type = 'purchase_receipt'", $cost_moves, $product_id ) );
	$receipt_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE purchase_order_id IN (%d,%d) AND status = 'completed'", $receipts, $fixture['orders'][0]['id'], $fixture['orders'][1]['id'] ) );
	$stock         = (float) wc_get_product( $product_id )->get_stock_quantity();
	if ( '15.000000' !== $average || 2 !== $move_count || 2 !== $receipt_count || 2.0 !== $stock ) {
		WP_CLI::error( sprintf( 'Lost update: average=%s movements=%d receipts=%d stock=%s', $average, $move_count, $receipt_count, $stock ) );
	}
	foreach ( $fixture['orders'] as $order ) {
		$receipt_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE purchase_order_id = %d', $receipts, $order['id'] ) );
		foreach ( $receipt_ids as $receipt_id ) {
			$wpdb->delete( $receipt_items, array( 'receipt_id' => $receipt_id ) );
		}
		$wpdb->delete( $receipts, array( 'purchase_order_id' => $order['id'] ) );
		$wpdb->delete( $order_items, array( 'purchase_order_id' => $order['id'] ) );
		$wpdb->delete( $orders, array( 'id' => $order['id'] ) );
	}
	$wpdb->delete( $cost_moves, array( 'stock_owner_id' => $product_id ) );
	$wpdb->delete( $costs, array( 'stock_owner_id' => $product_id ) );
	$wpdb->delete( $relations, array( 'supplier_id' => $fixture['supplier_id'] ) );
	$wpdb->delete( $suppliers, array( 'id' => $fixture['supplier_id'] ) );
	wp_delete_post( $product_id, true );
	delete_option( $option );
	WP_CLI::success( 'Concurrent receipts from two POs produced average 15.000000 exactly once each with no lost update.' );
	return;
}

WP_CLI::error( 'Use setup, worker-a, worker-b, or verify.' );
