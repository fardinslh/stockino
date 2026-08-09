<?php

namespace Stockino\Inventory;

use Stockino\Database\StockMovementRepository;
use WC_Product;

final class ExternalStockTracker {
	/** @var array<int,int> */
	private array $suppressed = array();

	/** @var array<int,float> */
	private array $before = array();

	public function __construct( private readonly StockMovementRepository $movements ) {}

	public function register(): void {
		add_action( 'woocommerce_product_before_set_stock', array( $this, 'capture_before' ) );
		add_action( 'woocommerce_variation_before_set_stock', array( $this, 'capture_before' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'record_after' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'record_after' ) );
	}

	public function suppress( int $product_id ): void {
		$this->suppressed[ $product_id ] = ( $this->suppressed[ $product_id ] ?? 0 ) + 1;
	}

	public function release( int $product_id ): void {
		if ( isset( $this->suppressed[ $product_id ] ) ) {
			--$this->suppressed[ $product_id ];
			if ( $this->suppressed[ $product_id ] <= 0 ) {
				unset( $this->suppressed[ $product_id ] );
			}
		}
		unset( $this->before[ $product_id ] );
	}

	public function capture_before( WC_Product $product ): void {
		$product_id = $product->get_stock_managed_by_id();
		if ( isset( $this->suppressed[ $product_id ] ) ) {
			return;
		}

		global $wpdb;
		$lookup_table = $wpdb->wc_product_meta_lookup;
		// A direct read is required here because a CRUD object may already contain its pending quantity.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT stock_quantity FROM %i WHERE product_id = %d', $lookup_table, $product_id ) );
		if ( null !== $value ) {
			$this->before[ $product_id ] = (float) $value;
		}
	}

	public function record_after( WC_Product $product ): void {
		$product_id = $product->get_stock_managed_by_id();
		if ( isset( $this->suppressed[ $product_id ] ) || ! isset( $this->before[ $product_id ] ) ) {
			return;
		}

		$before = $this->before[ $product_id ];
		unset( $this->before[ $product_id ] );
		$after = $product->get_stock_quantity();
		if ( null === $after || abs( (float) $after - $before ) < 0.000001 ) {
			return;
		}

		$is_variation = $product->is_type( 'variation' );
		try {
			$this->movements->insert(
				array(
					'product_id'      => $is_variation ? $product->get_parent_id() : $product_id,
					'variation_id'    => $is_variation ? $product_id : null,
					'movement_type'   => 'woocommerce_external_change',
					'reason'          => 'external_change',
					'quantity_before' => $before,
					'quantity_delta'  => (float) $after - $before,
					'quantity_after'  => (float) $after,
					'metadata'        => array( 'hook' => current_filter() ),
				)
			);
		} catch ( \RuntimeException $exception ) {
			// External changes must not be rolled back or break WooCommerce when audit storage is unavailable.
			do_action( 'stockino_external_movement_failed', $product_id, $exception );
		}
	}
}
