<?php

// Run with: wp eval-file wp-content/plugins/stockino/tests/Smoke/reorder-performance.php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This smoke suite must run through WP-CLI.' );
}

wp_set_current_user( 1 );
global $wpdb;
$catalog        = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM %i p INNER JOIN %i lookup ON lookup.product_id = p.ID AND lookup.stock_quantity IS NOT NULL
		WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private')",
		$wpdb->posts,
		$wpdb->wc_product_meta_lookup
	)
);
$before_queries = $wpdb->num_queries;
$started        = microtime( true );
$request        = new WP_REST_Request( 'GET', '/stockino/v1/reorder' );
$request->set_query_params(
	array(
		'page'     => 1,
		'per_page' => 20,
		'state'    => 'reorder_needed',
		'sort'     => 'urgency',
	)
);
$response = rest_do_request( $request );
$elapsed  = ( microtime( true ) - $started ) * 1000;
$queries  = $wpdb->num_queries - $before_queries;
$data     = $response->get_data();
if ( 200 !== $response->get_status() ) {
	WP_CLI::error( 'Reorder performance request failed.' );
}
WP_CLI::success(
	sprintf(
		'Reorder page size 20: catalog %d, %d recommendations, %d returned in %.2f ms using %d SQL queries.',
		$catalog,
		(int) $data['pagination']['total_items'],
		count( $data['items'] ),
		$elapsed,
		$queries
	)
);
