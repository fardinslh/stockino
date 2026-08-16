<?php

declare(strict_types=1);

namespace Stockino\Domain\Order;

final class CanonicalOrderItem {
	/**
	 * @param array<string, string> $variation_attributes
	 */
	public function __construct(
		public readonly ?string $external_item_id,
		public readonly string $product_name,
		public readonly ?string $sku,
		public readonly int|float $quantity,
		public readonly float $unit_price,
		public readonly float $total_price,
		public readonly ?int $wc_product_id = null,
		public readonly ?int $wc_variation_id = null,
		public readonly array $variation_attributes = array()
	) {}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'external_item_id'     => $this->external_item_id,
			'product_name'         => $this->product_name,
			'sku'                  => $this->sku,
			'quantity'             => $this->quantity,
			'unit_price'           => $this->unit_price,
			'total_price'          => $this->total_price,
			'wc_product_id'        => $this->wc_product_id,
			'wc_variation_id'      => $this->wc_variation_id,
			'variation_attributes' => $this->variation_attributes,
		);
	}
}
