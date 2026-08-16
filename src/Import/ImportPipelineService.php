<?php

declare(strict_types=1);

namespace Stockino\Import;

use Stockino\Domain\Product\CanonicalProduct;
use Stockino\Domain\Product\CanonicalVariant;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Product_Attribute;

final class ImportPipelineService {
	private CsvProductImporter $csv_importer;
	private ProductValidator $validator;
	private ProductNormalizer $normalizer;

	public function __construct(
		?CsvProductImporter $csv_importer = null,
		?ProductValidator $validator = null,
		?ProductNormalizer $normalizer = null
	) {
		$this->csv_importer = $csv_importer ?? new CsvProductImporter();
		$this->validator    = $validator ?? new ProductValidator();
		$this->normalizer   = $normalizer ?? new ProductNormalizer();
	}

	/**
	 * @param string $csv_content
	 * @param bool $persist_to_wc
	 * @return array{
	 *   total_rows: int,
	 *   valid_count: int,
	 *   imported_count: int,
	 *   failed_count: int,
	 *   errors: array<int, array{row: int, error: string}>,
	 *   products: array<int, array<string, mixed>>
	 * }
	 */
	public function process_csv( string $csv_content, bool $persist_to_wc = true ): array {
		$rows = $this->csv_importer->parse_content( $csv_content );
		return $this->process_raw_rows( $rows, $persist_to_wc );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param bool $persist_to_wc
	 * @return array{
	 *   total_rows: int,
	 *   valid_count: int,
	 *   imported_count: int,
	 *   failed_count: int,
	 *   errors: array<int, array{row: int, error: string}>,
	 *   products: array<int, array<string, mixed>>
	 * }
	 */
	public function process_raw_rows( array $rows, bool $persist_to_wc = true ): array {
		$errors         = array();
		$valid_products = array();
		$imported_count = 0;

		// 1. Validate & normalize rows
		foreach ( $rows as $index => $row ) {
			$row_num    = $index + 2; // 1-based header offset
			$validation = $this->validator->validate_raw_row( $row );

			if ( ! $validation['is_valid'] ) {
				foreach ( $validation['errors'] as $err ) {
					$errors[] = array( 'row' => $row_num, 'error' => $err );
				}
				continue;
			}

			try {
				$canonical = $this->normalizer->normalize_row( $row );
				$valid_products[] = $canonical;
			} catch ( \Exception $e ) {
				$errors[] = array( 'row' => $row_num, 'error' => $e->getMessage() );
			}
		}

		// 2. Persist to WooCommerce if requested
		if ( $persist_to_wc && function_exists( 'wc_get_product' ) ) {
			foreach ( $valid_products as $product ) {
				try {
					$this->persist_canonical_product( $product );
					++$imported_count;
				} catch ( \Exception $e ) {
					$errors[] = array(
						'row'   => 0,
						'error' => sprintf( 'خطا در ثبت محصول «%s»: %s', $product->title, $e->getMessage() ),
					);
				}
			}
		}

		return array(
			'total_rows'     => count( $rows ),
			'valid_count'    => count( $valid_products ),
			'imported_count' => $persist_to_wc ? $imported_count : count( $valid_products ),
			'failed_count'   => count( $errors ),
			'errors'         => $errors,
			'products'       => array_map( static fn( CanonicalProduct $p ) => $p->to_array(), $valid_products ),
		);
	}

	public function persist_canonical_product( CanonicalProduct $canonical ): int {
		// Find existing product by SKU if available
		$existing_id = 0;
		if ( $canonical->has_sku() ) {
			$existing_id = wc_get_product_id_by_sku( $canonical->sku );
		}

		$product = $existing_id > 0 ? wc_get_product( $existing_id ) : new WC_Product_Simple();
		if ( ! $product ) {
			$product = new WC_Product_Simple();
		}

		$product->set_name( $canonical->title );
		$product->set_description( $canonical->description );
		$product->set_short_description( $canonical->short_description );
		$product->set_status( 'publish' );
		$product->set_regular_price( (string) $canonical->price->regular_price );

		if ( null !== $canonical->price->sale_price && $canonical->price->sale_price > 0 ) {
			$product->set_sale_price( (string) $canonical->price->sale_price );
		}

		$product->set_manage_stock( $canonical->inventory->manage_stock );
		$product->set_stock_quantity( $canonical->inventory->quantity );
		$product->set_stock_status( $canonical->inventory->stock_status );

		if ( $canonical->has_sku() ) {
			$product->set_sku( $canonical->sku );
		}

		if ( $canonical->weight_grams > 0 ) {
			$product->set_weight( (string) ( $canonical->weight_grams / 1000 ) );
		}

		$saved_id = (int) $product->save();

		// Set category if provided
		if ( $canonical->category_id ) {
			$cat_term = term_exists( $canonical->category_id, 'product_cat' );
			if ( ! $cat_term && is_string( $canonical->category_id ) && ! is_numeric( $canonical->category_id ) ) {
				$cat_term = wp_insert_term( $canonical->category_id, 'product_cat' );
			}
			if ( $cat_term && ! is_wp_error( $cat_term ) ) {
				$term_id = is_array( $cat_term ) ? (int) $cat_term['term_id'] : (int) $cat_term;
				wp_set_object_terms( $saved_id, array( $term_id ), 'product_cat' );
			}
		}

		return $saved_id;
	}
}
