<?php

namespace Stockino\Inventory;

use Stockino\Database\StockMovementRepository;
use WC_Product;
use WP_Error;

final class InventoryMutationService implements InventoryMutation {
	public function __construct(
		private readonly StockMovementRepository $movements,
		private readonly ?ExternalStockTracker $tracker = null
	) {}

	/** @param array<string,mixed> $movement @return array<string,mixed>|WP_Error */
	public function mutate( WC_Product $stock_target, string $mode, float $quantity, array $movement ) {
		$stock_owner_id = $stock_target->get_stock_managed_by_id();
		$this->tracker?->suppress( $stock_owner_id );
		try {
			try {
				if ( 'delta' === $mode ) {
					$operation = $quantity > 0 ? 'increase' : 'decrease';
					$updated   = wc_update_product_stock( $stock_target, abs( $quantity ), $operation );
				} else {
					$updated = wc_update_product_stock( $stock_target, $quantity, 'set' );
				}
			} catch ( \Throwable $exception ) {
				return new WP_Error(
					'stockino_stock_update_failed',
					__( 'WooCommerce could not update this stock quantity.', 'stockino' ),
					array(
						'status'        => 500,
						'stock_changed' => false,
					)
				);
			}
		} finally {
			$this->tracker?->release( $stock_owner_id );
		}

		if ( false === $updated || null === $updated ) {
			return new WP_Error(
				'stockino_stock_update_failed',
				__( 'WooCommerce could not update this stock quantity.', 'stockino' ),
				array(
					'status'        => 500,
					'stock_changed' => false,
				)
			);
		}

		$after = (float) $updated;
		$entry = 'delta' === $mode
			? StockMath::movement_from_delta_result( $after, $quantity )
			: array(
				'before' => (float) ( $movement['quantity_before'] ?? 0 ),
				'after'  => $after,
				'delta'  => $after - (float) ( $movement['quantity_before'] ?? 0 ),
			);
		try {
			$movement_id = $this->movements->insert(
				array_merge(
					$movement,
					array(
						'quantity_before' => $entry['before'],
						'quantity_delta'  => $entry['delta'],
						'quantity_after'  => $entry['after'],
					)
				)
			);
		} catch ( \RuntimeException $exception ) {
			return new WP_Error(
				'stockino_ledger_failed',
				__( 'Stock changed in WooCommerce, but the audit movement could not be recorded. Review this product immediately.', 'stockino' ),
				array(
					'status'          => 500,
					'stock_changed'   => true,
					'quantity_before' => $entry['before'],
					'quantity_delta'  => $entry['delta'],
					'quantity_after'  => $entry['after'],
				)
			);
		}

		return array(
			'movement_id'     => $movement_id,
			'quantity_before' => $entry['before'],
			'quantity_delta'  => $entry['delta'],
			'quantity_after'  => $entry['after'],
		);
	}
}
