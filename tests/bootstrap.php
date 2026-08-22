<?php

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'STOCKINO_VERSION', '1.0.0' );
define( 'STOCKINO_DB_VERSION', '5.0.0' );

if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( int $min, int $max ): int {
		return random_int( $min, $max );
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request(): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
		);
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return class_exists( 'WP_Error' ) && $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( array $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}
