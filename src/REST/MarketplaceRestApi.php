<?php

declare(strict_types=1);

namespace Stockino\REST;

use Stockino\Database\MarketplaceRepository;
use Stockino\Import\ImportPipelineService;
use Stockino\Marketplaces\MarketplaceConnectionService;
use Stockino\Marketplaces\PublicationService;
use Stockino\Marketplaces\WebhookService;
use Stockino\Orders\OrderSyncService;
use Stockino\Publishing\PublicationControlOptions;
use Stockino\Publishing\PublishingEngine;
use Stockino\Sync\CentralSyncEngine;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class MarketplaceRestApi {
	private MarketplaceRepository $repository;
	private MarketplaceConnectionService $connections;
	private PublicationService $publication;
	private ?ImportPipelineService $import_service;
	private ?CentralSyncEngine $sync_engine;
	private ?OrderSyncService $order_sync;
	private ?PublishingEngine $publishing_engine;
	private ?WebhookService $webhook_service;

	public function __construct(
		MarketplaceRepository $repository,
		MarketplaceConnectionService $connections,
		PublicationService $publication,
		?ImportPipelineService $import_service = null,
		?CentralSyncEngine $sync_engine = null,
		?OrderSyncService $order_sync = null,
		?PublishingEngine $publishing_engine = null,
		?WebhookService $webhook_service = null
	) {
		$this->repository        = $repository;
		$this->connections       = $connections;
		$this->publication       = $publication;
		$this->import_service    = $import_service;
		$this->sync_engine       = $sync_engine;
		$this->order_sync        = $order_sync;
		$this->publishing_engine = $publishing_engine;
		$this->webhook_service   = $webhook_service;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$permission = array( 'permission_callback' => array( $this, 'can_manage' ) );

		register_rest_route(
			RestApi::NAMESPACE,
			'/marketplaces/connections',
			array(
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::READABLE,
						'callback' => array( $this, 'list_connections' ),
					)
				),
				array_merge(
					$permission,
					array(
						'methods'  => WP_REST_Server::CREATABLE,
						'callback' => array( $this, 'save_connection' ),
					)
				),
			)
		);

		register_rest_route(
			RestApi::NAMESPACE,
			'/marketplaces/test-connection',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'test_connection' ),
				)
			)
		);

		register_rest_route(
			RestApi::NAMESPACE,
			'/marketplaces/categories',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'get_categories' ),
				)
			)
		);

		register_rest_route(
			RestApi::NAMESPACE,
			'/publication/products',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'list_products' ),
				)
			)
		);

		register_rest_route(
			RestApi::NAMESPACE,
			'/publication/stats',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'get_stats' ),
				)
			)
		);

		register_rest_route(
			RestApi::NAMESPACE,
			'/publication/publish',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'publish_product' ),
				)
			)
		);

		register_rest_route(
			RestApi::NAMESPACE,
			'/publication/publish-batch',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'publish_batch' ),
				)
			)
		);

		register_rest_route(
			RestApi::NAMESPACE,
			'/publication/sync-stock',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'sync_stock' ),
				)
			)
		);

		register_rest_route(
			RestApi::NAMESPACE,
			'/publication/logs',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'get_logs' ),
				)
			)
		);

		// Import endpoint
		register_rest_route(
			RestApi::NAMESPACE,
			'/import/process',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'process_import' ),
				)
			)
		);

		// Full Central Sync
		register_rest_route(
			RestApi::NAMESPACE,
			'/sync/full',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'run_full_sync' ),
				)
			)
		);

		// Order Sync
		register_rest_route(
			RestApi::NAMESPACE,
			'/orders/sync',
			array_merge(
				$permission,
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'sync_orders' ),
				)
			)
		);

		// Public Webhook endpoint
		register_rest_route(
			RestApi::NAMESPACE,
			'/webhooks/(?P<marketplace>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function list_connections(): WP_REST_Response {
		return new WP_REST_Response( array( 'connections' => $this->connections->get_connections() ) );
	}

	public function save_connection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params      = (array) $request->get_json_params();
		$marketplace = sanitize_text_field( (string) ( $params['marketplace'] ?? 'basalam' ) );

		try {
			$connection = $this->connections->save_connection( $marketplace, $params );
			return new WP_REST_Response( array( 'connection' => $connection ) );
		} catch ( \Exception $e ) {
			return new WP_Error( 'stockino_connection_error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function test_connection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params      = (array) $request->get_json_params();
		$marketplace = sanitize_text_field( (string) ( $params['marketplace'] ?? 'basalam' ) );

		try {
			$result = $this->connections->test_connection( $marketplace );
			return new WP_REST_Response( $result );
		} catch ( \Exception $e ) {
			return new WP_Error( 'stockino_test_error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function get_categories( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$marketplace_param = $request->get_param( 'marketplace' );
		$marketplace       = sanitize_text_field( (string) ( $marketplace_param ? $marketplace_param : 'basalam' ) );
		try {
			$categories = $this->connections->get_categories( $marketplace );
			return new WP_REST_Response( array( 'categories' => $categories ) );
		} catch ( \Exception $e ) {
			return new WP_Error( 'stockino_category_error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function list_products( WP_REST_Request $request ): WP_REST_Response {
		$page_param        = $request->get_param( 'page' );
		$per_page_param    = $request->get_param( 'per_page' );
		$search_param      = $request->get_param( 'search' );
		$status_param      = $request->get_param( 'status' );
		$marketplace_param = $request->get_param( 'marketplace' );
		$params            = array(
			'page'        => max( 1, (int) ( $page_param ? $page_param : 1 ) ),
			'per_page'    => max( 1, min( 100, (int) ( $per_page_param ? $per_page_param : 20 ) ) ),
			'search'      => sanitize_text_field( (string) ( $search_param ? $search_param : '' ) ),
			'status'      => sanitize_text_field( (string) ( $status_param ? $status_param : '' ) ),
			'marketplace' => sanitize_text_field( (string) ( $marketplace_param ? $marketplace_param : 'basalam' ) ),
		);

		return new WP_REST_Response( $this->repository->list_publication_products( $params ) );
	}

	public function get_stats(): WP_REST_Response {
		return new WP_REST_Response( $this->repository->get_stats() );
	}

	public function publish_product( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params      = (array) $request->get_json_params();
		$product_id  = (int) ( $params['product_id'] ?? 0 );
		$marketplace = sanitize_text_field( (string) ( $params['marketplace'] ?? 'basalam' ) );

		if ( $product_id <= 0 ) {
			return new WP_Error( 'stockino_invalid_id', 'شناسه محصول نامعتبر است.', array( 'status' => 400 ) );
		}

		try {
			if ( $this->publishing_engine ) {
				$options = PublicationControlOptions::from_array( $params );
				$res     = $this->publishing_engine->publish( $product_id, $marketplace, $options );
				if ( ! $res->success ) {
					return new WP_Error( 'stockino_publish_failed', $res->message, array( 'status' => $res->status_code ?? 400 ) );
				}
				return new WP_REST_Response( $res->to_array() );
			}

			$result = $this->publication->publish_product( $product_id, $marketplace, $params );
			return new WP_REST_Response( $result );
		} catch ( \Exception $e ) {
			return new WP_Error( 'stockino_publish_error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function publish_batch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params      = (array) $request->get_json_params();
		$product_ids = (array) ( $params['product_ids'] ?? array() );
		$marketplace = sanitize_text_field( (string) ( $params['marketplace'] ?? 'basalam' ) );

		if ( empty( $product_ids ) ) {
			return new WP_Error( 'stockino_invalid_ids', 'حداقل یک شناسه محصول الزامی است.', array( 'status' => 400 ) );
		}

		try {
			$result = $this->publication->publish_batch( array_map( 'intval', $product_ids ), $marketplace, $params );
			return new WP_REST_Response( $result );
		} catch ( \Exception $e ) {
			return new WP_Error( 'stockino_publish_batch_error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function sync_stock( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params      = (array) $request->get_json_params();
		$product_ids = isset( $params['product_ids'] ) ? (array) $params['product_ids'] : ( isset( $params['product_id'] ) ? array( (int) $params['product_id'] ) : array() );

		if ( empty( $product_ids ) ) {
			return new WP_Error( 'stockino_invalid_ids', 'شناسه محصول الزامی است.', array( 'status' => 400 ) );
		}

		try {
			$results = $this->publication->sync_stock_batch( array_map( 'intval', $product_ids ) );
			return new WP_REST_Response(
				array(
					'results' => $results,
					'message' => 'همگام‌سازی موجودی انجام شد.',
				)
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'stockino_sync_stock_error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function get_logs( WP_REST_Request $request ): WP_REST_Response {
		$page_param     = $request->get_param( 'page' );
		$per_page_param = $request->get_param( 'per_page' );
		$page           = max( 1, (int) ( $page_param ? $page_param : 1 ) );
		$per_page       = max( 1, min( 100, (int) ( $per_page_param ? $per_page_param : 20 ) ) );
		$connection_id  = $request->get_param( 'connection_id' ) ? (int) $request->get_param( 'connection_id' ) : null;
		$product_id     = $request->get_param( 'product_id' ) ? (int) $request->get_param( 'product_id' ) : null;

		return new WP_REST_Response( $this->repository->get_logs( $page, $per_page, $connection_id, $product_id ) );
	}

	public function process_import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->import_service ) {
			return new WP_Error( 'stockino_import_disabled', 'سرویس واردسازی فعال نیست.', array( 'status' => 500 ) );
		}

		$params  = (array) $request->get_json_params();
		$content = (string) ( $params['content'] ?? '' );
		$persist = isset( $params['persist'] ) ? (bool) $params['persist'] : true;

		if ( '' === trim( $content ) ) {
			return new WP_Error( 'stockino_empty_content', 'محتوای واردسازی خالی است.', array( 'status' => 400 ) );
		}

		try {
			$result = $this->import_service->process_csv( $content, $persist );
			return new WP_REST_Response( $result );
		} catch ( \Exception $e ) {
			return new WP_Error( 'stockino_import_error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function run_full_sync( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->sync_engine ) {
			return new WP_Error( 'stockino_sync_disabled', 'موتور همگام‌سازی فعال نیست.', array( 'status' => 500 ) );
		}

		$params      = (array) $request->get_json_params();
		$marketplace = sanitize_text_field( (string) ( $params['marketplace'] ?? 'basalam' ) );

		try {
			$result = $this->sync_engine->run_full_sync( $marketplace );
			return new WP_REST_Response( $result );
		} catch ( \Exception $e ) {
			return new WP_Error( 'stockino_sync_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function sync_orders( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->order_sync ) {
			return new WP_Error( 'stockino_orders_disabled', 'سرویس همگام‌سازی سفارشات فعال نیست.', array( 'status' => 500 ) );
		}

		$params      = (array) $request->get_json_params();
		$marketplace = sanitize_text_field( (string) ( $params['marketplace'] ?? 'basalam' ) );

		try {
			$result = $this->order_sync->fetch_and_sync_orders( $marketplace, $params );
			return new WP_REST_Response( $result );
		} catch ( \Exception $e ) {
			return new WP_Error( 'stockino_order_sync_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->webhook_service ) {
			return new WP_Error( 'stockino_webhook_disabled', 'سرویس وب‌هوک فعال نیست.', array( 'status' => 500 ) );
		}

		$marketplace = sanitize_text_field( (string) $request->get_param( 'marketplace' ) );
		$payload     = (array) $request->get_json_params();
		$signature   = (string) ( $request->get_header( 'x-webhook-signature' ) ?? '' );

		try {
			$result = $this->webhook_service->handle_webhook( $marketplace, $payload, $signature );
			return new WP_REST_Response( $result );
		} catch ( \Exception $e ) {
			return new WP_Error( 'stockino_webhook_error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}
}
