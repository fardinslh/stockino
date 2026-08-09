<?php

namespace Stockino\Inventory;

use Stockino\Database\StockMovementRepository;
use WC_Product;

final class InventoryService {
	public function __construct(
		private readonly StockMovementRepository $movements,
		private readonly ProductDtoFactory $dto_factory
	) {}

	/** @param array<string,mixed> $params @return array<string,mixed> */
	public function list( array $params ): array {
		$page               = max( 1, (int) ( $params['page'] ?? 1 ) );
		$requested_per_page = (int) ( $params['per_page'] ?? 20 );
		$per_page           = in_array( $requested_per_page, array( 20, 50, 100 ), true ) ? $requested_per_page : 20;
		$args               = array(
			'status'   => array( 'publish', 'private' ),
			'type'     => array( 'simple', 'variable', 'variation' ),
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		if ( ! empty( $params['stock_status'] ) && in_array( $params['stock_status'], array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			$args['stock_status'] = $params['stock_status'];
		}
		if ( ! empty( $params['type'] ) && in_array( $params['type'], array( 'simple', 'variable', 'variation' ), true ) ) {
			$args['type'] = array( $params['type'] );
		}
		if ( ! empty( $params['category'] ) ) {
			$args['category'] = array( sanitize_title( (string) $params['category'] ) );
		}
		if ( ! empty( $params['search'] ) ) {
			$search       = sanitize_text_field( (string) $params['search'] );
			$exact_sku_id = wc_get_product_id_by_sku( $search );
			if ( ctype_digit( $search ) && wc_get_product( (int) $search ) ) {
				$args['include'] = array_values( array_unique( array_filter( array( (int) $search, $exact_sku_id ) ) ) );
			} elseif ( $exact_sku_id ) {
				$args['include'] = array( $exact_sku_id );
			} else {
				$args['s'] = $search;
			}
		}

		$needs_stock_filter = isset( $params['manage_stock'] ) || ! empty( $params['low_stock'] );
		if ( $needs_stock_filter ) {
			$ids = $this->stock_filtered_ids( $params );
			if ( array() === $ids ) {
				return $this->empty_page( $page, $per_page );
			}
			$args['include'] = isset( $args['include'] ) ? array_values( array_intersect( $args['include'], $ids ) ) : $ids;
			if ( array() === $args['include'] ) {
				return $this->empty_page( $page, $per_page );
			}
		}

		$result     = wc_get_products( $args );
		$products   = array_values( array_filter( $result->products, static fn( $product ): bool => $product instanceof WC_Product ) );
		$ids        = array_map( static fn( WC_Product $product ): int => $product->get_id(), $products );
		$parents    = array_map( static fn( WC_Product $product ): int => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(), $products );
		$categories = $this->categories_for_products( $parents );
		$latest     = $this->movements->latest_for_products( $ids );

		return array(
			'items'        => array_map( fn( WC_Product $product ): array => $this->dto_factory->make( $product, $categories, $latest ), $products ),
			'current_page' => $page,
			'per_page'     => $per_page,
			'total_items'  => (int) $result->total,
			'total_pages'  => (int) $result->max_num_pages,
		);
	}

	/** @return array<string,int|float> */
	public function stats(): array {
		global $wpdb;
		$lookup     = $wpdb->wc_product_meta_lookup;
		$posts      = $wpdb->posts;
		$meta       = $wpdb->postmeta;
		$global_low = (float) get_option( 'woocommerce_notify_low_stock_amount', 2 );

		$sql = "SELECT
			SUM(CASE WHEN lookup.stock_quantity IS NOT NULL THEN 1 ELSE 0 END) AS managed_products,
			SUM(CASE WHEN lookup.stock_status = 'outofstock' THEN 1 ELSE 0 END) AS out_of_stock,
			SUM(CASE WHEN lookup.stock_quantity > 0 AND lookup.stock_quantity <= COALESCE(NULLIF(low.meta_value, ''), %f) THEN 1 ELSE 0 END) AS low_stock,
			COALESCE(SUM(lookup.stock_quantity), 0) AS total_units
		FROM {$lookup} lookup
		INNER JOIN {$posts} posts ON posts.ID = lookup.product_id
		LEFT JOIN (SELECT post_id, MAX(meta_value) AS meta_value FROM {$meta} WHERE meta_key = '_low_stock_amount' GROUP BY post_id) low ON low.post_id = lookup.product_id
		WHERE posts.post_type IN ('product','product_variation')
		AND posts.post_status IN ('publish','private')";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are WooCommerce/WordPress-owned table names and the threshold is prepared.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $global_low ), ARRAY_A );

		return array(
			'managed_products' => (int) ( $row['managed_products'] ?? 0 ),
			'out_of_stock'     => (int) ( $row['out_of_stock'] ?? 0 ),
			'low_stock'        => (int) ( $row['low_stock'] ?? 0 ),
			'total_units'      => (float) ( $row['total_units'] ?? 0 ),
		);
	}

	/** @return array<string,mixed> */
	public function filters(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 200,
			)
		);
		return array(
			'categories'     => is_wp_error( $terms ) ? array() : array_map(
				static fn( $term ): array => array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				),
				$terms
			),
			'product_types'  => array( 'simple', 'variable', 'variation' ),
			'stock_statuses' => array( 'instock', 'outofstock', 'onbackorder' ),
		);
	}

	/** @param array<string,mixed> $params @return array<int,int> */
	private function stock_filtered_ids( array $params ): array {
		global $wpdb;
		$lookup = $wpdb->wc_product_meta_lookup;
		$posts  = $wpdb->posts;
		$meta   = $wpdb->postmeta;
		$where  = array( "posts.post_type IN ('product','product_variation')", "posts.post_status IN ('publish','private')" );
		$values = array();
		if ( isset( $params['manage_stock'] ) ) {
			$where[] = ! empty( $params['manage_stock'] ) ? 'lookup.stock_quantity IS NOT NULL' : 'lookup.stock_quantity IS NULL';
		}
		if ( ! empty( $params['low_stock'] ) ) {
			$where[]  = "lookup.stock_quantity > 0 AND lookup.stock_quantity <= COALESCE(NULLIF(low.meta_value, ''), %f)";
			$values[] = (float) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		}
		$sql = "SELECT lookup.product_id FROM {$lookup} lookup INNER JOIN {$posts} posts ON posts.ID = lookup.product_id LEFT JOIN (SELECT post_id, MAX(meta_value) AS meta_value FROM {$meta} WHERE meta_key = '_low_stock_amount' GROUP BY post_id) low ON low.post_id = lookup.product_id WHERE " . implode( ' AND ', $where );
		// Read-only lookup query is used because WC_Product_Query cannot express global-or-product low-stock thresholds.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- The WHERE fragments are fixed strings; the only dynamic value is prepared.
		$ids = $values ? $wpdb->get_col( $wpdb->prepare( $sql, $values ) ) : $wpdb->get_col( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $ids );
	}

	/** @param array<int,int> $product_ids @return array<int,array<int,string>> */
	private function categories_for_products( array $product_ids ): array {
		$terms = wp_get_object_terms( array_values( array_unique( $product_ids ) ), 'product_cat', array( 'fields' => 'all_with_object_id' ) );
		$map   = array();
		if ( is_wp_error( $terms ) ) {
			return $map;
		}
		foreach ( $terms as $term ) {
			$map[ (int) $term->object_id ][] = $term->name;
		}
		return $map;
	}

	/** @return array<string,mixed> */
	private function empty_page( int $page, int $per_page ): array {
		return array(
			'items'        => array(),
			'current_page' => $page,
			'per_page'     => $per_page,
			'total_items'  => 0,
			'total_pages'  => 0,
		);
	}
}
