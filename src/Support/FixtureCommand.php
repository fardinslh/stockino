<?php

namespace Stockino\Support;

use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

final class FixtureCommand {
	public function register(): void {
		\WP_CLI::add_command( 'stockino fixtures', array( $this, 'generate' ) );
	}

	/**
	 * Generate development inventory products.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<count>]
	 * : Number of parent products. Default: 100.
	 *
	 * [--start=<index>]
	 * : First fixture index. Default: 1.
	 */
	public function generate( array $args, array $assoc_args ): void {
		$environment = wp_get_environment_type();
		if ( ! FixtureEnvironment::is_allowed( $environment ) ) {
			\WP_CLI::error( sprintf( 'Fixtures are disabled in the %s environment. Use local or development only.', $environment ) );
		}

		$count      = min( 2500, max( 1, absint( $assoc_args['count'] ?? 100 ) ) );
		$start      = max( 1, absint( $assoc_args['start'] ?? 1 ) );
		$no_stock   = (float) get_option( 'woocommerce_notify_no_stock_amount', 0 );
		$global_low = max( $no_stock + 1, (float) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
		update_option( 'woocommerce_notify_low_stock_amount', $global_low );
		for ( $index = $start; $index < $start + $count; ++$index ) {
			if ( 0 === $index % 10 ) {
				$this->create_variable( $index );
			} else {
				$this->create_simple( $index );
			}
		}

		\WP_CLI::success( sprintf( 'Created %d Stockino fixture parent products.', $count ) );
	}

	private function create_simple( int $index ): void {
		$product = new WC_Product_Simple();
		$product->set_name( sprintf( 'Stockino Fixture %03d', $index ) );
		$product->set_status( 'publish' );
		$product->set_regular_price( '100000' );
		$product->set_manage_stock( true );
		$quantity = 0 === $index % 9 ? 0 : ( 0 === $index % 7 ? 2 : 18 + $index );
		if ( 11 === $index % 100 ) {
			$quantity = (float) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		}
		$product->set_stock_quantity( $quantity );
		$product->set_stock_status( 0 === $index % 9 ? 'outofstock' : 'instock' );
		$product->set_low_stock_amount( 11 === $index % 100 ? '' : 4 );
		if ( 0 !== $index % 8 ) {
			$product->set_sku( sprintf( 'STK-%03d', $index ) );
		}
		$product->save();
	}

	private function create_variable( int $index ): void {
		$product = new WC_Product_Variable();
		$product->set_name( sprintf( 'Stockino Variable %03d', $index ) );
		$product->set_status( 'publish' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 30 );
		$product->set_stock_status( 'instock' );
		$product->set_low_stock_amount( 5 );
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'Small', 'Large', 'Parent' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$parent_id = $product->save();
		$term      = term_exists( 'Stockino Electronics', 'product_cat' );
		if ( ! $term ) {
			$term = wp_insert_term( 'Stockino Electronics', 'product_cat', array( 'slug' => 'stockino-electronics' ) );
		}
		if ( ! is_wp_error( $term ) ) {
			wp_set_object_terms( $parent_id, array( (int) ( is_array( $term ) ? $term['term_id'] : $term ) ), 'product_cat' );
		}

		foreach ( array( 'Small', 'Large', 'Parent' ) as $offset => $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( '100000' );
			$variation->set_manage_stock( 2 !== $offset );
			if ( 2 !== $offset ) {
				$variation->set_stock_quantity( 4 );
			}
			$variation->set_stock_status( 'instock' );
			$variation->set_low_stock_amount( 0 === $offset ? '' : 2 );
			$variation->set_sku( sprintf( 'STK-%03d-%s', $index, array( 'S', 'L', 'P' )[ $offset ] ) );
			$variation->save();
		}
	}
}
