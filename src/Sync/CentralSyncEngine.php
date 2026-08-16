<?php

declare(strict_types=1);

namespace Stockino\Sync;

use Stockino\Database\MarketplaceRepository;
use Stockino\Events\StockinoEvent;
use Stockino\Events\StockinoEventDispatcher;
use Stockino\Orders\OrderSyncService;
use Stockino\Publishing\PublishingEngine;

final class CentralSyncEngine {
	private MarketplaceRepository $repository;
	private InventorySyncEngine $inventory_sync;
	private OrderSyncService $order_sync;
	private PublishingEngine $publishing_engine;

	public function __construct(
		MarketplaceRepository $repository,
		InventorySyncEngine $inventory_sync,
		OrderSyncService $order_sync,
		PublishingEngine $publishing_engine
	) {
		$this->repository        = $repository;
		$this->inventory_sync    = $inventory_sync;
		$this->order_sync        = $order_sync;
		$this->publishing_engine = $publishing_engine;
	}

	/**
	 * @param string $marketplace
	 * @return array{
	 *   inventory_results: array<string, mixed>,
	 *   order_results: array<string, mixed>,
	 *   retried_publications: array<string, mixed>,
	 *   status: string
	 * }
	 */
	public function run_full_sync( string $marketplace = 'basalam' ): array {
		$lock_key = 'stockino_sync_lock_' . $marketplace;
		if ( get_transient( $lock_key ) ) {
			return array(
				'inventory_results'    => array(),
				'order_results'        => array(),
				'retried_publications' => array(),
				'status'               => 'locked_in_progress',
			);
		}

		// Set 5-minute lock
		set_transient( $lock_key, '1', 300 );

		try {
			// 1. Sync orders
			$order_results = $this->order_sync->fetch_and_sync_orders( $marketplace );

			// 2. Sync inventory for all published products
			global $wpdb;
			$table = $wpdb->prefix . 'stockino_marketplace_products';
			$product_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT product_id FROM {$table} WHERE marketplace = %s AND status = 'published' AND auto_sync_stock = 1",
					$marketplace
				)
			);

			$inventory_results = array();
			if ( ! empty( $product_ids ) ) {
				$inventory_results = $this->inventory_sync->sync_batch( array_map( 'intval', $product_ids ) );
			}

			// 3. Retry failed publications (up to 10)
			$failed_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT product_id FROM {$table} WHERE marketplace = %s AND status = 'error' LIMIT 10",
					$marketplace
				)
			);

			$retried = array();
			foreach ( ( is_array( $failed_ids ) ? $failed_ids : array() ) as $fid ) {
				$retried[ (int) $fid ] = $this->publishing_engine->publish( (int) $fid, $marketplace )->to_array();
			}

			delete_transient( $lock_key );

			StockinoEventDispatcher::dispatch(
				new StockinoEvent(
					StockinoEvent::INVENTORY_CHANGED,
					array( 'marketplace' => $marketplace, 'count' => count( $inventory_results ) )
				)
			);

			return array(
				'inventory_results'    => $inventory_results,
				'order_results'        => $order_results,
				'retried_publications' => $retried,
				'status'               => 'completed',
			);
		} catch ( \Exception $e ) {
			delete_transient( $lock_key );

			StockinoEventDispatcher::dispatch(
				new StockinoEvent(
					StockinoEvent::SYNC_FAILED,
					array( 'marketplace' => $marketplace, 'error' => $e->getMessage() )
				)
			);

			throw $e;
		}
	}
}
