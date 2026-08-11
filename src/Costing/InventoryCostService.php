<?php

namespace Stockino\Costing;

use Stockino\Database\InventoryCostRepository;
use WC_Product;
use WP_Error;

final class InventoryCostService {
	public function __construct(
		private readonly InventoryCostRepository $costs,
		private readonly CostLock $lock
	) {}

	/** @param array<string,mixed> $context @return array{movement_id:int,average_unit_cost:string,inventory_value:string} */
	public function record_receipt( array $context ): array {
		$owner_id = (int) $context['stock_owner_id'];
		$current  = $this->costs->find_current( $owner_id );
		if ( $current && $current['currency_snapshot'] !== (string) $context['currency_snapshot'] ) {
			throw new \RuntimeException( 'cost_currency_mismatch' );
		}
		$before   = $this->quantity( $context['quantity_before'] );
		$after    = $this->quantity( $context['quantity_after'] );
		$quantity = FixedDecimal::normalize_cost( $context['quantity_received'] );
		$unit     = FixedDecimal::normalize_cost( $context['actual_unit_cost'] );
		if ( null === $quantity || null === $unit ) {
			throw new \RuntimeException( 'invalid_receipt_cost_context' );
		}
		$average_before = $current['average_unit_cost'] ?? null;
		$average_after  = FixedDecimal::weighted_average( $before, $quantity, $average_before, $unit );
		$value_before   = FixedDecimal::inventory_value( $before, $average_before );
		$value_after    = FixedDecimal::inventory_value( $after, $average_after );
		$movement_id    = $this->costs->record(
			array(
				'stock_owner_id'               => $owner_id,
				'stock_owner_name_snapshot'    => (string) $context['stock_owner_name'],
				'source_product_id'            => (int) $context['source_product_id'],
				'source_variation_id'          => $context['source_variation_id'],
				'source_product_name_snapshot' => (string) $context['source_product_name'],
				'source_sku_snapshot'          => $context['source_sku'],
				'purchase_order_id'            => (int) $context['purchase_order_id'],
				'purchase_order_item_id'       => (int) $context['purchase_order_item_id'],
				'receipt_id'                   => (int) $context['receipt_id'],
				'receipt_item_id'              => (int) $context['receipt_item_id'],
				'movement_type'                => 'purchase_receipt',
				'quantity_received'            => $quantity,
				'unit_cost'                    => $unit,
				'quantity_before'              => $before,
				'quantity_after'               => $after,
				'average_cost_before'          => $average_before,
				'inventory_value_before'       => $value_before,
				'inventory_value_after'        => $value_after,
				'currency_snapshot'            => (string) $context['currency_snapshot'],
				'reason'                       => null,
			),
			$average_after,
			(int) $context['receipt_item_id']
		);
		return array(
			'movement_id'       => $movement_id,
			'average_unit_cost' => $average_after,
			'inventory_value'   => $value_after,
		);
	}

	public function currency_is_compatible( int $stock_owner_id, string $currency ): bool {
		$current = $this->costs->find_current( $stock_owner_id );
		return ! $current || $current['currency_snapshot'] === $currency;
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function set_initial( int $stock_owner_id, array $input ) {
		return $this->manual_change( $stock_owner_id, $input, 'initial_cost' );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function correct( int $stock_owner_id, array $input ) {
		return $this->manual_change( $stock_owner_id, $input, 'cost_correction' );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	private function manual_change( int $stock_owner_id, array $input, string $type ) {
		$cost   = FixedDecimal::normalize_cost( $input['average_unit_cost'] ?? null );
		$reason = sanitize_textarea_field( (string) ( $input['reason'] ?? '' ) );
		if ( null === $cost ) {
			return $this->error( 'stockino_invalid_unit_cost', 'Enter a non-negative cost with no more than six decimal places.', 400 );
		}
		if ( '' === trim( $reason ) || strlen( $reason ) > 1000 ) {
			return $this->error( 'stockino_cost_reason_required', 'Provide a reason of 1 to 1000 characters for this audited cost change.', 400 );
		}
		if ( ! $this->lock->acquire_many( array( $stock_owner_id ) ) ) {
			return $this->error( 'stockino_cost_owner_busy', 'Another operation is costing this stock owner. Try again shortly.', 409 );
		}
		try {
			$product = wc_get_product( $stock_owner_id );
			if ( ! $product instanceof WC_Product || ! in_array( $product->get_status(), array( 'publish', 'private' ), true ) || $product->get_stock_managed_by_id() !== $stock_owner_id || ! $product->managing_stock() || null === $product->get_stock_quantity() ) {
				return $this->error( 'stockino_cost_owner_not_found', 'Choose an active WooCommerce stock owner.', 404 );
			}
			$current = $this->costs->find_current( $stock_owner_id );
			if ( 'initial_cost' === $type && $current ) {
				return $this->error( 'stockino_initial_cost_exists', 'This stock owner already has a cost. Use the explicit correction workflow.', 409 );
			}
			if ( 'cost_correction' === $type && ! $current ) {
				return $this->error( 'stockino_cost_not_established', 'Set the initial average cost before recording a correction.', 409 );
			}
			$quantity       = $this->quantity( $product->get_stock_quantity() );
			$average_before = $current['average_unit_cost'] ?? null;
			$value_before   = FixedDecimal::inventory_value( $quantity, $average_before );
			$value_after    = FixedDecimal::inventory_value( $quantity, $cost );
			$currency       = get_woocommerce_currency();
			$movement_id    = $this->costs->record(
				array(
					'stock_owner_id'               => $stock_owner_id,
					'stock_owner_name_snapshot'    => $product->get_name(),
					'source_product_id'            => $stock_owner_id,
					'source_variation_id'          => $product->is_type( 'variation' ) ? $stock_owner_id : null,
					'source_product_name_snapshot' => $product->get_name(),
					'source_sku_snapshot'          => $product->get_sku() ? $product->get_sku() : null,
					'purchase_order_id'            => null,
					'purchase_order_item_id'       => null,
					'receipt_id'                   => null,
					'receipt_item_id'              => null,
					'movement_type'                => $type,
					'quantity_received'            => '0.000000',
					'unit_cost'                    => $cost,
					'quantity_before'              => $quantity,
					'quantity_after'               => $quantity,
					'average_cost_before'          => $average_before,
					'inventory_value_before'       => $value_before,
					'inventory_value_after'        => $value_after,
					'currency_snapshot'            => $currency,
					'reason'                       => $reason,
				),
				$cost,
				null
			);
			return array(
				'stock_owner_id'    => $stock_owner_id,
				'average_unit_cost' => $cost,
				'inventory_value'   => $value_after,
				'currency'          => $currency,
				'movement_id'       => $movement_id,
			);
		} catch ( \Throwable $exception ) {
			do_action( 'stockino_manual_cost_failed', $stock_owner_id, $exception );
			return $this->error( 'stockino_cost_storage_failed', 'The audited cost change could not be saved.', 500 );
		} finally {
			$this->lock->release_many( array( $stock_owner_id ) );
		}
	}

	private function quantity( mixed $value ): string {
		$quantity = FixedDecimal::normalize_quantity( $value );
		if ( null === $quantity ) {
			throw new \RuntimeException( 'invalid_stock_quantity_for_costing' );
		}
		return $quantity;
	}

	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
