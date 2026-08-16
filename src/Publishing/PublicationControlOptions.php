<?php

declare(strict_types=1);

namespace Stockino\Publishing;

final class PublicationControlOptions {
	public function __construct(
		public readonly bool $sync_price = true,
		public readonly bool $sync_inventory = true,
		public readonly bool $sync_images = true,
		public readonly bool $sync_description = true,
		public readonly bool $dry_run = false,
		public readonly ?string $category_override = null,
		public readonly ?int $preparation_days = null
	) {}

	/**
	 * @param array<string, mixed> $params
	 * @return self
	 */
	public static function from_array( array $params ): self {
		return new self(
			isset( $params['sync_price'] ) ? (bool) $params['sync_price'] : true,
			isset( $params['sync_inventory'] ) ? (bool) $params['sync_inventory'] : true,
			isset( $params['sync_images'] ) ? (bool) $params['sync_images'] : true,
			isset( $params['sync_description'] ) ? (bool) $params['sync_description'] : true,
			isset( $params['dry_run'] ) ? (bool) $params['dry_run'] : false,
			isset( $params['category_override'] ) ? (string) $params['category_override'] : null,
			isset( $params['preparation_days'] ) ? (int) $params['preparation_days'] : null
		);
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'sync_price'        => $this->sync_price,
			'sync_inventory'    => $this->sync_inventory,
			'sync_images'       => $this->sync_images,
			'sync_description'  => $this->sync_description,
			'dry_run'           => $this->dry_run,
			'category_override' => $this->category_override,
			'preparation_days'  => $this->preparation_days,
		);
	}
}
