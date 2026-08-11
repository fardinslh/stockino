<?php

// Run with: wp eval-file wp-content/plugins/stockino/tests/Smoke/suppliers.php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This smoke suite must run through WP-CLI.' );
}

$assert  = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
	WP_CLI::log( 'PASS: ' . $message );
};
$request = static function ( string $method, string $route, array $params = array() ) {
	$rest = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$rest->set_query_params( $params );
	} else {
		$rest->set_body_params( $params );
	}
	return rest_do_request( $rest );
};

wp_set_current_user( 0 );
$assert( 401 === $request( 'GET', '/stockino/v1/suppliers' )->get_status(), 'Unauthorized users cannot list suppliers.' );
$assert( 401 === $request( 'POST', '/stockino/v1/suppliers', array( 'name' => 'Denied' ) )->get_status(), 'Unauthorized users cannot create suppliers.' );

wp_set_current_user( 1 );
$fixture_list = $request(
	'GET',
	'/stockino/v1/suppliers',
	array(
		'page'     => 1,
		'per_page' => 20,
	)
)->get_data();
$assert( 20 === count( $fixture_list['items'] ) && $fixture_list['pagination']['total_items'] >= 20, 'Supplier list is server-paginated at 20 rows.' );
$assert( 1 === $request( 'GET', '/stockino/v1/suppliers', array( 'search' => 'تأمین کالای سپاهان' ) )->get_data()['pagination']['total_items'], 'Supplier search matches names.' );
$assert( 1 === $request( 'GET', '/stockino/v1/suppliers', array( 'search' => 'SUP-001' ) )->get_data()['pagination']['total_items'], 'Supplier search matches codes.' );
$assert( 3 === $request( 'GET', '/stockino/v1/suppliers', array( 'status' => 'inactive' ) )->get_data()['pagination']['total_items'], 'Supplier status filtering is accurate.' );
$assert( 400 === $request( 'POST', '/stockino/v1/suppliers', array( 'code' => 'NO-NAME' ) )->get_status(), 'Supplier name is required.' );

$code     = 'QA-' . gmdate( 'His' );
$created  = $request(
	'POST',
	'/stockino/v1/suppliers',
	array(
		'name'           => 'تأمین‌کننده کنترل کیفیت',
		'code'           => strtolower( $code ),
		'contact_name'   => 'مسئول خرید',
		'phone'          => '09120000000',
		'email'          => 'qa-supplier@example.com',
		'website'        => 'https://example.com/supplier',
		'lead_time_days' => 4,
	)
);
$supplier = $created->get_data();
$assert( 201 === $created->get_status() && $code === $supplier['code'], 'Supplier creation validates and normalizes its code.' );
$supplier_id = (int) $supplier['id'];
$duplicate   = $request(
	'POST',
	'/stockino/v1/suppliers',
	array(
		'name' => 'Duplicate',
		'code' => strtolower( $code ),
	)
);
$assert( 409 === $duplicate->get_status() && 'stockino_duplicate_supplier_code' === $duplicate->get_data()['code'], 'Case-insensitive duplicate supplier codes are rejected.' );

$repository  = new Stockino\Database\SupplierRepository();
$race_caught = false;
try {
	$repository->create(
		array(
			'name'   => 'Race duplicate',
			'code'   => $code,
			'status' => 'active',
		)
	);
} catch ( RuntimeException $exception ) {
	$race_caught = 'duplicate_code' === $exception->getMessage();
}
$assert( $race_caught, 'The database unique constraint protects duplicate-code races.' );

$updated = $request(
	'PUT',
	"/stockino/v1/suppliers/{$supplier_id}",
	array(
		'contact_name'   => 'کارشناس جدید',
		'lead_time_days' => 6,
	)
)->get_data();
$assert( 'کارشناس جدید' === $updated['contact_name'] && 6 === $updated['lead_time_days'], 'Supplier details can be updated.' );
$assert( 'inactive' === $request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/archive" )->get_data()['status'], 'Supplier archiving sets inactive status.' );
$assert( 'active' === $request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/reactivate" )->get_data()['status'], 'Supplier reactivation restores active status.' );

$simple_id    = wc_get_product_id_by_sku( 'STK-049' );
$variation_id = wc_get_product_id_by_sku( 'STK-110-S' );
$assert( $simple_id > 0 && $variation_id > 0, 'Simple and variation fixtures are available for linking.' );
global $wpdb;
$movement_table   = $wpdb->prefix . 'stockino_stock_movements';
$supplier_table   = $wpdb->prefix . 'stockino_suppliers';
$relation_table   = $wpdb->prefix . 'stockino_supplier_products';
$stock_before     = (float) wc_get_product( $simple_id )->get_stock_quantity();
$movements_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id = %d', $movement_table, $simple_id ) );

$invalid_supplier = $request( 'POST', '/stockino/v1/suppliers/999999/products', array( 'product_id' => $simple_id ) );
$assert( 404 === $invalid_supplier->get_status(), 'Product linking rejects an invalid supplier.' );
$invalid_product = $request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/products", array( 'product_id' => 999999 ) );
$assert( 404 === $invalid_product->get_status(), 'Product linking rejects an invalid product.' );

$simple_link     = $request(
	'POST',
	"/stockino/v1/suppliers/{$supplier_id}/products",
	array(
		'product_id'             => $simple_id,
		'supplier_sku'           => 'QA-SIMPLE-49',
		'minimum_order_quantity' => '5.5',
		'order_multiple'         => '2.5',
	)
);
$simple_relation = $simple_link->get_data();
$assert( 201 === $simple_link->get_status() && 'QA-SIMPLE-49' === $simple_relation['supplier_sku'], 'A simple product link stores supplier SKU metadata.' );
$assert( 6 === $simple_relation['effective_lead_time_days'], 'A relationship inherits the supplier default lead time.' );
$assert( '5.500000' === $simple_relation['minimum_order_quantity'], 'Decimal minimum order quantity is stored precisely.' );

$variation_link = $request(
	'POST',
	"/stockino/v1/suppliers/{$supplier_id}/products",
	array(
		'product_id'     => $variation_id,
		'supplier_sku'   => 'QA-VAR-S',
		'lead_time_days' => 2,
	)
);
$assert( 201 === $variation_link->get_status() && $variation_id === $variation_link->get_data()['product_id'], 'A variation is linked without collapsing to its parent.' );
$duplicate_link = $request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/products", array( 'product_id' => $simple_id ) );
$assert( 409 === $duplicate_link->get_status(), 'Duplicate supplier-product relationships are rejected.' );

$relation_update = $request(
	'PUT',
	"/stockino/v1/suppliers/{$supplier_id}/products/{$simple_id}",
	array(
		'lead_time_days'         => 3,
		'minimum_order_quantity' => '7.25',
		'order_multiple'         => '1.25',
		'notes'                  => 'Updated QA metadata',
	)
)->get_data();
$assert( 3 === $relation_update['effective_lead_time_days'] && '7.250000' === $relation_update['minimum_order_quantity'], 'Relationship metadata and lead-time override update correctly.' );
$assert( 400 === $request( 'PUT', "/stockino/v1/suppliers/{$supplier_id}/products/{$simple_id}", array( 'minimum_order_quantity' => 0 ) )->get_status(), 'Zero MOQ is rejected.' );
$assert( 400 === $request( 'PUT', "/stockino/v1/suppliers/{$supplier_id}/products/{$simple_id}", array( 'minimum_order_quantity' => -1 ) )->get_status(), 'Negative MOQ is rejected.' );
$assert( 400 === $request( 'PUT', "/stockino/v1/suppliers/{$supplier_id}/products/{$simple_id}", array( 'order_multiple' => -1 ) )->get_status(), 'Negative order multiple is rejected.' );
$assert( 400 === $request( 'PUT', "/stockino/v1/suppliers/{$supplier_id}/products/{$simple_id}", array( 'minimum_order_quantity' => '0.0000001' ) )->get_status(), 'An MOQ that normalizes to zero is rejected.' );
$assert( 400 === $request( 'PUT', "/stockino/v1/suppliers/{$supplier_id}/products/{$simple_id}", array( 'order_multiple' => '0.0000001' ) )->get_status(), 'An order multiple that normalizes to zero is rejected.' );
$minimum_quantity = $request( 'PUT', "/stockino/v1/suppliers/{$supplier_id}/products/{$simple_id}", array( 'minimum_order_quantity' => '0.000001' ) )->get_data();
$assert( '0.000001' === $minimum_quantity['minimum_order_quantity'], 'The smallest representable positive purchasing quantity is accepted.' );
$maximum_quantity = $request( 'PUT', "/stockino/v1/suppliers/{$supplier_id}/products/{$simple_id}", array( 'order_multiple' => '99999999999999.999999' ) )->get_data();
$assert( '99999999999999.999999' === $maximum_quantity['order_multiple'], 'The largest DECIMAL(20,6) purchasing quantity is accepted.' );
$assert( 400 === $request( 'PUT', "/stockino/v1/suppliers/{$supplier_id}/products/{$simple_id}", array( 'order_multiple' => '100000000000000' ) )->get_status(), 'A purchasing quantity beyond DECIMAL(20,6) is rejected before persistence.' );

$supplier_products = $request(
	'GET',
	"/stockino/v1/suppliers/{$supplier_id}/products",
	array(
		'page'     => 1,
		'per_page' => 20,
	)
)->get_data();
$assert( 2 === $supplier_products['pagination']['total_items'] && 2 === count( $supplier_products['items'] ), 'Supplier products are paginated and hydrated in a bounded page.' );
$assert( 2 === count( array_filter( array_column( $supplier_products['items'], 'product' ) ) ), 'Bounded relationship hydration includes simple and variation product details.' );
$assert( 1 === $request( 'GET', "/stockino/v1/suppliers/{$supplier_id}/products", array( 'search' => 'STK-049' ) )->get_data()['pagination']['total_items'], 'Supplier-product search matches the WooCommerce SKU.' );
$assert( 2 === $request( 'GET', "/stockino/v1/suppliers/{$supplier_id}" )->get_data()['linked_product_count'], 'Supplier detail returns an aggregated linked-product count.' );
$product_suppliers = $request( 'GET', "/stockino/v1/products/{$simple_id}/suppliers" )->get_data();
$assert( in_array( $supplier_id, array_column( $product_suppliers['items'], 'supplier_id' ), true ), 'Product-to-suppliers endpoint returns the relationship.' );
$picker = $request(
	'GET',
	'/stockino/v1/products/search',
	array(
		'search'   => 'STK-110-S',
		'per_page' => 20,
	)
)->get_data();
$assert( in_array( $variation_id, array_column( $picker['items'], 'id' ), true ) && count( $picker['items'] ) <= 20, 'Product picker search is server-side and bounded.' );
$numeric_picker = $request( 'GET', '/stockino/v1/products/search', array( 'search' => '7' ) );
$assert( 200 === $numeric_picker->get_status() && count( $numeric_picker->get_data()['items'] ) <= 20, 'Product picker search accepts a one-character numeric query.' );

$supplier_queries = 0;
$query_counter    = static function ( string $sql ) use ( &$supplier_queries, $supplier_table ): string {
	if ( str_contains( $sql, $supplier_table ) ) {
		++$supplier_queries;
	}
	return $sql;
};
add_filter( 'query', $query_counter );
$request(
	'GET',
	'/stockino/v1/suppliers',
	array(
		'page'     => 1,
		'per_page' => 20,
	)
);
remove_filter( 'query', $query_counter );
$assert( $supplier_queries <= 2, 'Supplier list uses aggregate counts without N+1 queries.' );

$assert( 200 === $request( 'DELETE', "/stockino/v1/suppliers/{$supplier_id}/products/{$simple_id}" )->get_status(), 'A supplier-product relationship can be unlinked.' );
$assert( 200 === $request( 'DELETE', "/stockino/v1/suppliers/{$supplier_id}/products/{$variation_id}" )->get_status(), 'A variation relationship can be unlinked.' );
$stock_after     = (float) wc_get_product( $simple_id )->get_stock_quantity();
$movements_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id = %d', $movement_table, $simple_id ) );
$assert( $stock_before === $stock_after && $movements_before === $movements_after, 'Supplier CRUD and relationship operations do not change stock or movements.' );

$orphan_id       = 999999999;
$stats_before    = $request( 'GET', '/stockino/v1/suppliers/stats' )->get_data();
$detail_before   = $request( 'GET', "/stockino/v1/suppliers/{$supplier_id}" )->get_data();
$products_before = $request( 'GET', "/stockino/v1/suppliers/{$supplier_id}/products" )->get_data();
$now             = current_time( 'mysql', true );
$wpdb->insert(
	$relation_table,
	array(
		'supplier_id' => $supplier_id,
		'product_id'  => $orphan_id,
		'created_at'  => $now,
		'updated_at'  => $now,
	)
);
$stats_with_orphan    = $request( 'GET', '/stockino/v1/suppliers/stats' )->get_data();
$detail_with_orphan   = $request( 'GET', "/stockino/v1/suppliers/{$supplier_id}" )->get_data();
$products_with_orphan = $request( 'GET', "/stockino/v1/suppliers/{$supplier_id}/products" )->get_data();
$without_products     = $request( 'GET', '/stockino/v1/suppliers', array( 'has_products' => 'false', 'search' => $code ) )->get_data();
$assert( $stats_before === $stats_with_orphan, 'Supplier stats exclude orphan relationships.' );
$assert( $detail_before['linked_product_count'] === $detail_with_orphan['linked_product_count'], 'Supplier detail counts exclude orphan relationships.' );
$assert( $products_before['pagination']['total_items'] === $products_with_orphan['pagination']['total_items'], 'Supplier product pagination totals exclude orphan relationships.' );
$assert( 1 === $without_products['pagination']['total_items'], 'The has-products filter ignores orphan relationships.' );
$wpdb->delete( $relation_table, array( 'supplier_id' => $supplier_id, 'product_id' => $orphan_id ) );

$temporary = new WC_Product_Simple();
$temporary->set_name( 'Stockino deletion QA' );
$temporary->set_status( 'publish' );
$temporary->set_manage_stock( true );
$temporary->set_stock_quantity( 0 );
$temporary->set_stock_status( 'outofstock' );
$temporary_id = $temporary->save();
$assert( 201 === $request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/products", array( 'product_id' => $temporary_id ) )->get_status(), 'An out-of-stock product can retain supplier metadata.' );
wp_trash_post( $temporary_id );
$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE supplier_id = %d AND product_id = %d', $relation_table, $supplier_id, $temporary_id ) ), 'Trashing a product preserves its supplier relationship.' );
wp_untrash_post( $temporary_id );
wp_update_post( array( 'ID' => $temporary_id, 'post_status' => 'draft' ) );
$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE supplier_id = %d AND product_id = %d', $relation_table, $supplier_id, $temporary_id ) ), 'Drafting a product preserves its supplier relationship.' );
wp_update_post( array( 'ID' => $temporary_id, 'post_status' => 'private' ) );
wp_delete_post( $temporary_id, true );
$assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id = %d', $relation_table, $temporary_id ) ), 'Permanently deleting a simple product removes only its supplier relationships.' );

$parent = new WC_Product_Variable();
$parent->set_name( 'Stockino variable deletion QA' );
$parent_id     = $parent->save();
$variation_ids = array();
foreach ( array( 'A', 'B' ) as $label ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent_id );
	$variation->set_name( "Stockino variation {$label}" );
	$variation_ids[] = $variation->save();
}
$request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/products", array( 'product_id' => $parent_id ) );
foreach ( $variation_ids as $child_id ) {
	$request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/products", array( 'product_id' => $child_id ) );
}
wp_delete_post( $variation_ids[0], true );
$remaining_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM %i WHERE supplier_id = %d', $relation_table, $supplier_id ) ) );
$assert( ! in_array( $variation_ids[0], $remaining_ids, true ) && in_array( $parent_id, $remaining_ids, true ) && in_array( $variation_ids[1], $remaining_ids, true ), 'Deleting one variation removes its exact relationship without affecting its parent or sibling.' );
wp_delete_post( $parent_id, true );
$remaining_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM %i WHERE supplier_id = %d', $relation_table, $supplier_id ) ) );
$assert( ! in_array( $parent_id, $remaining_ids, true ) && ! in_array( $variation_ids[1], $remaining_ids, true ), 'Deleting a variable parent removes its exact and child-variation relationships.' );

$movement_rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $movement_table ) );
update_option( 'stockino_db_version', '1.0.0' );
Stockino\Database\Installer::activate();
$assert( '4.0.0' === get_option( 'stockino_db_version' ) && $movement_rows === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $movement_table ) ), 'The cumulative migration preserves movement data and advances to the Phase 4 schema version.' );

$wpdb->delete( $relation_table, array( 'supplier_id' => $supplier_id ) );
$wpdb->delete( $supplier_table, array( 'id' => $supplier_id ) );
WP_CLI::success( 'Stockino supplier smoke suite passed.' );
