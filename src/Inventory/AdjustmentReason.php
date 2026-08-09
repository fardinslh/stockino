<?php

namespace Stockino\Inventory;

final class AdjustmentReason {
	/** @var array<int,string> */
	private const ALLOWED = array(
		'manual_adjustment',
		'damaged',
		'correction',
		'found_stock',
		'internal_use',
		'other',
	);

	public static function is_valid( string $reason ): bool {
		return in_array( $reason, self::ALLOWED, true );
	}

	/** @return array<int,string> */
	public static function all(): array {
		return self::ALLOWED;
	}
}
