<?php

declare(strict_types=1);

namespace Stockino\Support;

use Stockino\Import\ImportPipelineService;
use Stockino\Orders\OrderSyncService;
use Stockino\Publishing\PublicationControlOptions;
use Stockino\Publishing\PublishingEngine;
use Stockino\Sync\CentralSyncEngine;
use Stockino\Sync\InventorySyncEngine;

final class StockinoCliCommand {
	private ImportPipelineService $importer;
	private PublishingEngine $publishing;
	private InventorySyncEngine $inventory_sync;
	private OrderSyncService $order_sync;
	private CentralSyncEngine $central_sync;

	public function __construct(
		ImportPipelineService $importer,
		PublishingEngine $publishing,
		InventorySyncEngine $inventory_sync,
		OrderSyncService $order_sync,
		CentralSyncEngine $central_sync
	) {
		$this->importer       = $importer;
		$this->publishing     = $publishing;
		$this->inventory_sync = $inventory_sync;
		$this->order_sync     = $order_sync;
		$this->central_sync   = $central_sync;
	}

	public function register(): void {
		\WP_CLI::add_command( 'stockino import', array( $this, 'import_cmd' ) );
		\WP_CLI::add_command( 'stockino publish', array( $this, 'publish_cmd' ) );
		\WP_CLI::add_command( 'stockino sync-inventory', array( $this, 'sync_inventory_cmd' ) );
		\WP_CLI::add_command( 'stockino sync-orders', array( $this, 'sync_orders_cmd' ) );
		\WP_CLI::add_command( 'stockino sync-all', array( $this, 'sync_all_cmd' ) );
	}

	/**
	 * Import products from CSV file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to CSV file.
	 *
	 * [--dry-run]
	 * : Parse and validate without saving to WooCommerce.
	 */
	public function import_cmd( array $args, array $assoc_args ): void {
		$file_path = $args[0] ?? '';
		if ( ! file_exists( $file_path ) ) {
			\WP_CLI::error( sprintf( 'File not found: %s', $file_path ) );
		}

		$persist = ! isset( $assoc_args['dry-run'] );
		$content = (string) file_get_contents( $file_path );
		$result  = $this->importer->process_csv( $content, $persist );

		\WP_CLI::line( sprintf( 'Total Rows: %d', $result['total_rows'] ) );
		\WP_CLI::line( sprintf( 'Valid Rows: %d', $result['valid_count'] ) );
		\WP_CLI::line( sprintf( 'Imported: %d', $result['imported_count'] ) );

		if ( ! empty( $result['errors'] ) ) {
			\WP_CLI::warning( sprintf( 'Encountered %d errors during import:', count( $result['errors'] ) ) );
			foreach ( $result['errors'] as $err ) {
				\WP_CLI::line( sprintf( '  Row %d: %s', $err['row'], $err['error'] ) );
			}
		}

		\WP_CLI::success( 'Import completed.' );
	}

	/**
	 * Publish product to marketplace.
	 *
	 * ## OPTIONS
	 *
	 * --product=<id>
	 * : WooCommerce Product ID.
	 *
	 * [--marketplace=<marketplace>]
	 * : Target marketplace (default: basalam).
	 *
	 * [--dry-run]
	 * : Test payload without sending.
	 */
	public function publish_cmd( array $args, array $assoc_args ): void {
		$product_id  = absint( $assoc_args['product'] ?? 0 );
		$marketplace = (string) ( $assoc_args['marketplace'] ?? 'basalam' );
		$dry_run     = isset( $assoc_args['dry-run'] );

		if ( $product_id <= 0 ) {
			\WP_CLI::error( 'Valid --product=<id> is required.' );
		}

		$options = new PublicationControlOptions(
			true,
			true,
			true,
			true,
			$dry_run
		);

		$res = $this->publishing->publish( $product_id, $marketplace, $options );
		if ( $res->success ) {
			\WP_CLI::success( sprintf( '%s (External ID: %s)', $res->message, (string) $res->external_id ) );
		} else {
			\WP_CLI::error( sprintf( 'Failed: %s', $res->message ) );
		}
	}

	/**
	 * Sync inventory for marketplace products.
	 *
	 * ## OPTIONS
	 *
	 * [--product=<id>]
	 * : Specific product ID (optional).
	 */
	public function sync_inventory_cmd( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['product'] ) ) {
			$id  = absint( $assoc_args['product'] );
			$res = $this->inventory_sync->sync_product_inventory( $id );
			\WP_CLI::success( sprintf( 'Synced product #%d to %d marketplace link(s).', $id, count( $res ) ) );
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'stockino_marketplace_products';
		$ids   = $wpdb->get_col( "SELECT DISTINCT product_id FROM {$table} WHERE status = 'published' AND auto_sync_stock = 1" );

		if ( empty( $ids ) ) {
			\WP_CLI::line( 'No published products with auto-sync enabled.' );
			return;
		}

		$results = $this->inventory_sync->sync_batch( array_map( 'intval', $ids ) );
		\WP_CLI::success( sprintf( 'Completed inventory sync for %d products.', count( $results ) ) );
	}

	/**
	 * Sync orders from marketplace into WooCommerce and Orderino.
	 *
	 * ## OPTIONS
	 *
	 * [--marketplace=<marketplace>]
	 * : Target marketplace (default: basalam).
	 */
	public function sync_orders_cmd( array $args, array $assoc_args ): void {
		$marketplace = (string) ( $assoc_args['marketplace'] ?? 'basalam' );
		$res         = $this->order_sync->fetch_and_sync_orders( $marketplace );

		\WP_CLI::line( sprintf( 'Fetched: %d', $res['total_fetched'] ) );
		\WP_CLI::line( sprintf( 'Created WC Orders: %d', $res['created_count'] ) );
		\WP_CLI::line( sprintf( 'Updated WC Orders: %d', $res['updated_count'] ) );

		if ( ! empty( $res['errors'] ) ) {
			\WP_CLI::warning( sprintf( '%d order sync errors:', count( $res['errors'] ) ) );
			foreach ( $res['errors'] as $err ) {
				\WP_CLI::line( sprintf( '  Order %s: %s', $err['external_order_id'], $err['error'] ) );
			}
		}

		\WP_CLI::success( 'Order sync completed.' );
	}

	/**
	 * Run full synchronization (Orders, Inventory, Publication retries).
	 *
	 * ## OPTIONS
	 *
	 * [--marketplace=<marketplace>]
	 * : Target marketplace (default: basalam).
	 */
	public function sync_all_cmd( array $args, array $assoc_args ): void {
		$marketplace = (string) ( $assoc_args['marketplace'] ?? 'basalam' );
		$res         = $this->central_sync->run_full_sync( $marketplace );

		\WP_CLI::success( sprintf( 'Full synchronization finished with status: %s', $res['status'] ) );
	}
}
