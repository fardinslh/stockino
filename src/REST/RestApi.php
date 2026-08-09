<?php

namespace Stockino\REST;

use WP_REST_Response;

final class RestApi {
	public const NAMESPACE = 'stockino/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function status(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'name'       => 'Stockino',
				'version'    => STOCKINO_VERSION,
				'db_version' => STOCKINO_DB_VERSION,
			),
			200
		);
	}
}
