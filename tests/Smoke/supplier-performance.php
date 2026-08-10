<?php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This check must run through WP-CLI.' );
}

wp_set_current_user( 1 );
$measure = static function ( string $route, array $params ): array {
	$request = new WP_REST_Request( 'GET', $route );
	$request->set_query_params( $params );
	$start    = hrtime( true );
	$response = rest_do_request( $request );
	return array( $response, ( hrtime( true ) - $start ) / 1e6 );
};

list( $suppliers, $supplier_ms ) = $measure(
	'/stockino/v1/suppliers',
	array(
		'page'     => 1,
		'per_page' => 20,
	)
);
$supplier_data                   = $suppliers->get_data();
if ( 200 !== $suppliers->get_status() || count( $supplier_data['items'] ) > 20 ) {
	WP_CLI::error( 'Bounded supplier page failed.' );
}

$supplier_id = (int) $supplier_data['items'][0]['id'];
foreach ( $supplier_data['items'] as $supplier ) {
	if ( 'SUP-001' === $supplier['code'] ) {
		$supplier_id = (int) $supplier['id'];
		break;
	}
}
list( $products, $product_ms ) = $measure(
	"/stockino/v1/suppliers/{$supplier_id}/products",
	array(
		'page'     => 1,
		'per_page' => 20,
	)
);
$product_data                  = $products->get_data();
if ( 200 !== $products->get_status() || count( $product_data['items'] ) > 20 || $product_data['pagination']['total_items'] <= 20 ) {
	WP_CLI::error( 'Bounded supplier-products page failed or fixture links do not exercise pagination.' );
}

WP_CLI::success(
	sprintf(
		'Suppliers: %d of %d in %.2f ms; supplier products: %d of %d in %.2f ms.',
		count( $supplier_data['items'] ),
		$supplier_data['pagination']['total_items'],
		$supplier_ms,
		count( $product_data['items'] ),
		$product_data['pagination']['total_items'],
		$product_ms
	)
);
