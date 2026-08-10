<?php

namespace Stockino\Suppliers;

use Stockino\Database\SupplierProductRepository;

final class SupplierProductCleanup {
	public function __construct( private readonly SupplierProductRepository $relations ) {}

	public function register(): void {
		add_action( 'before_delete_post', array( $this, 'cleanup' ), 10, 2 );
	}

	public function cleanup( int $post_id, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		$product_ids = array( $post_id );
		if ( 'product' === $post->post_type ) {
			global $wpdb;
			$variation_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT ID FROM %i WHERE post_parent = %d AND post_type = %s',
					$wpdb->posts,
					$post_id,
					'product_variation'
				)
			);
			$product_ids   = array_merge( $product_ids, array_map( 'intval', $variation_ids ) );
		}

		$this->relations->delete_for_product_ids( $product_ids );
	}
}
