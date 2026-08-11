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
		$before         = $stock_target->get_stock_quantity();
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
				$observed_quantity = null;
				try {
					$observed = wc_get_product( $stock_owner_id );
					if ( $observed instanceof WC_Product && null !== $observed->get_stock_quantity() ) {
						$observed_quantity = (float) $observed->get_stock_quantity();
					}
				} catch ( \Throwable ) {
					// Observation is best-effort only; the original write remains uncertain.
				}
				return new WP_Error(
					'stockino_stock_update_uncertain',
					__( 'WooCommerce reported an error after the stock write may have reached persistence. Reconcile this product before retrying.', 'stockino' ),
					array(
						'status'                  => 500,
						'mutation_outcome'        => self::OUTCOME_UNCERTAIN,
						'quantity_before'         => null === $before ? null : (float) $before,
						'quantity_requested'      => $quantity,
						'observed_quantity_after' => $observed_quantity,
						'exception_type'          => get_class( $exception ),
						'exception_message'       => $exception->getMessage(),
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
					'status'           => 500,
					'mutation_outcome' => self::OUTCOME_UNCHANGED,
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
					'status'           => 500,
					'mutation_outcome' => self::OUTCOME_CHANGED,
					'quantity_before'  => $entry['before'],
					'quantity_delta'   => $entry['delta'],
					'quantity_after'   => $entry['after'],
				)
			);
		}

		return array(
			'mutation_outcome' => self::OUTCOME_CHANGED,
			'movement_id'      => $movement_id,
			'quantity_before'  => $entry['before'],
			'quantity_delta'   => $entry['delta'],
			'quantity_after'   => $entry['after'],
		);
	}
}
