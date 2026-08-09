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
}
