<?php

// Run after purchase fixtures with WP-CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This performance smoke must run through WP-CLI.' );
}

wp_set_current_user( 1 );
global $wpdb;
$queries = 0;
$table   = $wpdb->prefix . 'stockino_purchase_orders';
$counter = static function ( string $sql ) use ( &$queries, $table ): string {
	if ( str_contains( $sql, $table ) ) {
		++$queries;
	}
	return $sql;
};
add_filter( 'query', $counter );
$started = hrtime( true );
$request = new WP_REST_Request( 'GET', '/stockino/v1/purchase-orders' );
$request->set_query_params(
	array(
		'page'     => 1,
		'per_page' => 20,
	)
);
$response = rest_do_request( $request );
$elapsed  = ( hrtime( true ) - $started ) / 1e6;
remove_filter( 'query', $counter );
$data = $response->get_data();
if ( 200 !== $response->get_status() || count( $data['items'] ) > 20 || $queries > 2 ) {
	WP_CLI::error( 'Purchase-order list pagination/query bound failed.' );
}
WP_CLI::success( sprintf( 'Purchase orders: %d of %d in %.2f ms using %d purchase-order queries.', count( $data['items'] ), $data['pagination']['total_items'], $elapsed, $queries ) );
