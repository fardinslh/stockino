<?php

declare(strict_types=1);

namespace Stockino\Import;

use Stockino\Domain\Inventory\CanonicalInventory;
use Stockino\Domain\Pricing\CanonicalPrice;
use Stockino\Domain\Product\CanonicalProduct;
use Stockino\Domain\Product\CanonicalVariant;

final class ProductNormalizer {
	/**
	 * @param array<string, mixed> $row
	 * @param array<int, CanonicalVariant> $variants
	 * @return CanonicalProduct
	 */
	public function normalize_row( array $row, array $variants = array() ): CanonicalProduct {
		$id                = isset( $row['id'] ) && is_numeric( $row['id'] ) ? (int) $row['id'] : null;
		$title             = trim( (string) ( $row['title'] ?? $row['name'] ?? $row['product_name'] ?? '' ) );
		$sku               = trim( (string) ( $row['sku'] ?? '' ) );
		$description       = trim( (string) ( $row['description'] ?? $title ) );
		$short_description = trim( (string) ( $row['short_description'] ?? $row['brief'] ?? mb_substr( $description, 0, 150 ) ) );

		$regular_price = (float) ( $row['price'] ?? $row['regular_price'] ?? 0 );
		$sale_price    = isset( $row['sale_price'] ) && is_numeric( $row['sale_price'] ) ? (float) $row['sale_price'] : null;
		$currency      = trim( (string) ( $row['currency'] ?? 'IRT' ) );
		$price         = new CanonicalPrice( $regular_price, $sale_price, $currency );

		$quantity     = (float) ( $row['stock'] ?? $row['quantity'] ?? $row['stock_quantity'] ?? 0 );
		$manage_stock = ! isset( $row['manage_stock'] ) || in_array( strtolower( (string) $row['manage_stock'] ), array( '1', 'true', 'yes' ), true );
		$stock_status = $quantity > 0 ? 'instock' : 'outofstock';
		$inventory    = new CanonicalInventory( $quantity, $manage_stock, $stock_status );

		$weight_raw   = (float) ( $row['weight'] ?? $row['weight_grams'] ?? 250 );
		$weight_grams = $weight_raw > 0 ? ( $weight_raw < 10 ? (int) round( $weight_raw * 1000 ) : (int) round( $weight_raw ) ) : 250;

		$category_id = isset( $row['category_id'] ) ? (string) $row['category_id'] : ( isset( $row['category'] ) ? (string) $row['category'] : null );
		$brand       = isset( $row['brand'] ) ? (string) $row['brand'] : null;

		$images_raw = (string) ( $row['images'] ?? $row['image_urls'] ?? $row['image'] ?? '' );
		$image_urls = array();
		if ( '' !== trim( $images_raw ) ) {
			$delimiter  = str_contains( $images_raw, '|' ) ? '|' : ( str_contains( $images_raw, ';' ) ? ';' : ',' );
			$image_urls = array_values( array_filter( array_map( 'trim', explode( $delimiter, $images_raw ) ) ) );
		}

		$attributes = array();
		foreach ( $row as $key => $val ) {
			if ( str_starts_with( $key, 'attr_' ) || str_starts_with( $key, 'attribute_' ) ) {
				$attr_name                = substr( $key, str_starts_with( $key, 'attr_' ) ? 5 : 10 );
				$attributes[ $attr_name ] = trim( (string) $val );
			}
		}

		return new CanonicalProduct(
			$id,
			$sku,
			$title,
			$description,
			$short_description,
			$price,
			$inventory,
			$weight_grams,
			$category_id,
			$brand,
			$variants,
			$image_urls,
			$attributes,
			isset( $row['parent_sku'] ) ? (string) $row['parent_sku'] : null
		);
	}
}
