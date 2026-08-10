<?php

namespace Stockino\Inventory;

final class LowStockDefinition {
	public static function is_low( ?float $quantity, float $low_stock_amount, float $no_stock_amount ): bool {
		return null !== $quantity && $quantity <= $low_stock_amount && $quantity > $no_stock_amount;
	}
}
