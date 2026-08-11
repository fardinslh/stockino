<?php

namespace Stockino\REST;

use Stockino\Costing\ValuationService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ValuationRestApi {
	public function __construct( private readonly ValuationService $valuations ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$permission = array( 'permission_callback' => array( $this, 'can_manage' ) );
		register_rest_route(
			RestApi::NAMESPACE,
			'/valuation',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'list_rows' ),
				)
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/valuation/stats',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'stats' ),
				)
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/valuation/(?P<id>\d+)',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'get' ),
				)
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/valuation/(?P<id>\d+)/history',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'history' ),
				)
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/valuation/(?P<id>\d+)/initial-cost',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'set_initial' ),
				)
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/valuation/(?P<id>\d+)/corrections',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'correct' ),
				)
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function list_rows( WP_REST_Request $request ): WP_REST_Response {
		list( $page, $per_page ) = $this->pagination( $request );
		return new WP_REST_Response(
			$this->valuations->list(
				$page,
				$per_page,
				(string) $request->get_param( 'search' ),
				(string) $request->get_param( 'stock_status' ),
				(string) $request->get_param( 'cost_status' ),
				(string) $request->get_param( 'sort' ),
				(string) $request->get_param( 'direction' )
			)
		);
	}

	public function stats(): WP_REST_Response {
		return new WP_REST_Response( $this->valuations->stats() );
	}

	/** @return WP_REST_Response|WP_Error */
	public function get( WP_REST_Request $request ) {
		return $this->respond( $this->valuations->get( absint( $request['id'] ) ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function history( WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->pagination( $request );
		return $this->respond( $this->valuations->history( absint( $request['id'] ), $page, $per_page ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function set_initial( WP_REST_Request $request ) {
		return $this->respond( $this->valuations->set_initial( absint( $request['id'] ), $request->get_params() ), 201 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function correct( WP_REST_Request $request ) {
		return $this->respond( $this->valuations->correct( absint( $request['id'] ), $request->get_params() ), 201 );
	}

	/** @param mixed $result @return WP_REST_Response|WP_Error */
	private function respond( mixed $result, int $status = 200 ) {
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, $status );
	}

	/** @return array{int,int} */
	private function pagination( WP_REST_Request $request ): array {
		$page_param     = $request->get_param( 'page' );
		$per_page_param = $request->get_param( 'per_page' );
		$page           = max( 1, absint( $page_param ? $page_param : 1 ) );
		$per_page       = absint( $per_page_param ? $per_page_param : 20 );
		return array( $page, in_array( $per_page, array( 20, 50, 100 ), true ) ? $per_page : 20 );
	}
}
