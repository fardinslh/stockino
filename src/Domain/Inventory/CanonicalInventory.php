<?php

declare(strict_types=1);

namespace Stockino\Domain\Inventory;

final class CanonicalInventory {
	public function __construct(
		public readonly float $quantity,
		public readonly bool $manage_stock = true,
		public readonly string $stock_status = 'instock',
		public readonly float $low_stock_threshold = 2.0,
		public readonly bool $allow_backorders = false
	) {}

	public function is_in_stock(): bool {
		return ! $this->manage_stock || $this->quantity > 0 || $this->allow_backorders;
	}

	public function is_low_stock(): bool {
		return $this->manage_stock && $this->quantity > 0 && $this->quantity <= $this->low_stock_threshold;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'quantity'            => $this->quantity,
			'manage_stock'        => $this->manage_stock,
			'stock_status'        => $this->stock_status,
			'low_stock_threshold' => $this->low_stock_threshold,
			'allow_backorders'    => $this->allow_backorders,
			'is_in_stock'         => $this->is_in_stock(),
			'is_low_stock'        => $this->is_low_stock(),
		);
	}
}
