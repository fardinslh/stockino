<?php

// Run with: wp eval-file wp-content/plugins/stockino/tests/Smoke/inventory.php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This smoke suite must run through WP-CLI.' );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
	WP_CLI::log( 'PASS: ' . $message );
};

$request = static function ( string $method, string $route, array $params = array() ) {
	$rest_request = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$rest_request->set_query_params( $params );
	} else {
		$rest_request->set_body_params( $params );
	}
	return rest_do_request( $rest_request );
};

wp_set_current_user( 0 );
$unauthorized = $request( 'GET', '/stockino/v1/inventory' );
$assert( 401 === $unauthorized->get_status(), 'Inventory rejects an unauthenticated request.' );

wp_set_current_user( 1 );
$product_id   = wc_get_product_id_by_sku( 'STK-049' );
$variation_id = wc_get_product_id_by_sku( 'STK-110-S' );
$large_id     = wc_get_product_id_by_sku( 'STK-110-L' );
$inherited_id = wc_get_product_id_by_sku( 'STK-110-P' );
$global_id    = wc_get_product_id_by_sku( 'STK-111' );
$assert( $product_id > 0 && $variation_id > 0 && $large_id > 0 && $inherited_id > 0 && $global_id > 0, 'Development fixtures are available.' );

$name_search = $request( 'GET', '/stockino/v1/inventory', array( 'search' => 'Stockino Fixture 049' ) )->get_data();
$sku_search  = $request( 'GET', '/stockino/v1/inventory', array( 'search' => 'STK-049' ) )->get_data();
$page_two    = $request(
	'GET',
	'/stockino/v1/inventory',
	array(
		'page'     => 2,
		'per_page' => 20,
	)
)->get_data();
$assert( 1 === $name_search['total_items'], 'Product-name search works server-side.' );
$assert( 1 === $sku_search['total_items'], 'Exact SKU search works server-side.' );
$assert( 2 === $page_two['current_page'] && count( $page_two['items'] ) <= 20, 'Pagination stays server-side.' );

$category_variations = $request(
	'GET',
	'/stockino/v1/inventory',
	array(
		'category' => 'stockino-electronics',
		'type'     => 'variation',
		'search'   => 'Stockino Variable 110',
		'per_page' => 100,
	)
)->get_data();
$category_ids        = array_column( $category_variations['items'], 'id' );
$assert( in_array( $variation_id, $category_ids, true ) && in_array( $large_id, $category_ids, true ), 'Category filtering includes variations through their parent.' );
$low_variations = $request(
	'GET',
	'/stockino/v1/inventory',
	array(
		'category'  => 'stockino-electronics',
		'type'      => 'variation',
		'search'    => 'Stockino Variable 110',
		'low_stock' => true,
		'per_page'  => 100,
	)
)->get_data();
$low_ids        = array_column( $low_variations['items'], 'id' );
$assert( in_array( $variation_id, $low_ids, true ) && ! in_array( $large_id, $low_ids, true ), 'Variation low-stock filtering honors inherited and own thresholds.' );
$global_low = $request(
	'GET',
	'/stockino/v1/inventory',
	array(
		'search'    => 'STK-111',
		'low_stock' => true,
	)
)->get_data();
$assert( in_array( $global_id, array_column( $global_low['items'], 'id' ), true ), 'Low-stock filtering honors the global fallback threshold.' );
$unmanaged = $request(
	'GET',
	'/stockino/v1/inventory',
	array(
		'manage_stock' => false,
		'search'       => 'STK-110-P',
	)
)->get_data();
$assert( in_array( $inherited_id, array_column( $unmanaged['items'], 'id' ), true ), 'Manage-stock filtering returns the parent-managed variation.' );
$all_low = $request(
	'GET',
	'/stockino/v1/inventory',
	array(
		'low_stock' => true,
		'per_page'  => 20,
	)
)->get_data();
$stats   = $request( 'GET', '/stockino/v1/inventory/stats' )->get_data();
$assert( $all_low['total_items'] === $stats['low_stock'], 'Low-stock list and summary statistics use the same definition.' );

global $wpdb;
$table        = $wpdb->prefix . 'stockino_stock_movements';
$count_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id = %d AND variation_id IS NULL', $table, $product_id ) );
$product      = wc_get_product( $product_id );
$original     = (float) $product->get_stock_quantity();

$plus = $request(
	'POST',
	"/stockino/v1/products/{$product_id}/adjust-stock",
	array(
		'mode'     => 'delta',
		'quantity' => 5,
		'reason'   => 'manual_adjustment',
		'note'     => 'Automated smoke +5',
	)
);
$assert( 200 === $plus->get_status() && $original + 5 === $plus->get_data()['quantity_after'], 'Delta +5 updates WooCommerce stock.' );
$count_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id = %d AND variation_id IS NULL', $table, $product_id ) );
$assert( $count_before + 1 === $count_after, 'A Stockino adjustment records exactly one movement.' );
$plus_row = $wpdb->get_row( $wpdb->prepare( 'SELECT movement_type, quantity_before, quantity_delta, quantity_after FROM %i WHERE id = %d', $table, $plus->get_data()['movement_id'] ), ARRAY_A );
$assert( 'manual_adjustment' === $plus_row['movement_type'] && $original === (float) $plus_row['quantity_before'] && 5.0 === (float) $plus_row['quantity_delta'] && $original + 5 === (float) $plus_row['quantity_after'], 'The +5 ledger row has exact before, delta, and after arithmetic.' );
$zero_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id = %d', $table, $product_id ) );
$zero        = $request(
	'POST',
	"/stockino/v1/products/{$product_id}/adjust-stock",
	array(
		'mode'     => 'delta',
		'quantity' => 0,
		'reason'   => 'correction',
	)
);
$zero_after  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id = %d', $table, $product_id ) );
$assert( 400 === $zero->get_status() && 'stockino_zero_delta' === $zero->get_data()['code'] && $zero_before === $zero_after, 'Zero delta is rejected without creating a movement.' );

$minus = $request(
	'POST',
	"/stockino/v1/products/{$product_id}/adjust-stock",
	array(
		'mode'     => 'delta',
		'quantity' => -3,
		'reason'   => 'damaged',
	)
);
$assert( 200 === $minus->get_status() && $original + 2 === $minus->get_data()['quantity_after'], 'Delta -3 updates WooCommerce stock.' );
$set = $request(
	'POST',
	"/stockino/v1/products/{$product_id}/adjust-stock",
	array(
		'mode'             => 'set',
		'quantity'         => $original,
		'expected_current' => $original + 2,
		'reason'           => 'correction',
	)
);
$assert( 200 === $set->get_status() && $original === $set->get_data()['quantity_after'], 'Set mode validates expected current stock and restores quantity.' );

$stale = $request(
	'POST',
	"/stockino/v1/products/{$product_id}/adjust-stock",
	array(
		'mode'             => 'set',
		'quantity'         => $original + 1,
		'expected_current' => $original - 1,
		'reason'           => 'correction',
	)
);
$assert( 409 === $stale->get_status() && 'stockino_stale_stock' === $stale->get_data()['code'], 'Stale set-mode updates are rejected.' );
$invalid_reason = $request(
	'POST',
	"/stockino/v1/products/{$product_id}/adjust-stock",
	array(
		'mode'     => 'delta',
		'quantity' => 1,
		'reason'   => 'translated-label',
	)
);
$assert( 400 === $invalid_reason->get_status() && 'stockino_invalid_reason' === $invalid_reason->get_data()['code'], 'Unknown reason identifiers are rejected.' );

$product->set_backorders( 'yes' );
$product->save();
$negative = $request(
	'POST',
	"/stockino/v1/products/{$product_id}/adjust-stock",
	array(
		'mode'             => 'set',
		'quantity'         => -1,
		'expected_current' => $original,
		'reason'           => 'correction',
	)
);
$assert( 200 === $negative->get_status() && -1.0 === $negative->get_data()['quantity_after'], 'Negative stock is allowed when WooCommerce backorders permit it.' );
$request(
	'POST',
	"/stockino/v1/products/{$product_id}/adjust-stock",
	array(
		'mode'             => 'set',
		'quantity'         => $original,
		'expected_current' => -1,
		'reason'           => 'correction',
	)
);
$product = wc_get_product( $product_id );
$product->set_backorders( 'no' );
$product->save();

$variation = $request(
	'POST',
	"/stockino/v1/products/{$variation_id}/adjust-stock",
	array(
		'mode'     => 'delta',
		'quantity' => 1,
		'reason'   => 'found_stock',
	)
);
$assert( 200 === $variation->get_status(), 'Variation stock adjusts independently.' );
$request(
	'POST',
	"/stockino/v1/products/{$variation_id}/adjust-stock",
	array(
		'mode'     => 'delta',
		'quantity' => -1,
		'reason'   => 'correction',
	)
);
$inherited = $request(
	'POST',
	"/stockino/v1/products/{$inherited_id}/adjust-stock",
	array(
		'mode'     => 'delta',
		'quantity' => 1,
		'reason'   => 'correction',
	)
);
$assert( 409 === $inherited->get_status() && 'stockino_stock_managed_by_parent' === $inherited->get_data()['code'], 'A parent-managed variation cannot be adjusted independently.' );

$parent_id = wc_get_product( $variation_id )->get_parent_id();
$bulk      = $request(
	'POST',
	'/stockino/v1/inventory/bulk-adjust',
	array(
		'product_ids' => array( $product_id, $inherited_id ),
		'quantity'    => 1,
		'reason'      => 'correction',
	)
);
$assert( 200 === $bulk->get_status() && 1 === count( $bulk->get_data()['updated'] ) && 1 === count( $bulk->get_data()['failed'] ), 'Bulk adjustment reports partial success.' );
$request(
	'POST',
	"/stockino/v1/products/{$product_id}/adjust-stock",
	array(
		'mode'     => 'delta',
		'quantity' => -1,
		'reason'   => 'correction',
	)
);
$too_many = $request(
	'POST',
	'/stockino/v1/inventory/bulk-adjust',
	array(
		'product_ids' => range( 1000, 1100 ),
		'quantity'    => 1,
		'reason'      => 'correction',
	)
);
$assert( 400 === $too_many->get_status() && 'stockino_bulk_limit' === $too_many->get_data()['code'], 'The 100-product bulk cap is enforced.' );

$external_before  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE product_id = %d AND movement_type = 'woocommerce_external_change'", $table, $product_id ) );
$external_product = wc_get_product( $product_id );
wc_update_product_stock( $external_product, 1, 'increase' );
$external_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE product_id = %d AND movement_type = 'woocommerce_external_change'", $table, $product_id ) );
$assert( $external_before + 1 === $external_after, 'An external WooCommerce API change records exactly one external movement.' );
wc_update_product_stock( wc_get_product( $product_id ), 1, 'decrease' );

$history = $request( 'GET', "/stockino/v1/products/{$product_id}/movements" )->get_data();
$assert( $history['total_items'] >= 4 && count( $history['items'] ) <= 20, 'Movement history is paginated and product-scoped.' );
$csv = $request( 'GET', '/stockino/v1/inventory/export' );
$assert( 200 === $csv->get_status() && str_starts_with( ltrim( $csv->get_data(), "\xEF\xBB\xBF" ), 'product_id,variation_id' ), 'CSV export contains the documented header.' );
$filtered_csv = $request(
	'GET',
	'/stockino/v1/inventory/export',
	array(
		'category'  => 'stockino-electronics',
		'type'      => 'variation',
		'low_stock' => true,
	)
)->get_data();
$assert( str_contains( $filtered_csv, 'STK-110-S' ) && ! str_contains( $filtered_csv, 'STK-110-L' ), 'CSV export preserves the combined category, type, and low-stock filters.' );

WP_CLI::success( 'Stockino inventory smoke suite passed.' );
