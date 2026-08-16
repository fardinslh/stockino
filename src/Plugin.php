<?php

namespace Stockino;

use Stockino\Admin\AdminPage;
use Stockino\Costing\InventoryCostService;
use Stockino\Costing\MysqlCostLock;
use Stockino\Costing\ValuationService;
use Stockino\Database\Installer;
use Stockino\Database\InventoryCostRepository;
use Stockino\Database\MarketplaceRepository;
use Stockino\Database\PurchaseOrderRepository;
use Stockino\Database\PurchaseReceiptRepository;
use Stockino\Database\ReorderRepository;
use Stockino\Database\ReorderSettingsRepository;
use Stockino\Database\StockMovementRepository;
use Stockino\Database\SupplierProductRepository;
use Stockino\Database\SupplierRepository;
use Stockino\Database\ValuationRepository;
use Stockino\Import\ImportPipelineService;
use Stockino\Inventory\ExternalStockTracker;
use Stockino\Inventory\InventoryMutationService;
use Stockino\Inventory\InventoryQuery;
use Stockino\Inventory\InventoryService;
use Stockino\Inventory\ProductDtoFactory;
use Stockino\Inventory\StockAdjustmentService;
use Stockino\Marketplaces\MarketplaceConnectionService;
use Stockino\Marketplaces\PublicationService;
use Stockino\Marketplaces\WebhookService;
use Stockino\Orders\FulfillmentDispatchService;
use Stockino\Orders\OrderinoBridge;
use Stockino\Orders\OrderSyncService;
use Stockino\Publishing\PublishingEngine;
use Stockino\Purchasing\MysqlReceiveLock;
use Stockino\Purchasing\PurchaseOrderService;
use Stockino\Purchasing\PurchaseReceivingService;
use Stockino\Reorder\MysqlReorderLock;
use Stockino\Reorder\ReorderCalculator;
use Stockino\Reorder\ReorderService;
use Stockino\REST\InventoryRestApi;
use Stockino\REST\MarketplaceRestApi;
use Stockino\REST\PurchaseOrderRestApi;
use Stockino\REST\ReorderRestApi;
use Stockino\REST\RestApi;
use Stockino\REST\SupplierRestApi;
use Stockino\REST\ValuationRestApi;
use Stockino\Suppliers\SupplierProductCleanup;
use Stockino\Suppliers\SupplierProductService;
use Stockino\Suppliers\SupplierService;
use Stockino\Suppliers\SupplierValidator;
use Stockino\Sync\CentralSyncEngine;
use Stockino\Sync\InventorySyncEngine;

final class Plugin {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		load_plugin_textdomain( 'stockino', false, dirname( plugin_basename( STOCKINO_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'woocommerce_notice' ) );
			return;
		}

		self::$booted = true;
		Installer::maybe_upgrade();
		( new AdminPage() )->register();

		// 1. Core Inventory
		$movements   = new StockMovementRepository();
		$tracker     = new ExternalStockTracker( $movements );
		$mutations   = new InventoryMutationService( $movements, $tracker );
		$inventory   = new InventoryService( $movements, new ProductDtoFactory(), new InventoryQuery() );
		$adjustments = new StockAdjustmentService( $mutations );
		$tracker->register();
		( new RestApi( $inventory, $adjustments, $movements ) )->register();

		// 2. Suppliers
		$validator = new SupplierValidator();
		$suppliers = new SupplierRepository();
		$relations = new SupplierProductRepository();
		( new SupplierProductCleanup( $relations ) )->register();
		( new SupplierRestApi(
			new SupplierService( $suppliers, $validator ),
			new SupplierProductService( $relations, $suppliers, $validator ),
			$inventory
		) )->register();

		// 3. Purchasing
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

		// 4. Valuation & Costing
		( new ValuationRestApi( new ValuationService( new ValuationRepository(), $costs, $cost_service ) ) )->register();

		// 5. Reorder Intelligence
		( new ReorderRestApi(
			new ReorderService(
				new ReorderRepository(),
				new ReorderSettingsRepository(),
				new ReorderCalculator(),
				$order_service,
				new MysqlReorderLock()
			)
		) )->register();

		// 6. Marketplaces, Publishing, Inventory Sync & Orderino Bridge
		$marketplace_repo      = new MarketplaceRepository();
		$marketplace_conn_srv  = new MarketplaceConnectionService( $marketplace_repo );
		$publication_srv       = new PublicationService( $marketplace_repo, $marketplace_conn_srv );
		$publishing_engine     = new PublishingEngine( $marketplace_repo, $marketplace_conn_srv );
		$import_pipeline       = new ImportPipelineService();
		$inventory_sync_engine = new InventorySyncEngine( $marketplace_repo, $marketplace_conn_srv );
		$order_sync_service    = new OrderSyncService( $marketplace_repo, $marketplace_conn_srv );

		$orderino_bridge = new OrderinoBridge();
		$orderino_bridge->register();

		$fulfillment_service = new FulfillmentDispatchService( $marketplace_repo, $marketplace_conn_srv );
		$fulfillment_service->register();

		$webhook_service     = new WebhookService( $marketplace_repo, $order_sync_service );
		$central_sync_engine = new CentralSyncEngine( $marketplace_repo, $inventory_sync_engine, $order_sync_service, $publishing_engine );

		( new MarketplaceRestApi(
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

		// 7. WP-CLI
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
