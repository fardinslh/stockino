<?php

// Run after generating at least 500 fixtures.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This check must run through WP-CLI.' );
}

wp_set_current_user( 1 );
$request = new WP_REST_Request( 'GET', '/stockino/v1/inventory' );
$request->set_query_params(
	array(
		'page'         => 1,
		'per_page'     => 20,
		'manage_stock' => true,
		'low_stock'    => true,
	)
);
$memory_before = memory_get_usage( true );
$started       = hrtime( true );
$response      = rest_do_request( $request );
$elapsed_ms    = ( hrtime( true ) - $started ) / 1e6;
$memory_mb     = ( memory_get_peak_usage( true ) - $memory_before ) / 1048576;
$data          = $response->get_data();
if ( 200 !== $response->get_status() || count( $data['items'] ) > 20 || 20 !== $data['per_page'] ) {
	WP_CLI::error( 'Bounded inventory performance request failed.' );
}

WP_CLI::success(
	sprintf(
		'Bounded page returned %d of %d matches in %.2f ms (peak delta %.2f MiB).',
		count( $data['items'] ),
		$data['total_items'],
		$elapsed_ms,
		$memory_mb
	)
);
