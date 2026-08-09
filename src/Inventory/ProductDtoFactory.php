<?php

namespace Stockino\Inventory;

use WC_Product;

final class ProductDtoFactory {
	/** @param array<int,array<int,string>> $categories @param array<int,array<string,mixed>> $movements @return array<string,mixed> */
	public function make( WC_Product $product, array $categories, array $movements ): array {
		$id           = $product->get_id();
		$is_variation = $product->is_type( 'variation' );
		$parent_id    = $is_variation ? $product->get_parent_id() : 0;
		$name         = $product->get_name();
		$attributes   = $is_variation ? wc_get_formatted_variation( $product, true, false, true ) : '';
		$parent_name  = $is_variation ? get_the_title( $parent_id ) : '';
		if ( $is_variation && '' === $attributes ) {
			$attributes = trim( (string) preg_replace( '/^' . preg_quote( $parent_name, '/' ) . '\s*[-–—]\s*/u', '', $name ) );
		}
		$edit_id  = $is_variation ? $parent_id : $id;
		$edit_url = get_edit_post_link( $edit_id, 'raw' );

		return array(
			'id'                   => $id,
			'parent_id'            => $parent_id,
			'parent_name'          => $is_variation ? $parent_name : null,
			'name'                 => $name,
			'variation_attributes' => wp_strip_all_tags( $attributes ),
			'type'                 => $product->get_type(),
			'sku'                  => $product->get_sku(),
			'manage_stock'         => $product->managing_stock(),
			'can_adjust'           => $product->managing_stock() && $product->get_stock_managed_by_id() === $id,
			'stock_managed_by_id'  => $product->get_stock_managed_by_id(),
			'stock_quantity'       => null !== $product->get_stock_quantity() ? (float) $product->get_stock_quantity() : null,
			'stock_status'         => $product->get_stock_status(),
			'backorders'           => $product->get_backorders(),
			'low_stock_amount'     => (float) wc_get_low_stock_amount( $product ),
			'is_low_stock'         => $product->managing_stock() && null !== $product->get_stock_quantity() && (float) $product->get_stock_quantity() <= (float) wc_get_low_stock_amount( $product ) && (float) $product->get_stock_quantity() > 0,
			'category_names'       => $categories[ $is_variation ? $parent_id : $id ] ?? array(),
			'permalink'            => $product->get_permalink(),
			'edit_url'             => $edit_url ? $edit_url : '',
			'last_movement'        => $movements[ $id ] ?? null,
		);
	}
}
