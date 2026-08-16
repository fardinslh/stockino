<?php

declare(strict_types=1);

namespace Stockino\Sync;

use Stockino\Database\MarketplaceRepository;
use Stockino\Marketplaces\MarketplaceConnectionService;
use WC_Product;

final class InventorySyncEngine {
	private MarketplaceRepository $repository;
	private MarketplaceConnectionService $connection_service;

	public function __construct(
		MarketplaceRepository $repository,
		MarketplaceConnectionService $connection_service
	) {
		$this->repository         = $repository;
		$this->connection_service = $connection_service;
	}

	/**
	 * @param int $product_id
	 * @return array<int, array{marketplace: string, product_id: int, external_product_id: string, synced_stock: float, status: string, error?: string}>
	 */
	public function sync_product_inventory( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$links = $this->repository->get_published_links_for_product( $product_id );
		if ( empty( $links ) ) {
			return array();
		}

		$current_stock = max( 0.0, (float) ( $product->get_stock_quantity() ?? 0 ) );
		$results       = array();

		foreach ( $links as $link ) {
			$conn_id             = (int) $link['connection_id'];
			$marketplace         = (string) $link['marketplace_kind'];
			$external_product_id = (string) ( $link['external_product_id'] ?? '' );

			if ( empty( $external_product_id ) ) {
				continue;
			}

			try {
				$adapter = $this->connection_service->create_adapter( $marketplace );
				$adapter->update_stock( $external_product_id, $current_stock );

				$this->repository->save_product_link(
					array(
						'connection_id'      => $conn_id,
						'product_id'         => $product_id,
						'marketplace'        => $marketplace,
						'status'             => 'published',
						'last_synced_at'     => gmdate( 'Y-m-d H:i:s' ),
						'last_error_message' => null,
					)
				);

				$this->repository->add_log(
					$conn_id,
					$product_id,
					'sync_stock',
					'success',
					sprintf( 'موجودی محصول «%s» در %s به %s همگام‌سازی شد.', $product->get_name(), $marketplace, (string) $current_stock )
				);

				$results[] = array(
					'marketplace'         => $marketplace,
					'product_id'          => $product_id,
					'external_product_id' => $external_product_id,
					'synced_stock'        => $current_stock,
					'status'              => 'success',
				);
			} catch ( \Exception $e ) {
				$err = $e->getMessage();
				$this->repository->update_product_status( $conn_id, $product_id, 'sync_failed', $external_product_id, $err );
				$this->repository->add_log(
					$conn_id,
					$product_id,
					'sync_stock',
					'failure',
					sprintf( 'خطا در همگام‌سازی موجودی «%s» در %s: %s', $product->get_name(), $marketplace, $err )
				);

				$results[] = array(
					'marketplace'         => $marketplace,
					'product_id'          => $product_id,
					'external_product_id' => $external_product_id,
					'synced_stock'        => $current_stock,
					'status'              => 'failed',
					'error'               => $err,
				);
			}
		}

		return $results;
	}

	/**
	 * @param array<int, int> $product_ids
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	public function sync_batch( array $product_ids ): array {
		$results = array();
		foreach ( $product_ids as $id ) {
			$results[ (int) $id ] = $this->sync_product_inventory( (int) $id );
		}
		return $results;
	}
}
