<?php

namespace Stockino;

use Stockino\Admin\AdminPage;
use Stockino\Database\Installer;
use Stockino\Database\InventoryCostRepository;
use Stockino\Database\PurchaseOrderRepository;
use Stockino\Database\PurchaseReceiptRepository;
use Stockino\Database\ReorderRepository;
use Stockino\Database\ReorderSettingsRepository;
use Stockino\Database\StockMovementRepository;
use Stockino\Database\SupplierProductRepository;
use Stockino\Database\SupplierRepository;
use Stockino\Database\ValuationRepository;
use Stockino\Costing\InventoryCostService;
use Stockino\Costing\MysqlCostLock;
use Stockino\Costing\ValuationService;
use Stockino\Inventory\ExternalStockTracker;
use Stockino\Inventory\InventoryService;
use Stockino\Inventory\InventoryQuery;
use Stockino\Inventory\InventoryMutationService;
use Stockino\Inventory\ProductDtoFactory;
use Stockino\Inventory\StockAdjustmentService;
use Stockino\REST\RestApi;
use Stockino\REST\PurchaseOrderRestApi;
use Stockino\REST\ReorderRestApi;
use Stockino\REST\SupplierRestApi;
use Stockino\REST\ValuationRestApi;
use Stockino\Purchasing\MysqlReceiveLock;
use Stockino\Purchasing\PurchaseOrderService;
use Stockino\Purchasing\PurchaseReceivingService;
use Stockino\Reorder\MysqlReorderLock;
use Stockino\Reorder\ReorderCalculator;
use Stockino\Reorder\ReorderService;
use Stockino\Suppliers\SupplierProductCleanup;
use Stockino\Suppliers\SupplierProductService;
use Stockino\Suppliers\SupplierService;
use Stockino\Suppliers\SupplierValidator;

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

		$movements = new StockMovementRepository();
		$tracker   = new ExternalStockTracker( $movements );
		$mutations = new InventoryMutationService( $movements, $tracker );
		$inventory = new InventoryService( $movements, new ProductDtoFactory(), new InventoryQuery() );
		$tracker->register();
		( new RestApi( $inventory, new StockAdjustmentService( $mutations ), $movements ) )->register();
		$validator = new SupplierValidator();
		$suppliers = new SupplierRepository();
		$relations = new SupplierProductRepository();
		( new SupplierProductCleanup( $relations ) )->register();
		( new SupplierRestApi(
			new SupplierService( $suppliers, $validator ),
			new SupplierProductService( $relations, $suppliers, $validator ),
			$inventory
		) )->register();
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
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new \Stockino\Support\FixtureCommand() )->register();
			( new \Stockino\Support\SupplierFixtureCommand() )->register();
			( new \Stockino\Support\PurchaseFixtureCommand( $order_service, $receiving_service, $suppliers, $relations ) )->register();
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
