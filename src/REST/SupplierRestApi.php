<?php

namespace Stockino\REST;

use Stockino\Inventory\InventoryService;
use Stockino\Suppliers\SupplierProductService;
use Stockino\Suppliers\SupplierService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class SupplierRestApi {
	public function __construct(
		private readonly SupplierService $suppliers,
		private readonly SupplierProductService $products,
		private readonly InventoryService $inventory
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$permission = array( 'permission_callback' => array( $this, 'can_manage' ) );
		register_rest_route(
			RestApi::NAMESPACE,
			'/suppliers',
			array(
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::READABLE,
						'callback' => array( $this, 'list_suppliers' ),
					)
				),
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::CREATABLE,
						'callback' => array( $this, 'create_supplier' ),
					)
				),
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/suppliers/stats',
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
			'/suppliers/(?P<id>\d+)',
			array(
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::READABLE,
						'callback' => array( $this, 'get_supplier' ),
					)
				),
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::EDITABLE,
						'callback' => array( $this, 'update_supplier' ),
					)
				),
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/suppliers/(?P<id>\d+)/archive',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'archive_supplier' ),
				)
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/suppliers/(?P<id>\d+)/reactivate',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'reactivate_supplier' ),
				)
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/suppliers/(?P<id>\d+)/products',
			array(
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::READABLE,
						'callback' => array( $this, 'supplier_products' ),
					)
				),
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::CREATABLE,
						'callback' => array( $this, 'link_product' ),
					)
				),
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/suppliers/(?P<id>\d+)/products/(?P<product_id>\d+)',
			array(
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::EDITABLE,
						'callback' => array( $this, 'update_product_link' ),
					)
				),
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::DELETABLE,
						'callback' => array( $this, 'unlink_product' ),
					)
				),
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/products/(?P<id>\d+)/suppliers',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'product_suppliers' ),
				)
			)
		);
		register_rest_route(
			RestApi::NAMESPACE,
			'/products/search',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'product_search' ),
				)
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function list_suppliers( WP_REST_Request $request ): WP_REST_Response {
		list( $page, $per_page ) = $this->pagination( $request );
		$has_products            = null === $request->get_param( 'has_products' ) ? null : rest_sanitize_boolean( $request->get_param( 'has_products' ) );
		return new WP_REST_Response( $this->suppliers->list( $page, $per_page, (string) $request->get_param( 'search' ), (string) $request->get_param( 'status' ), $has_products ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function create_supplier( WP_REST_Request $request ) {
		$result = $this->suppliers->create( $request->get_params() );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function get_supplier( WP_REST_Request $request ) {
		$result = $this->suppliers->get( absint( $request['id'] ) );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/** @return WP_REST_Response|WP_Error */
	public function update_supplier( WP_REST_Request $request ) {
		$result = $this->suppliers->update( absint( $request['id'] ), $request->get_params() );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/** @return WP_REST_Response|WP_Error */
	public function archive_supplier( WP_REST_Request $request ) {
		$result = $this->suppliers->set_status( absint( $request['id'] ), 'inactive' );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/** @return WP_REST_Response|WP_Error */
	public function reactivate_supplier( WP_REST_Request $request ) {
		$result = $this->suppliers->set_status( absint( $request['id'] ), 'active' );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	public function stats(): WP_REST_Response {
		return new WP_REST_Response( $this->suppliers->stats() );
	}

	/** @return WP_REST_Response|WP_Error */
	public function supplier_products( WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->pagination( $request );
		$result                  = $this->products->list_for_supplier( absint( $request['id'] ), $page, $per_page, (string) $request->get_param( 'search' ) );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/** @return WP_REST_Response|WP_Error */
	public function link_product( WP_REST_Request $request ) {
		$result = $this->products->create( absint( $request['id'] ), $request->get_params() );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function update_product_link( WP_REST_Request $request ) {
		$result = $this->products->update( absint( $request['id'] ), absint( $request['product_id'] ), $request->get_params() );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/** @return WP_REST_Response|WP_Error */
	public function unlink_product( WP_REST_Request $request ) {
		$result = $this->products->delete( absint( $request['id'] ), absint( $request['product_id'] ) );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'deleted' => true ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function product_suppliers( WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->pagination( $request );
		$result                  = $this->products->list_for_product( absint( $request['id'] ), $page, $per_page );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	public function product_search( WP_REST_Request $request ): WP_REST_Response {
		list( $page, $per_page ) = $this->pagination( $request );
		$result                  = $this->inventory->list(
			array(
				'page'         => $page,
				'per_page'     => $per_page,
				'search'       => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'stock_status' => '',
				'type'         => '',
				'category'     => '',
				'low_stock'    => false,
				'manage_stock' => null,
			)
		);
		return new WP_REST_Response( $result );
	}

	/** @return array{int,int} */
	private function pagination( WP_REST_Request $request ): array {
		$page_param = $request->get_param( 'page' );
		$per_param  = $request->get_param( 'per_page' );
		$page       = max( 1, absint( null !== $page_param ? $page_param : 1 ) );
		$requested  = absint( null !== $per_param ? $per_param : 20 );
		$per_page   = in_array( $requested, array( 20, 50, 100 ), true ) ? $requested : 20;
		return array( $page, $per_page );
	}
}
