<?php

namespace Stockino;

use Stockino\Admin\AdminPage;
use Stockino\Costing\InventoryCostRepository;
use Stockino\Costing\InventoryCostService;
use Stockino\Costing\MysqlCostLock;
use Stockino\Costing\ValuationRepository;
use Stockino\Costing\ValuationService;
use Stockino\Database\Installer;
use Stockino\Database\MovementRepository;
use Stockino\Inventory\InventoryQuery;
use Stockino\Inventory\InventoryService;
use Stockino\Inventory\StockMutationService;
use Stockino\Inventory\StockTracker;
use Stockino\Purchasing\MysqlReceiveLock;
use Stockino\Purchasing\PurchaseOrderRepository;
use Stockino\Purchasing\PurchaseOrderService;
use Stockino\Purchasing\PurchaseReceivingService;
use Stockino\Purchasing\PurchaseReceiptRepository;
use Stockino\Reorder\MysqlReorderLock;
use Stockino\Reorder\ReorderCalculator;
use Stockino\Reorder\ReorderRepository;
use Stockino\Reorder\ReorderService;
use Stockino\Reorder\ReorderSettingsRepository;
use Stockino\REST\InventoryRestApi;
use Stockino\REST\PurchaseOrderRestApi;
use Stockino\REST\ReorderRestApi;
use Stockino\REST\SupplierRestApi;
use Stockino\REST\ValuationRestApi;
use Stockino\Suppliers\SupplierProductRepository;
use Stockino\Suppliers\SupplierRepository;
use Stockino\Suppliers\SupplierService;

final class Plugin {
	public static function init(): void {
		register_activation_hook( STOCKINO_FILE, array( Installer::class, 'install' ) );

		add_action( 'plugins_loaded', array( self::class, 'bootstrap' ) );
	}

	public static function bootstrap(): void {
		if ( ! function_exists( 'WC' ) ) {
			add_action( 'admin_notices', array( self::class, 'woocommerce_notice' ) );
			return;
		}

		Installer::migrate();

		( new AdminPage() )->register();

		$movements         = new MovementRepository();
		$mutations         = new StockMutationService( $movements );
		$tracker           = new StockTracker( $mutations );
		$tracker->register();

		$inventory_service = new InventoryService( new InventoryQuery(), $mutations, $movements );
		( new InventoryRestApi( $inventory_service ) )->register();

		$suppliers         = new SupplierRepository();
		$relations         = new SupplierProductRepository();
		$supplier_service  = new SupplierService( $suppliers, $relations );
		( new SupplierRestApi( $supplier_service ) )->register();

		$orders            = new PurchaseOrderRepository();
		$receipts          = new PurchaseReceiptRepository();
		$operation_lock    = new MysqlReceiveLock();
		$cost_lock         = new MysqlCostLock();
		$costs             = new InventoryCostRepository();
		$cost_service      = new InventoryCostService( $costs, $cost_lock );
		$order_service     = new PurchaseOrderService( $orders, $suppliers, $relations, $operation_lock );
		$receiving_service = new PurchaseReceivingService( $orders, $receipts, $mutations, $operation_lock, $cost_service, $cost_lock );
		( new PurchaseOrderRestApi(
			$order_service,
			$receiving_service
		) )->register();
		( new ValuationRestApi( new ValuationService( new ValuationRepository(), $costs, $cost_service ) ) )->register();
		( new ReorderRestApi(
			new ReorderService(
				new ReorderRepository(),
				new ReorderSettingsRepository(),
				new ReorderCalculator(),
				$order_service,
				new MysqlReorderLock()
			)
		) )->register();

		$marketplace_repo       = new \Stockino\Database\MarketplaceRepository();
		$marketplace_conn_srv   = new \Stockino\Marketplaces\MarketplaceConnectionService( $marketplace_repo );
		$publication_srv        = new \Stockino\Marketplaces\PublicationService( $marketplace_repo, $marketplace_conn_srv );
		$publishing_engine      = new \Stockino\Publishing\PublishingEngine( $marketplace_repo, $marketplace_conn_srv );
		$import_pipeline        = new \Stockino\Import\ImportPipelineService();
		$inventory_sync_engine  = new \Stockino\Sync\InventorySyncEngine( $marketplace_repo, $marketplace_conn_srv );
		$order_sync_service     = new \Stockino\Orders\OrderSyncService( $marketplace_repo, $marketplace_conn_srv );
		$orderino_bridge        = new \Stockino\Orders\OrderinoBridge();
		$orderino_bridge->register();
		$fulfillment_service    = new \Stockino\Orders\FulfillmentDispatchService( $marketplace_repo, $marketplace_conn_srv );
		$fulfillment_service->register();
		$webhook_service        = new \Stockino\Marketplaces\WebhookService( $marketplace_repo, $order_sync_service );
		$central_sync_engine    = new \Stockino\Sync\CentralSyncEngine( $marketplace_repo, $inventory_sync_engine, $order_sync_service, $publishing_engine );

		( new \Stockino\REST\MarketplaceRestApi(
			$marketplace_repo,
			$marketplace_conn_srv,
			$publication_srv,
			$import_pipeline,
			$central_sync_engine,
			$order_sync_service,
			$publishing_engine,
			$webhook_service
		) )->register();

		// Auto sync marketplace stock on product stock changes
		add_action(
			'woocommerce_product_set_stock',
			static function ( $product ) use ( $inventory_sync_engine ): void {
				if ( $product instanceof \WC_Product ) {
					$inventory_sync_engine->sync_product_inventory( (int) $product->get_id() );
				}
			}
		);
		add_action(
			'woocommerce_variation_set_stock',
			static function ( $variation ) use ( $inventory_sync_engine ): void {
				if ( $variation instanceof \WC_Product ) {
					$inventory_sync_engine->sync_product_inventory( (int) $variation->get_id() );
				}
			}
		);

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new \Stockino\Support\FixtureCommand() )->register();
			( new \Stockino\Support\SupplierFixtureCommand() )->register();
			( new \Stockino\Support\PurchaseFixtureCommand( $order_service, $receiving_service, $suppliers, $relations ) )->register();
			( new \Stockino\Support\MarketplaceFixtureCommand( $marketplace_repo, $marketplace_conn_srv, $publication_srv ) )->register();
			( new \Stockino\Support\StockinoCliCommand( $import_pipeline, $publishing_engine, $inventory_sync_engine, $order_sync_service, $central_sync_engine ) )->register();
		}

		do_action( 'stockino_loaded' );
	}

	public static function woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Stockino requires WooCommerce. Install and activate WooCommerce to use inventory features.', 'stockino' )
		);
	}
}
