<?php

// Run with: wp eval-file wp-content/plugins/stockino/tests/Smoke/costing.php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This smoke suite must run through WP-CLI.' );
}

$assert      = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
	WP_CLI::log( 'PASS: ' . $message );
};
$request     = static function ( string $method, string $route, array $params = array() ) {
	$rest = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$rest->set_query_params( $params );
	} else {
		$rest->set_body_params( $params );
	}
	return rest_do_request( $rest );
};
$new_product = static function ( string $name, string $sku, float $stock ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_sku( $sku );
	$product->set_status( 'publish' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $stock );
	$product->save();
	return $product;
};

wp_set_current_user( 0 );
$assert( 401 === $request( 'GET', '/stockino/v1/valuation' )->get_status(), 'Unauthorized users cannot read valuation.' );
$assert(
	401 === $request(
		'POST',
		'/stockino/v1/valuation/1/initial-cost',
		array(
			'average_unit_cost' => '1',
			'reason'            => 'no access',
		)
	)->get_status(),
	'Unauthorized users cannot establish cost.'
);

wp_set_current_user( 1 );
global $wpdb;
$suffix          = strtolower( wp_generate_password( 8, false, false ) );
$costs_table     = $wpdb->prefix . 'stockino_inventory_costs';
$cost_moves      = $wpdb->prefix . 'stockino_inventory_cost_movements';
$orders_table    = $wpdb->prefix . 'stockino_purchase_orders';
$items_table     = $wpdb->prefix . 'stockino_purchase_order_items';
$receipts_table  = $wpdb->prefix . 'stockino_purchase_receipts';
$receipt_items   = $wpdb->prefix . 'stockino_purchase_receipt_items';
$relations_table = $wpdb->prefix . 'stockino_supplier_products';
$suppliers_table = $wpdb->prefix . 'stockino_suppliers';

$main     = $new_product( 'Phase 4 weighted product', "COST-{$suffix}-MAIN", 0 );
$legacy   = $new_product( 'Phase 4 legacy stock', "COST-{$suffix}-LEGACY", 5 );
$free     = $new_product( 'Phase 4 zero cost', "COST-{$suffix}-FREE", 0 );
$negative = $new_product( 'Phase 4 negative stock', "COST-{$suffix}-NEG", -2 );
$failure  = $new_product( 'Phase 4 cost failure', "COST-{$suffix}-FAIL", 3 );
$deleted  = $new_product( 'Phase 4 deleted history', "COST-{$suffix}-DELETE", 0 );

$parent = new WC_Product_Variable();
$parent->set_name( 'Phase 4 shared owner' );
$parent->set_status( 'publish' );
$parent->set_manage_stock( true );
$parent->set_stock_quantity( 0 );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Cost mode' );
$attribute->set_options( array( 'Parent', 'Self' ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$parent->set_attributes( array( $attribute ) );
$parent_id = $parent->save();
$shared    = new WC_Product_Variation();
$shared->set_parent_id( $parent_id );
$shared->set_status( 'publish' );
$shared->set_attributes( array( 'cost-mode' => 'Parent' ) );
$shared->set_manage_stock( false );
$shared->set_sku( "COST-{$suffix}-SHARED" );
$shared_id = $shared->save();
$self      = new WC_Product_Variation();
$self->set_parent_id( $parent_id );
$self->set_status( 'publish' );
$self->set_attributes( array( 'cost-mode' => 'Self' ) );
$self->set_manage_stock( true );
$self->set_stock_quantity( 0 );
$self->set_sku( "COST-{$suffix}-SELF" );
$self_id = $self->save();

$supplier_response = $request(
	'POST',
	'/stockino/v1/suppliers',
	array(
		'name' => 'Phase 4 Cost Supplier',
		'code' => 'C4-' . strtoupper( $suffix ),
	)
);
$supplier_id       = (int) $supplier_response->get_data()['id'];
$product_ids       = array( $main->get_id(), $legacy->get_id(), $free->get_id(), $negative->get_id(), $failure->get_id(), $deleted->get_id(), $shared_id, $self_id );
foreach ( $product_ids as $product_id ) {
	$assert( 201 === $request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/products", array( 'product_id' => $product_id ) )->get_status(), "Cost fixture product {$product_id} is linked to its supplier." );
}

$order_ids = array();
$create_po = static function ( int $product_id, string $quantity, ?string $ordered_cost ) use ( $request, $supplier_id, &$order_ids ): array {
	$order       = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) )->get_data();
	$order_ids[] = (int) $order['id'];
	$line        = $request(
		'POST',
		"/stockino/v1/purchase-orders/{$order['id']}/items",
		array(
			'product_id'        => $product_id,
			'ordered_quantity'  => $quantity,
			'ordered_unit_cost' => $ordered_cost,
		)
	)->get_data();
	$request( 'POST', "/stockino/v1/purchase-orders/{$order['id']}/mark-ordered" );
	return array( $order, $line );
};
$receive   = static function ( array $order, array $line, string $quantity, mixed $cost, ?string $key = null ) use ( $request ) {
	$item = array(
		'item_id'  => $line['id'],
		'quantity' => $quantity,
	);
	if ( null !== $cost ) {
		$item['actual_unit_cost'] = $cost;
	}
	return $request(
		'POST',
		"/stockino/v1/purchase-orders/{$order['id']}/receipts",
		array(
			'idempotency_key' => $key ?? wp_generate_uuid4(),
			'items'           => array( $item ),
		)
	);
};

list( $legacy_order, $legacy_line ) = $create_po( $legacy->get_id(), '1', null );
$legacy_before                      = (float) wc_get_product( $legacy->get_id() )->get_stock_quantity();
$unknown                            = $receive( $legacy_order, $legacy_line, '1', null );
// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- The calculated baseline reads naturally on the left in this integration assertion.
$assert( 400 === $unknown->get_status() && $legacy_before === (float) wc_get_product( $legacy->get_id() )->get_stock_quantity(), 'Unknown legacy cost is rejected before stock changes.' );

list( $first_order, $first_line ) = $create_po( $main->get_id(), '10', '10.000000' );
$first                            = $receive( $first_order, $first_line, '10', '10.000000' );
$state                            = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE stock_owner_id = %d', $costs_table, $main->get_id() ), ARRAY_A );
$assert( 201 === $first->get_status() && '10.000000' === $state['average_unit_cost'], 'First receipt establishes average cost exactly.' );

list( $second_order, $second_line ) = $create_po( $main->get_id(), '5', '15.000000' );
$token                              = wp_generate_uuid4();
$second                             = $receive( $second_order, $second_line, '5', '20.000000', $token );
$state                              = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE stock_owner_id = %d', $costs_table, $main->get_id() ), ARRAY_A );
$assert( 201 === $second->get_status() && '13.333333' === $state['average_unit_cost'], 'Second PO receipt recalculates moving weighted average with fixed-decimal rounding.' );
$second_item = $second->get_data()['items'][0];
$assert( '20.000000' === $second_item['actual_unit_cost'] && (int) $second_item['cost_movement_id'] > 0, 'Receipt history preserves actual cost independently from the PO default.' );
$move_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE receipt_item_id = %d', $cost_moves, $second_item['id'] ) );
$replay     = $receive( $second_order, $second_line, '5', '999.000000', $token );
$assert( 200 === $replay->get_status() && 1 === $move_count && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE receipt_item_id = %d', $cost_moves, $second_item['id'] ) ), 'Idempotent replay cannot duplicate inventory or cost movement.' );

$initial = $request(
	'POST',
	"/stockino/v1/valuation/{$legacy->get_id()}/initial-cost",
	array(
		'average_unit_cost' => '4.000000',
		'reason'            => 'Opening inventory count',
	)
);
// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- The calculated baseline reads naturally on the left in this integration assertion.
$assert( 201 === $initial->get_status() && $legacy_before === (float) wc_get_product( $legacy->get_id() )->get_stock_quantity(), 'Initial cost requires explicit action and never mutates stock.' );
$assert(
	409 === $request(
		'POST',
		"/stockino/v1/valuation/{$legacy->get_id()}/initial-cost",
		array(
			'average_unit_cost' => '9',
			'reason'            => 'silent overwrite',
		)
	)->get_status(),
	'Initial cost cannot silently overwrite an established average.'
);
$correction = $request(
	'POST',
	"/stockino/v1/valuation/{$legacy->get_id()}/corrections",
	array(
		'average_unit_cost' => '5.500000',
		'reason'            => 'Supplier invoice reconciliation',
	)
);
$assert( 201 === $correction->get_status() && '5.500000' === $correction->get_data()['average_unit_cost'], 'Manual correction preserves an explicit immutable audit event.' );
$before_external_cost = $wpdb->get_var( $wpdb->prepare( 'SELECT average_unit_cost FROM %i WHERE stock_owner_id = %d', $costs_table, $legacy->get_id() ) );
wc_update_product_stock( wc_get_product( $legacy->get_id() ), 1, 'increase' );
$assert( $before_external_cost === $wpdb->get_var( $wpdb->prepare( 'SELECT average_unit_cost FROM %i WHERE stock_owner_id = %d', $costs_table, $legacy->get_id() ) ), 'Non-purchase stock increase preserves the existing average cost.' );

list( $free_order, $free_line ) = $create_po( $free->get_id(), '1', '0' );
$free_receipt                   = $receive( $free_order, $free_line, '1', '0' );
$assert( 201 === $free_receipt->get_status() && '0.000000' === $wpdb->get_var( $wpdb->prepare( 'SELECT average_unit_cost FROM %i WHERE stock_owner_id = %d', $costs_table, $free->get_id() ) ), 'An explicit zero-cost receipt is accepted as genuinely free stock.' );

list( $negative_order, $negative_line ) = $create_po( $negative->get_id(), '1', '9' );
$negative_receipt                       = $receive( $negative_order, $negative_line, '1', '9' );
$negative_row                           = $request( 'GET', "/stockino/v1/valuation/{$negative->get_id()}" )->get_data();
$assert( 201 === $negative_receipt->get_status() && '9.000000' === $negative_row['average_unit_cost'] && '0.000000' === $negative_row['inventory_value'], 'Negative pre-stock resets average to receipt cost while nonpositive inventory value stays zero.' );

list( $shared_order, $shared_line ) = $create_po( $shared_id, '1', '8' );
list( $self_order, $self_line )     = $create_po( $self_id, '1', '12' );
$assert( 201 === $receive( $shared_order, $shared_line, '1', '8' )->get_status() && 201 === $receive( $self_order, $self_line, '1', '12' )->get_status(), 'Parent-managed and self-managed variations both receive normally.' );
$shared_move = $wpdb->get_row( $wpdb->prepare( 'SELECT stock_owner_id, source_product_id, source_variation_id FROM %i WHERE purchase_order_id = %d', $cost_moves, $shared_order['id'] ), ARRAY_A );
$assert( $parent_id === (int) $shared_move['stock_owner_id'] && $parent_id === (int) $shared_move['source_product_id'] && $shared_id === (int) $shared_move['source_variation_id'], 'Parent-managed variation records the parent as cost owner while preserving source identity.' );
$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE stock_owner_id IN (%d,%d)', $costs_table, $parent_id, $shared_id ) ) && null !== $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE stock_owner_id = %d', $costs_table, $self_id ) ), 'Shared parent has one valuation and a self-managed variation has independent cost state.' );
$variation_rows = $request(
	'GET',
	'/stockino/v1/valuation',
	array(
		'search'   => 'Phase 4 shared owner',
		'per_page' => 20,
	)
)->get_data();
$valuation_ids  = array_map( 'intval', wp_list_pluck( $variation_rows['items'], 'stock_owner_id' ) );
$assert( in_array( $parent_id, $valuation_ids, true ) && in_array( $self_id, $valuation_ids, true ) && ! in_array( $shared_id, $valuation_ids, true ), 'Valuation lists the shared parent exactly once and never double-counts its parent-managed variation.' );

list( $failure_order, $failure_line ) = $create_po( $failure->get_id(), '1', '7' );
$trigger                              = $wpdb->prefix . 'stockino_test_cost_failure';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only identifiers are constructed from the trusted WordPress prefix.
$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only identifiers are constructed from the trusted WordPress prefix.
$wpdb->query( $wpdb->prepare( "CREATE TRIGGER {$trigger} BEFORE INSERT ON {$cost_moves} FOR EACH ROW BEGIN IF NEW.stock_owner_id = %d THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected cost failure'; END IF; END", $failure->get_id() ) );
$failure_before = (float) wc_get_product( $failure->get_id() )->get_stock_quantity();
$failed_cost    = $receive( $failure_order, $failure_line, '1', '7' );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only identifier is constructed from the trusted WordPress prefix.
$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" );
$failed_payload = $failed_cost->get_data();
$failed_receipt = $failed_payload['data']['receipt'] ?? null;
// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- The calculated expected quantity reads naturally on the left in this integration assertion.
$assert( 500 === $failed_cost->get_status() && $failure_before + 1 === (float) wc_get_product( $failure->get_id() )->get_stock_quantity() && 'requires_attention' === $failed_receipt['items'][0]['costing_status'], 'Post-stock cost persistence failure converges to requires_attention without reversing stock.' );
$assert( null === $wpdb->get_var( $wpdb->prepare( 'SELECT average_unit_cost FROM %i WHERE stock_owner_id = %d', $costs_table, $failure->get_id() ) ), 'Failed cost persistence does not partially advance weighted-average state.' );

list( $deleted_order, $deleted_line ) = $create_po( $deleted->get_id(), '1', '6' );
$deleted_receipt                      = $receive( $deleted_order, $deleted_line, '1', '6' );
$deleted_id                           = $deleted->get_id();
wp_delete_post( $deleted_id, true );
$deleted_history = $request( 'GET', "/stockino/v1/valuation/{$deleted_id}/history" )->get_data();
$assert( 1 === $deleted_history['pagination']['total_items'] && 'Phase 4 deleted history' === $deleted_history['items'][0]['source_product_name_snapshot'], 'Cost history snapshots survive product deletion.' );
$assert( 404 === $request( 'GET', "/stockino/v1/valuation/{$deleted_id}" )->get_status(), 'Deleted stock owner no longer appears as active valuation inventory.' );

$uncosted = $request(
	'GET',
	'/stockino/v1/valuation',
	array(
		'cost_status'  => 'uncosted',
		'stock_status' => 'positive',
		'search'       => $failure->get_sku(),
		'per_page'     => 20,
	)
)->get_data();
$assert( 1 === $uncosted['pagination']['total_items'] && 'uncosted' === $uncosted['items'][0]['cost_status'], 'Valuation search and filters expose positive uncosted inventory.' );
$stats = $request( 'GET', '/stockino/v1/valuation/stats' )->get_data();
$assert( $stats['uncosted_positive_stock_owners'] > 0 && true === $stats['is_partial'], 'Valuation aggregate explicitly reports when known value is partial.' );
$history = $request(
	'GET',
	"/stockino/v1/valuation/{$main->get_id()}/history",
	array(
		'page'     => 1,
		'per_page' => 20,
	)
)->get_data();
$assert( 2 === $history['pagination']['total_items'] && '20.000000' === $history['items'][0]['unit_cost'], 'Cost history is server-paginated and retains receipt actual cost.' );

$all_owner_ids = array_merge( $product_ids, array( $parent_id ) );
foreach ( $order_ids as $cleanup_order_id ) {
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE purchase_order_id = %d', $receipts_table, $cleanup_order_id ) );
	foreach ( $ids as $receipt_id ) {
		$wpdb->delete( $receipt_items, array( 'receipt_id' => $receipt_id ) );
	}
	$wpdb->delete( $receipts_table, array( 'purchase_order_id' => $cleanup_order_id ) );
	$wpdb->delete( $items_table, array( 'purchase_order_id' => $cleanup_order_id ) );
	$wpdb->delete( $orders_table, array( 'id' => $cleanup_order_id ) );
}
foreach ( $all_owner_ids as $owner_id ) {
	$wpdb->delete( $cost_moves, array( 'stock_owner_id' => $owner_id ) );
	$wpdb->delete( $costs_table, array( 'stock_owner_id' => $owner_id ) );
}
$wpdb->delete( $relations_table, array( 'supplier_id' => $supplier_id ) );
$wpdb->delete( $suppliers_table, array( 'id' => $supplier_id ) );
foreach ( array( $shared_id, $self_id, $parent_id, $main->get_id(), $legacy->get_id(), $free->get_id(), $negative->get_id(), $failure->get_id() ) as $product_id ) {
	wp_delete_post( $product_id, true );
}

WP_CLI::success( 'Stockino Phase 4 costing and valuation smoke suite passed.' );
