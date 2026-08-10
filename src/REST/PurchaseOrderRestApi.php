<?php

namespace Stockino\REST;

use Stockino\Purchasing\PurchaseOrderService;
use Stockino\Purchasing\PurchaseReceivingService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class PurchaseOrderRestApi {
	public function __construct(
		private readonly PurchaseOrderService $orders,
		private readonly PurchaseReceivingService $receiving
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$permission = array( 'permission_callback' => array( $this, 'can_manage' ) );
		register_rest_route(
			RestApi::NAMESPACE,
			'/purchase-orders',
			array(
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::READABLE,
						'callback' => array( $this, 'list_orders' ),
					)
				),
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::CREATABLE,
						'callback' => array( $this, 'create_order' ),
					)
				),
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/purchase-orders/stats',
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
			'/purchase-orders/(?P<id>\d+)',
			array(
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::READABLE,
						'callback' => array( $this, 'get_order' ),
					)
				),
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::EDITABLE,
						'callback' => array( $this, 'update_order' ),
					)
				),
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/purchase-orders/(?P<id>\d+)/mark-ordered',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'mark_ordered' ),
				)
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/purchase-orders/(?P<id>\d+)/cancel',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'cancel' ),
				)
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/purchase-orders/(?P<id>\d+)/items',
			array(
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::READABLE,
						'callback' => array( $this, 'list_items' ),
					)
				),
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::CREATABLE,
						'callback' => array( $this, 'create_item' ),
					)
				),
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/purchase-orders/(?P<id>\d+)/items/(?P<item_id>\d+)',
			array(
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::EDITABLE,
						'callback' => array( $this, 'update_item' ),
					)
				),
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::DELETABLE,
						'callback' => array( $this, 'delete_item' ),
					)
				),
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/purchase-orders/(?P<id>\d+)/receipts',
			array(
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::READABLE,
						'callback' => array( $this, 'list_receipts' ),
					)
				),
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::CREATABLE,
						'callback' => array( $this, 'receive' ),
					)
				),
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/purchase-receipts/(?P<id>\d+)',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'get_receipt' ),
				)
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function list_orders( WP_REST_Request $request ): WP_REST_Response {
		list( $page, $per_page ) = $this->pagination( $request );
		return new WP_REST_Response(
			$this->orders->list(
				$page,
				$per_page,
				(string) $request->get_param( 'search' ),
				(string) $request->get_param( 'status' ),
				absint( $request->get_param( 'supplier_id' ) ),
				(string) $request->get_param( 'date_from' ),
				(string) $request->get_param( 'date_to' )
			)
		);
	}

	public function stats(): WP_REST_Response {
		return new WP_REST_Response( $this->orders->stats() );
	}

	/** @return WP_REST_Response|WP_Error */
	public function create_order( WP_REST_Request $request ) {
		return $this->respond( $this->orders->create( $request->get_params() ), 201 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function get_order( WP_REST_Request $request ) {
		return $this->respond( $this->orders->get( absint( $request['id'] ) ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function update_order( WP_REST_Request $request ) {
		return $this->respond( $this->orders->update( absint( $request['id'] ), $request->get_params() ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function mark_ordered( WP_REST_Request $request ) {
		return $this->respond( $this->orders->mark_ordered( absint( $request['id'] ) ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function cancel( WP_REST_Request $request ) {
		return $this->respond( $this->orders->cancel( absint( $request['id'] ) ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function list_items( WP_REST_Request $request ) {
		$result = $this->orders->get( absint( $request['id'] ) );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'items' => $result['items'] ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function create_item( WP_REST_Request $request ) {
		return $this->respond( $this->orders->add_item( absint( $request['id'] ), $request->get_params() ), 201 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function update_item( WP_REST_Request $request ) {
		return $this->respond( $this->orders->update_item( absint( $request['id'] ), absint( $request['item_id'] ), $request->get_params() ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function delete_item( WP_REST_Request $request ) {
		$result = $this->orders->delete_item( absint( $request['id'] ), absint( $request['item_id'] ) );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'deleted' => true ) );
	}

	public function list_receipts( WP_REST_Request $request ): WP_REST_Response {
		list( $page, $per_page ) = $this->pagination( $request );
		return new WP_REST_Response( $this->receiving->list_for_order( absint( $request['id'] ), $page, $per_page ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function receive( WP_REST_Request $request ) {
		$result = $this->receiving->receive( absint( $request['id'] ), $request->get_params() );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, ! empty( $result['idempotent_replay'] ) ? 200 : 201 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function get_receipt( WP_REST_Request $request ) {
		return $this->respond( $this->receiving->get( absint( $request['id'] ) ) );
	}

	/** @param array<string,mixed>|WP_Error $result @return WP_REST_Response|WP_Error */
	private function respond( $result, int $status = 200 ) {
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
