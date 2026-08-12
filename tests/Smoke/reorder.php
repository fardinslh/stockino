<?php

// Run with: wp eval-file wp-content/plugins/stockino/tests/Smoke/reorder.php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This smoke suite must run through WP-CLI.' );
}

$assert       = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
	WP_CLI::log( 'PASS: ' . $message );
};
$request      = static function ( string $method, string $route, array $params = array() ) {
	$rest = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$rest->set_query_params( $params );
	} else {
		$rest->set_body_params( $params );
	}
	return rest_do_request( $rest );
};
$new_product  = static function ( string $name, string $sku, float $stock, string $low = '10' ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_sku( $sku );
	$product->set_status( 'publish' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $stock );
	$product->set_low_stock_amount( $low );
	$product->save();
	return $product;
};
$find         = static function ( array $items, int $owner_id ): ?array {
	foreach ( $items as $item ) {
		if ( $owner_id === (int) $item['stock_owner_id'] ) {
			return $item;
		}
	}
	return null;
};
$create_order = static function ( int $supplier_id, int $product_id, string $quantity, string $cost = '4.000000' ) use ( $request ): array {
	$order_response = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) );
	$order          = $order_response->get_data();
	if ( 201 !== $order_response->get_status() ) {
		throw new RuntimeException( 'Could not create reorder smoke PO.' );
	}
	$item_response = $request(
		'POST',
		'/stockino/v1/purchase-orders/' . $order['id'] . '/items',
		array(
			'product_id'        => $product_id,
			'ordered_quantity'  => $quantity,
			'ordered_unit_cost' => $cost,
		)
	);
	if ( 201 !== $item_response->get_status() ) {
		throw new RuntimeException( 'Could not create reorder smoke PO line.' );
	}
	return array( $order, $item_response->get_data() );
};

wp_set_current_user( 0 );
$assert( 401 === $request( 'GET', '/stockino/v1/reorder' )->get_status(), 'Unauthorized users cannot read reorder recommendations.' );
$assert( 401 === $request( 'PATCH', '/stockino/v1/reorder/1/settings', array( 'custom_reorder_point' => '1' ) )->get_status(), 'Unauthorized users cannot change reorder settings.' );
$assert( 401 === $request( 'POST', '/stockino/v1/reorder/create-purchase-orders', array( 'stock_owner_ids' => array( 1 ) ) )->get_status(), 'Unauthorized users cannot generate purchase orders.' );

wp_set_current_user( 1 );
global $wpdb;
$suffix          = strtolower( wp_generate_password( 8, false, false ) );
$orders_table    = $wpdb->prefix . 'stockino_purchase_orders';
$items_table     = $wpdb->prefix . 'stockino_purchase_order_items';
$receipts_table  = $wpdb->prefix . 'stockino_purchase_receipts';
$receipt_items   = $wpdb->prefix . 'stockino_purchase_receipt_items';
$relations_table = $wpdb->prefix . 'stockino_supplier_products';
$suppliers_table = $wpdb->prefix . 'stockino_suppliers';
$settings_table  = $wpdb->prefix . 'stockino_reorder_settings';

$main      = $new_product( 'Phase 5 reorder main', "R5-{$suffix}-MAIN", 3 );
$covered   = $new_product( 'Phase 5 incoming coverage', "R5-{$suffix}-COVER", 2 );
$grouped   = $new_product( 'Phase 5 grouped draft', "R5-{$suffix}-GROUP", 1 );
$no_supply = $new_product( 'Phase 5 no supplier', "R5-{$suffix}-NONE", 0 );

$supplier_a_response = $request(
	'POST',
	'/stockino/v1/suppliers',
	array(
		'name'           => 'Phase 5 Supplier A',
		'code'           => 'R5A-' . $suffix,
		'lead_time_days' => 7,
	)
);
$supplier_b_response = $request(
	'POST',
	'/stockino/v1/suppliers',
	array(
		'name'           => 'Phase 5 Supplier B',
		'code'           => 'R5B-' . $suffix,
		'lead_time_days' => 3,
	)
);
$supplier_a          = $supplier_a_response->get_data();
$supplier_b          = $supplier_b_response->get_data();
$assert( 201 === $supplier_a_response->get_status() && 201 === $supplier_b_response->get_status(), 'Active supplier fixtures are available for deterministic selection.' );

$link = static function ( int $supplier_id, int $product_id, ?string $minimum = null, ?string $multiple = null, ?int $lead = null ) use ( $request, $assert ): void {
	$response = $request(
		'POST',
		"/stockino/v1/suppliers/{$supplier_id}/products",
		array(
			'product_id'             => $product_id,
			'minimum_order_quantity' => $minimum,
			'order_multiple'         => $multiple,
			'lead_time_days'         => $lead,
		)
	);
	$assert( 201 === $response->get_status(), "Supplier relationship {$supplier_id}/{$product_id} created." );
};
$link( (int) $supplier_a['id'], $main->get_id(), '10', '6' );
$link( (int) $supplier_b['id'], $main->get_id(), '10', '6', 2 );
$link( (int) $supplier_a['id'], $covered->get_id() );
$link( (int) $supplier_a['id'], $grouped->get_id() );

$main_response = $request( 'GET', '/stockino/v1/reorder/' . $main->get_id() );
$main_row      = $main_response->get_data();
$assert( 200 === $main_response->get_status() && 'reorder_needed' === $main_row['state'], 'Low stock with no incoming produces a reorder recommendation.' );
$assert( '18.000000' === $main_row['recommended_quantity'], 'MOQ and order-multiple rounding produce the exact deterministic quantity.' );
$assert( (int) $supplier_b['id'] === (int) $main_row['supplier']['supplier_id'] && 2 === $main_row['supplier']['effective_lead_time_days'], 'Relationship lead time wins and deterministically selects the fastest active supplier.' );

list( $draft, $draft_item ) = $create_order( (int) $supplier_a['id'], $covered->get_id(), '20' );
$covered_row                = $request( 'GET', '/stockino/v1/reorder/' . $covered->get_id() )->get_data();
$assert( '0.000000' === $covered_row['confirmed_incoming'] && 'reorder_needed' === $covered_row['state'], 'Draft purchase orders do not count as incoming supply.' );
$ordered = $request( 'POST', '/stockino/v1/purchase-orders/' . $draft['id'] . '/mark-ordered' );
$assert( 200 === $ordered->get_status(), 'The incoming fixture PO can be marked ordered.' );
$covered_row = $request( 'GET', '/stockino/v1/reorder/' . $covered->get_id() )->get_data();
$assert( '20.000000' === $covered_row['confirmed_incoming'] && 'covered_by_incoming' === $covered_row['state'], 'Ordered PO supply covers low stock without another reorder.' );
$incoming = $request(
	'GET',
	'/stockino/v1/reorder/' . $covered->get_id() . '/incoming',
	array(
		'page'     => 1,
		'per_page' => 20,
	)
)->get_data();
$assert( 1 === count( $incoming['items'] ) && '20.000000' === $incoming['items'][0]['remaining_quantity'], 'Open incoming PO detail is server-paginated with exact remaining quantity.' );
$assert( 200 === $request( 'POST', '/stockino/v1/purchase-orders/' . $draft['id'] . '/cancel' )->get_status(), 'Cancelling an ordered PO succeeds.' );
$assert( '0.000000' === $request( 'GET', '/stockino/v1/reorder/' . $covered->get_id() )->get_data()['confirmed_incoming'], 'Cancelled POs are excluded from incoming supply.' );

$initial_cost = $request(
	'POST',
	'/stockino/v1/valuation/' . $covered->get_id() . '/initial-cost',
	array(
		'average_unit_cost' => '4',
		'reason'            => 'Phase 5 partial receipt fixture',
	)
);
$assert( 201 === $initial_cost->get_status(), 'Existing positive stock receives an explicit initial cost before receipt testing.' );

list( $partial_order, $partial_item ) = $create_order( (int) $supplier_a['id'], $covered->get_id(), '10' );
$request( 'POST', '/stockino/v1/purchase-orders/' . $partial_order['id'] . '/mark-ordered' );
$partial_receipt = $request(
	'POST',
	'/stockino/v1/purchase-orders/' . $partial_order['id'] . '/receipts',
	array(
		'idempotency_key' => wp_generate_uuid4(),
		'items'           => array(
			array(
				'item_id'          => $partial_item['id'],
				'quantity'         => '4',
				'actual_unit_cost' => '4',
			),
		),
	)
);
$assert( 201 === $partial_receipt->get_status(), 'A partial receipt fixture is confirmed.' );
$partial_row = $request( 'GET', '/stockino/v1/reorder/' . $covered->get_id() )->get_data();
$assert( '6.000000' === $partial_row['confirmed_incoming'] && '12.000000' === $partial_row['inventory_position'], 'Partial receiving counts only the confirmed remaining incoming quantity.' );
$complete_receipt = $request(
	'POST',
	'/stockino/v1/purchase-orders/' . $partial_order['id'] . '/receipts',
	array(
		'idempotency_key' => wp_generate_uuid4(),
		'items'           => array(
			array(
				'item_id'          => $partial_item['id'],
				'quantity'         => '6',
				'actual_unit_cost' => '4',
			),
		),
	)
);
$assert( 201 === $complete_receipt->get_status(), 'The remaining incoming fixture can be fully received.' );
$assert( '0.000000' === $request( 'GET', '/stockino/v1/reorder/' . $covered->get_id() )->get_data()['confirmed_incoming'], 'Received POs contribute zero incoming quantity.' );

$settings_response = $request(
	'PATCH',
	'/stockino/v1/reorder/' . $main->get_id() . '/settings',
	array(
		'custom_reorder_point'  => '12.500000',
		'custom_target_stock'   => '30.000000',
		'preferred_supplier_id' => $supplier_a['id'],
		'preferred_product_id'  => $main->get_id(),
	)
);
$settings          = $settings_response->get_data();
$assert( 200 === $settings_response->get_status() && '12.500000' === $settings['custom_reorder_point'], 'Custom reorder point and target persist as fixed decimals.' );
$assert( 400 === $request( 'PATCH', '/stockino/v1/reorder/' . $main->get_id() . '/settings', array( 'custom_target_stock' => '1' ) )->get_status(), 'A custom target below the effective reorder point is rejected.' );
$main_row = $request( 'GET', '/stockino/v1/reorder/' . $main->get_id() )->get_data();
$assert( '30.000000' === $main_row['target_stock'] && 'preferred' === $main_row['supplier_selection_method'], 'Explicit preferred supplier and custom target control the recommendation.' );

$no_supplier_row = $request( 'GET', '/stockino/v1/reorder/' . $no_supply->get_id() )->get_data();
$assert( 'no_supplier' === $no_supplier_row['state'], 'Low stock without an active relationship exposes no_supplier.' );

$parent = new WC_Product_Variable();
$parent->set_name( 'Phase 5 shared stock owner' );
$parent->set_status( 'publish' );
$parent->set_manage_stock( true );
$parent->set_stock_quantity( 1 );
$parent->set_low_stock_amount( 5 );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Supply source' );
$attribute->set_options( array( 'A', 'B', 'Self' ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$parent->set_attributes( array( $attribute ) );
$parent_id   = $parent->save();
$variation_a = new WC_Product_Variation();
$variation_a->set_parent_id( $parent_id );
$variation_a->set_status( 'publish' );
$variation_a->set_attributes( array( 'supply-source' => 'A' ) );
$variation_a->set_manage_stock( false );
$variation_a->set_sku( "R5-{$suffix}-SHARED-A" );
$variation_a_id = $variation_a->save();
$variation_b    = new WC_Product_Variation();
$variation_b->set_parent_id( $parent_id );
$variation_b->set_status( 'publish' );
$variation_b->set_attributes( array( 'supply-source' => 'B' ) );
$variation_b->set_manage_stock( false );
$variation_b->set_sku( "R5-{$suffix}-SHARED-B" );
$variation_b_id = $variation_b->save();
$self           = new WC_Product_Variation();
$self->set_parent_id( $parent_id );
$self->set_status( 'publish' );
$self->set_attributes( array( 'supply-source' => 'Self' ) );
$self->set_manage_stock( true );
$self->set_stock_quantity( 1 );
$self->set_sku( "R5-{$suffix}-SELF" );
$self_id = $self->save();
$link( (int) $supplier_a['id'], $variation_a_id );
$link( (int) $supplier_b['id'], $variation_b_id );
$link( (int) $supplier_a['id'], $self_id );
$shared_row = $request( 'GET', '/stockino/v1/reorder/' . $parent_id )->get_data();
$assert( 'supplier_selection_required' === $shared_row['state'], 'Competing child relationships on one parent stock owner require explicit source selection.' );
$search_rows = $request(
	'GET',
	'/stockino/v1/reorder',
	array(
		'search'   => 'Phase 5 shared stock owner',
		'page'     => 1,
		'per_page' => 20,
	)
)->get_data();
$assert( 1 === count( array_filter( $search_rows['items'], static fn( array $row ): bool => $parent_id === (int) $row['stock_owner_id'] ) ), 'Shared parent stock produces exactly one owner-centric recommendation.' );
$preferred = $request(
	'PATCH',
	'/stockino/v1/reorder/' . $parent_id . '/settings',
	array(
		'preferred_supplier_id' => $supplier_b['id'],
		'preferred_product_id'  => $variation_b_id,
	)
);
$assert( 200 === $preferred->get_status() && (int) $supplier_b['id'] === (int) $request( 'GET', '/stockino/v1/reorder/' . $parent_id )->get_data()['supplier']['supplier_id'], 'A valid preferred child relationship safely resolves shared-owner allocation.' );
$self_row = $request( 'GET', '/stockino/v1/reorder/' . $self_id )->get_data();
$assert( $self_id === (int) $self_row['stock_owner_id'] && '5.000000' === $self_row['effective_reorder_point'], 'A self-managed variation retains an independent recommendation and inherits its parent low-stock threshold.' );
$assert( 200 === $request( 'POST', '/stockino/v1/suppliers/' . $supplier_b['id'] . '/archive' )->get_status(), 'The preferred supplier can be archived for fallback testing.' );
$invalid_preferred = $request( 'GET', '/stockino/v1/reorder/' . $parent_id )->get_data();
$assert( true === $invalid_preferred['preferred_supplier_invalid'] && (int) $supplier_a['id'] === (int) $invalid_preferred['supplier']['supplier_id'], 'An inactive preferred supplier is not used and safely falls back when only one source identity remains.' );
$assert( 200 === $request( 'POST', '/stockino/v1/suppliers/' . $supplier_b['id'] . '/reactivate' )->get_status(), 'The archived supplier can be restored after fallback testing.' );

list( $attention_order, $attention_order_item ) = $create_order( (int) $supplier_a['id'], $main->get_id(), '10' );
$assert( 200 === $request( 'POST', '/stockino/v1/purchase-orders/' . $attention_order['id'] . '/mark-ordered' )->get_status(), 'The attention fixture PO can be marked ordered.' );
$wpdb->insert(
	$receipt_items,
	array(
		'receipt_id'             => 999999999,
		'purchase_order_item_id' => $attention_order_item['id'],
		'product_id'             => $main->get_id(),
		'stock_owner_id'         => $main->get_id(),
		'quantity_received'      => '2.000000',
		'status'                 => 'requires_attention',
		'costing_status'         => 'requires_attention',
		'created_at'             => current_time( 'mysql', true ),
		'updated_at'             => current_time( 'mysql', true ),
	)
);
$attention_item_id = (int) $wpdb->insert_id;
$attention_row     = $request( 'GET', '/stockino/v1/reorder/' . $main->get_id() )->get_data();
$assert( 'attention_required' === $attention_row['state'] && null === $attention_row['recommended_quantity'] && '8.000000' === $attention_row['confirmed_incoming'] && '2.000000' === $attention_row['attention_incoming'], 'Unresolved receiving/costing attention is excluded from confirmed incoming and blocks an unsafe replenishment quantity.' );
$wpdb->delete( $receipt_items, array( 'id' => $attention_item_id ) );
$assert( 200 === $request( 'POST', '/stockino/v1/purchase-orders/' . $attention_order['id'] . '/cancel' )->get_status(), 'The attention fixture PO can be cancelled after reconciliation testing.' );

$bulk      = $request( 'POST', '/stockino/v1/reorder/create-purchase-orders', array( 'stock_owner_ids' => array( $main->get_id(), $grouped->get_id(), $no_supply->get_id() ) ) );
$bulk_data = $bulk->get_data();
$assert( 201 === $bulk->get_status() && 1 === count( $bulk_data['created'] ) && 1 === count( $bulk_data['skipped'] ), 'Bulk creation groups ready rows and reports unresolved rows as a partial result.' );
$created_order = $bulk_data['created'][0]['purchase_order'];
$assert( 2 === count( $created_order['items'] ), 'The grouped draft contains every revalidated recommendation line.' );
$assert( $main->get_id() === (int) $created_order['items'][0]['reorder_stock_owner_id'] || $main->get_id() === (int) $created_order['items'][1]['reorder_stock_owner_id'], 'Generated lines preserve reorder owner provenance.' );
$duplicate = $request( 'POST', '/stockino/v1/reorder/create-purchase-orders', array( 'stock_owner_ids' => array( $main->get_id() ) ) )->get_data();
$assert( 'already_replenishing' === $duplicate['skipped'][0]['reason'], 'A second request cannot duplicate replenishment while the generated draft remains active.' );
$stale = $request( 'POST', '/stockino/v1/reorder/create-purchase-orders', array( 'stock_owner_ids' => array( $covered->get_id() ) ) )->get_data();
$assert( 'stale_or_unresolved' === $stale['skipped'][0]['reason'], 'Server-side revalidation skips a recommendation that is no longer reorder-needed.' );

$filtered = $request(
	'GET',
	'/stockino/v1/reorder',
	array(
		'state'    => 'no_supplier',
		'search'   => $suffix,
		'page'     => 1,
		'per_page' => 20,
		'sort'     => 'urgency',
	)
)->get_data();
$assert( null !== $find( $filtered['items'], $no_supply->get_id() ), 'Reorder search, state filtering, sorting, and server pagination agree.' );
$stats = $request( 'GET', '/stockino/v1/reorder/stats' )->get_data();
$assert( isset( $stats['reorder_needed'], $stats['critical'], $stats['covered_by_incoming'], $stats['no_supplier'], $stats['attention_required'] ), 'Reorder dashboard statistics expose all required deterministic states.' );

$fixture_ids = array( $main->get_id(), $covered->get_id(), $grouped->get_id(), $no_supply->get_id(), $parent_id, $variation_a_id, $variation_b_id, $self_id );
$order_ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT purchase_order_id FROM %i WHERE product_id IN (' . implode( ',', array_fill( 0, count( $fixture_ids ), '%d' ) ) . ')', array_merge( array( $items_table ), $fixture_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
if ( array() !== $order_ids ) {
	$receipt_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE purchase_order_id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')', array_merge( array( $receipts_table ), $order_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( array() !== $receipt_ids ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE receipt_id IN (' . implode( ',', array_fill( 0, count( $receipt_ids ), '%d' ) ) . ')', array_merge( array( $receipt_items ), $receipt_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE purchase_order_id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')', array_merge( array( $receipts_table ), $order_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE purchase_order_id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')', array_merge( array( $items_table ), $order_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')', array_merge( array( $orders_table ), $order_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE stock_owner_id IN (' . implode( ',', array_fill( 0, count( $fixture_ids ), '%d' ) ) . ')', array_merge( array( $settings_table ), $fixture_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->delete( $relations_table, array( 'supplier_id' => $supplier_a['id'] ) );
$wpdb->delete( $relations_table, array( 'supplier_id' => $supplier_b['id'] ) );
$wpdb->delete( $suppliers_table, array( 'id' => $supplier_a['id'] ) );
$wpdb->delete( $suppliers_table, array( 'id' => $supplier_b['id'] ) );
foreach ( array_reverse( $fixture_ids ) as $product_id ) {
	wp_delete_post( $product_id, true );
}

WP_CLI::success( 'Stockino Phase 5 reorder recommendation smoke suite passed.' );
