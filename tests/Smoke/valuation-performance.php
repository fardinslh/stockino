<?php

// Run after inventory fixtures with WP-CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This performance smoke must run through WP-CLI.' );
}

wp_set_current_user( 1 );
global $wpdb;
$queries = 0;
$counter = static function ( string $sql ) use ( &$queries, $wpdb ): string {
	if ( str_contains( $sql, $wpdb->posts ) || str_contains( $sql, $wpdb->prefix . 'stockino_inventory_costs' ) ) {
		++$queries;
	}
	return $sql;
};
add_filter( 'query', $counter );
$started = hrtime( true );
$request = new WP_REST_Request( 'GET', '/stockino/v1/valuation' );
$request->set_query_params(
	array(
		'page'      => 1,
		'per_page'  => 20,
		'sort'      => 'name',
		'direction' => 'asc',
	)
);
$response = rest_do_request( $request );
$elapsed  = ( hrtime( true ) - $started ) / 1e6;
remove_filter( 'query', $counter );
$data = $response->get_data();
if ( 200 !== $response->get_status() || count( $data['items'] ) > 20 || $queries > 2 ) {
	WP_CLI::error( 'Valuation pagination/query bound failed.' );
}
WP_CLI::success( sprintf( 'Valuation page size 20: %d of %d owners in %.2f ms using %d catalog/cost queries.', count( $data['items'] ), $data['pagination']['total_items'], $elapsed, $queries ) );
