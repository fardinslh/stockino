<?php

declare(strict_types=1);

namespace Stockino\Domain\Product;

use Stockino\Domain\Inventory\CanonicalInventory;
use Stockino\Domain\Pricing\CanonicalPrice;

final class CanonicalVariant {
	/**
	 * @param array<string, string> $attributes (e.g. ['size' => 'L', 'color' => 'Red'])
	 * @param array<int, string> $image_urls
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $sku,
		public readonly string $title,
		public readonly CanonicalPrice $price,
		public readonly CanonicalInventory $inventory,
		public readonly array $attributes = array(),
		public readonly array $image_urls = array(),
		public readonly ?int $weight_grams = null
	) {}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'sku'          => $this->sku,
			'title'        => $this->title,
			'price'        => $this->price->to_array(),
			'inventory'    => $this->inventory->to_array(),
			'attributes'   => $this->attributes,
			'image_urls'   => $this->image_urls,
			'weight_grams' => $this->weight_grams,
		);
	}
}
