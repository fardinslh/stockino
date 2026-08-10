<?php

namespace Stockino\Inventory;

use WC_Product;
use WP_Error;

interface InventoryMutation {
	/** @param array<string,mixed> $movement @return array<string,mixed>|WP_Error */
	public function mutate( WC_Product $stock_target, string $mode, float $quantity, array $movement );
}
