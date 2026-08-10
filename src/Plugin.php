<?php

namespace Stockino;

use Stockino\Admin\AdminPage;
use Stockino\Database\Installer;
use Stockino\Database\StockMovementRepository;
use Stockino\Database\SupplierProductRepository;
use Stockino\Database\SupplierRepository;
use Stockino\Inventory\ExternalStockTracker;
use Stockino\Inventory\InventoryService;
use Stockino\Inventory\InventoryQuery;
use Stockino\Inventory\ProductDtoFactory;
use Stockino\Inventory\StockAdjustmentService;
use Stockino\REST\RestApi;
use Stockino\REST\SupplierRestApi;
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
		$inventory = new InventoryService( $movements, new ProductDtoFactory(), new InventoryQuery() );
		$tracker->register();
		( new RestApi( $inventory, new StockAdjustmentService( $movements, $tracker ), $movements ) )->register();
		$validator = new SupplierValidator();
		$suppliers = new SupplierRepository();
		$relations = new SupplierProductRepository();
		( new SupplierProductCleanup( $relations ) )->register();
		( new SupplierRestApi(
			new SupplierService( $suppliers, $validator ),
			new SupplierProductService( $relations, $suppliers, $validator ),
			$inventory
		) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new \Stockino\Support\FixtureCommand() )->register();
			( new \Stockino\Support\SupplierFixtureCommand() )->register();
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
