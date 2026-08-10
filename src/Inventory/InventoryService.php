<?php

namespace Stockino\Inventory;

use Stockino\Database\StockMovementRepository;
use WC_Product;

final class InventoryService {
	public function __construct(
		private readonly StockMovementRepository $movements,
		private readonly ProductDtoFactory $dto_factory,
		private readonly InventoryQuery $query
	) {}

	/** @param array<string,mixed> $params @return array<string,mixed> */
	public function list( array $params, ?int $known_total = null ): array {
		$page               = max( 1, (int) ( $params['page'] ?? 1 ) );
		$requested_per_page = (int) ( $params['per_page'] ?? 20 );
		$per_page           = in_array( $requested_per_page, array( 20, 50, 100 ), true ) ? $requested_per_page : 20;
		$result             = $this->query->page( $params, $page, $per_page, $known_total );
		$products           = array_values(
			array_filter(
				array_map( 'wc_get_product', $result['ids'] ),
				static fn( $product ): bool => $product instanceof WC_Product
			)
		);
		$ids                = array_map( static fn( WC_Product $product ): int => $product->get_id(), $products );
		$parents            = array_map( static fn( WC_Product $product ): int => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(), $products );
		$categories         = $this->categories_for_products( $parents );
		$latest             = $this->movements->latest_for_products( $ids );

		return array(
			'items'        => array_map( fn( WC_Product $product ): array => $this->dto_factory->make( $product, $categories, $latest ), $products ),
			'current_page' => $page,
			'per_page'     => $per_page,
			'total_items'  => $result['total'],
			'total_pages'  => (int) ceil( $result['total'] / $per_page ),
		);
	}

	/** @return array<string,int|float> */
	public function stats(): array {
		return $this->query->stats();
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
}
