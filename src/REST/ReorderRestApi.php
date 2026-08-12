<?php

namespace Stockino\REST;

use Stockino\Reorder\ReorderService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ReorderRestApi {
	public function __construct( private readonly ReorderService $reorders ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$permission = array( 'permission_callback' => array( $this, 'can_manage' ) );
		$this->route( '/reorder', WP_REST_Server::READABLE, 'list_rows', $permission );
		$this->route( '/reorder/stats', WP_REST_Server::READABLE, 'stats', $permission );
		$this->route( '/reorder/filters', WP_REST_Server::READABLE, 'filters', $permission );
		$this->route( '/reorder/create-purchase-orders', WP_REST_Server::CREATABLE, 'create_purchase_orders', $permission );
		$this->route( '/reorder/(?P<id>\d+)', WP_REST_Server::READABLE, 'get', $permission );
		$this->route( '/reorder/(?P<id>\d+)/incoming', WP_REST_Server::READABLE, 'incoming', $permission );
		register_rest_route(
			RestApi::NAMESPACE,
			'/reorder/(?P<id>\d+)/settings',
			array(
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::READABLE,
						'callback' => array( $this, 'get_settings' ),
					)
				),
				array_merge(
					$permission,
					array(
						'methods'  => 'PATCH',
						'callback' => array( $this, 'update_settings' ),
					)
				),
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function list_rows( WP_REST_Request $request ): WP_REST_Response {
		list( $page, $per_page ) = $this->pagination( $request );
		return new WP_REST_Response(
			$this->reorders->list(
				$page,
				$per_page,
				(string) $request->get_param( 'search' ),
				(string) $request->get_param( 'state' ),
				absint( $request->get_param( 'supplier_id' ) ),
				absint( $request->get_param( 'category_id' ) ),
				(string) $request->get_param( 'sort' )
			)
		);
	}

	public function stats(): WP_REST_Response {
		return new WP_REST_Response( $this->reorders->stats() );
	}

	public function filters(): WP_REST_Response {
		return new WP_REST_Response( $this->reorders->filters() );
	}

	/** @return WP_REST_Response|WP_Error */
	public function get( WP_REST_Request $request ) {
		return $this->respond( $this->reorders->get( absint( $request['id'] ) ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function incoming( WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->pagination( $request );
		return $this->respond( $this->reorders->incoming( absint( $request['id'] ), $page, $per_page ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function get_settings( WP_REST_Request $request ) {
		return $this->respond( $this->reorders->get_settings( absint( $request['id'] ) ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function update_settings( WP_REST_Request $request ) {
		return $this->respond( $this->reorders->update_settings( absint( $request['id'] ), $request->get_params() ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function create_purchase_orders( WP_REST_Request $request ) {
		$result = $this->reorders->create_purchase_orders( $request->get_param( 'stock_owner_ids' ) );
		return $this->respond( $result, 201 );
	}

	/** @param array<string,mixed> $permission */
	private function route( string $path, string $methods, string $callback, array $permission ): void {
		register_rest_route(
			RestApi::NAMESPACE,
			$path,
			array_merge(
				$permission,
				array(
					'methods'  => $methods,
					'callback' => array( $this, $callback ),
				)
			)
		);
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
