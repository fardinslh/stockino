<?php

namespace Stockino\Inventory;

use InvalidArgumentException;

final class StockMath {
	/** @return array{before:float,delta:float,after:float} */
	public static function calculate( float $before, string $mode, float $quantity ): array {
		if ( ! is_finite( $before ) || ! is_finite( $quantity ) ) {
			throw new InvalidArgumentException( 'Stock quantities must be finite numbers.' );
		}

		if ( 'delta' === $mode ) {
			$after = $before + $quantity;
		} elseif ( 'set' === $mode ) {
			$after = $quantity;
		} else {
			throw new InvalidArgumentException( 'Unsupported adjustment mode.' );
		}

		return array(
			'before' => $before,
			'delta'  => $after - $before,
			'after'  => $after,
		);
	}

	/** @return array{before:float,after:float,delta:float} */
	public static function movement_from_delta_result( float $after, float $effective_delta ): array {
		if ( ! is_finite( $after ) || ! is_finite( $effective_delta ) || 0.0 === $effective_delta ) {
			throw new \InvalidArgumentException( 'A finite, non-zero effective delta is required.' );
		}

		return array(
			'before' => $after - $effective_delta,
			'after'  => $after,
			'delta'  => $effective_delta,
		);
	}
}
