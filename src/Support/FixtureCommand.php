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
	 */
	public function generate( array $args, array $assoc_args ): void {
		if ( 'production' === wp_get_environment_type() ) {
			\WP_CLI::error( 'Fixtures are disabled in production.' );
		}

		$count = min( 500, max( 1, absint( $assoc_args['count'] ?? 100 ) ) );
		for ( $index = 1; $index <= $count; ++$index ) {
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
		$product->set_stock_quantity( 0 === $index % 9 ? 0 : ( 0 === $index % 7 ? 2 : 18 + $index ) );
		$product->set_stock_status( 0 === $index % 9 ? 'outofstock' : 'instock' );
		$product->set_low_stock_amount( 4 );
		if ( 0 !== $index % 8 ) {
			$product->set_sku( sprintf( 'STK-%03d', $index ) );
		}
		$product->save();
	}

	private function create_variable( int $index ): void {
		$product = new WC_Product_Variable();
		$product->set_name( sprintf( 'Stockino Variable %03d', $index ) );
		$product->set_status( 'publish' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'Small', 'Large' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$parent_id = $product->save();

		foreach ( array( 'Small', 'Large' ) as $offset => $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( '100000' );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 0 === $offset ? 2 : 14 );
			$variation->set_stock_status( 'instock' );
			$variation->set_low_stock_amount( 3 );
			$variation->set_sku( sprintf( 'STK-%03d-%s', $index, 0 === $offset ? 'S' : 'L' ) );
			$variation->save();
		}
	}
}
