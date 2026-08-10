<?php

namespace Stockino\Inventory;

use WC_Product;
use WP_Error;

final class StockAdjustmentService {
	private const SUPPORTED_TYPES = array( 'simple', 'variable', 'variation' );

	public function __construct(
		private readonly InventoryMutation $mutations
	) {}

	/** @return array<string,mixed>|WP_Error */
	public function adjust( int $product_id, string $mode, float $quantity, string $reason, string $note = '', ?float $expected_current = null ) {
		$note = sanitize_textarea_field( $note );
		if ( strlen( $note ) > 1000 ) {
			return new WP_Error( 'stockino_note_too_long', __( 'The note must be 1000 characters or fewer.', 'stockino' ), array( 'status' => 400 ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'stockino_invalid_product', __( 'The requested product does not exist.', 'stockino' ), array( 'status' => 404 ) );
		}

		if ( ! in_array( $product->get_type(), self::SUPPORTED_TYPES, true ) ) {
			return new WP_Error( 'stockino_unsupported_product', __( 'This product type is not supported for stock adjustment.', 'stockino' ), array( 'status' => 400 ) );
		}

		if ( ! $product->managing_stock() ) {
			return new WP_Error( 'stockino_stock_not_managed', __( 'Stock management is disabled for this product. Stockino will not enable it automatically.', 'stockino' ), array( 'status' => 409 ) );
		}

		if ( $product->get_stock_managed_by_id() !== $product->get_id() ) {
			return new WP_Error( 'stockino_stock_managed_by_parent', __( 'This variation inherits stock from its parent. Adjust the stock-managing parent product instead.', 'stockino' ), array( 'status' => 409 ) );
		}

		if ( ! AdjustmentReason::is_valid( $reason ) ) {
			return new WP_Error( 'stockino_invalid_reason', __( 'Choose a valid adjustment reason.', 'stockino' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $mode, array( 'set', 'delta' ), true ) || ! is_finite( $quantity ) ) {
			return new WP_Error( 'stockino_invalid_adjustment', __( 'Enter a valid stock adjustment.', 'stockino' ), array( 'status' => 400 ) );
		}

		$effective_quantity = (float) wc_stock_amount( $quantity );
		if ( 'delta' === $mode && 0.0 === $effective_quantity ) {
			return new WP_Error( 'stockino_zero_delta', __( 'Enter a non-zero stock adjustment.', 'stockino' ), array( 'status' => 400 ) );
		}

		$current = $product->get_stock_quantity();
		if ( null === $current ) {
			return new WP_Error( 'stockino_unknown_stock', __( 'WooCommerce did not return a numeric stock quantity.', 'stockino' ), array( 'status' => 409 ) );
		}

		if ( 'set' === $mode && null !== $expected_current && abs( (float) $current - $expected_current ) > 0.000001 ) {
			return new WP_Error(
				'stockino_stale_stock',
				__( 'Stock changed after this dialog was opened. Refresh and try again.', 'stockino' ),
				array(
					'status'           => 409,
					'current_quantity' => (float) $current,
				)
			);
		}

		try {
			$calculation = StockMath::calculate( (float) $current, $mode, $effective_quantity );
		} catch ( \InvalidArgumentException $exception ) {
			return new WP_Error( 'stockino_invalid_adjustment', __( 'Enter a valid stock adjustment.', 'stockino' ), array( 'status' => 400 ) );
		}
		if ( 'set' === $mode && (float) $current === $calculation['after'] ) {
			return new WP_Error( 'stockino_no_stock_change', __( 'The requested quantity is already the current stock.', 'stockino' ), array( 'status' => 400 ) );
		}

		if ( $calculation['after'] < 0 && ! $product->backorders_allowed() ) {
			return new WP_Error( 'stockino_negative_stock', __( 'This adjustment would create negative stock, but backorders are not allowed.', 'stockino' ), array( 'status' => 409 ) );
		}

		$result = $this->mutations->mutate(
			$product,
			$mode,
			$effective_quantity,
			array(
				'product_id'      => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
				'variation_id'    => $product->is_type( 'variation' ) ? $product->get_id() : null,
				'movement_type'   => 'manual_adjustment',
				'reason'          => $reason,
				'quantity_before' => (float) $current,
				'note'            => '' !== $note ? $note : null,
				'metadata'        => array(
					'mode'               => $mode,
					'requested_quantity' => $quantity,
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'product_id'      => $product->get_id(),
			'movement_id'     => $result['movement_id'],
			'quantity_before' => $result['quantity_before'],
			'quantity_delta'  => $result['quantity_delta'],
			'quantity_after'  => $result['quantity_after'],
			'negative'        => $result['quantity_after'] < 0,
		);
	}
}
