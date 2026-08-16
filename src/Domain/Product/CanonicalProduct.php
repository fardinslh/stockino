<?php

declare(strict_types=1);

namespace Stockino\Domain\Product;

use Stockino\Domain\Inventory\CanonicalInventory;
use Stockino\Domain\Pricing\CanonicalPrice;

final class CanonicalProduct {
	/**
	 * @param array<int, CanonicalVariant> $variants
	 * @param array<int, string> $image_urls
	 * @param array<string, mixed> $attributes
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $sku,
		public readonly string $title,
		public readonly string $description,
		public readonly string $short_description,
		public readonly CanonicalPrice $price,
		public readonly CanonicalInventory $inventory,
		public readonly int $weight_grams = 250,
		public readonly ?string $category_id = null,
		public readonly ?string $brand = null,
		public readonly array $variants = array(),
		public readonly array $image_urls = array(),
		public readonly array $attributes = array(),
		public readonly ?string $parent_sku = null
	) {}

	public function is_variable(): bool {
		return ! empty( $this->variants );
	}

	public function has_sku(): bool {
		return '' !== trim( $this->sku );
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'id'                => $this->id,
			'sku'               => $this->sku,
			'title'             => $this->title,
			'description'       => $this->description,
			'short_description' => $this->short_description,
			'price'             => $this->price->to_array(),
			'inventory'         => $this->inventory->to_array(),
			'weight_grams'      => $this->weight_grams,
			'category_id'       => $this->category_id,
			'brand'             => $this->brand,
			'variants'          => array_map( static fn( CanonicalVariant $v ) => $v->to_array(), $this->variants ),
			'image_urls'        => $this->image_urls,
			'attributes'        => $this->attributes,
			'parent_sku'        => $this->parent_sku,
		);
	}
}
