<?php

namespace Stockino\Suppliers;

use Stockino\Database\SupplierProductRepository;
use Stockino\Database\SupplierRepository;
use WC_Product;
use WP_Error;

final class SupplierProductService {
	private const TYPES = array( 'simple', 'variable', 'variation' );

	public function __construct(
		private readonly SupplierProductRepository $repository,
		private readonly SupplierRepository $suppliers,
		private readonly SupplierValidator $validator
	) {}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function create( int $supplier_id, array $input ) {
		$supplier = $this->suppliers->find( $supplier_id );
		if ( ! $supplier ) {
			return $this->supplier_not_found();
		}
		if ( 'active' !== $supplier['status'] ) {
			return new WP_Error( 'stockino_supplier_inactive', __( 'Reactivate this supplier before linking new products.', 'stockino' ), array( 'status' => 409 ) );
		}
		$product_id = absint( $input['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! in_array( $product->get_type(), self::TYPES, true ) ) {
			return $this->product_not_found();
		}
		if ( $this->repository->find( $supplier_id, $product_id ) ) {
			return $this->duplicate_error();
		}
		$data = $this->validator->relationship( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data = array_merge(
			array(
				'supplier_id' => $supplier_id,
				'product_id'  => $product_id,
			),
			$data
		);
		try {
			$this->repository->create( $data );
		} catch ( \RuntimeException $exception ) {
			return 'duplicate_relation' === $exception->getMessage() ? $this->duplicate_error() : $this->storage_error();
		}
		return $this->get( $supplier_id, $product_id );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function update( int $supplier_id, int $product_id, array $input ) {
		if ( ! $this->suppliers->find( $supplier_id ) ) {
			return $this->supplier_not_found();
		}
		if ( ! $this->repository->find( $supplier_id, $product_id ) ) {
			return $this->relation_not_found();
		}
		$data = $this->validator->relationship( $input, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( array() !== $data ) {
			try {
				$this->repository->update( $supplier_id, $product_id, $data );
			} catch ( \RuntimeException $exception ) {
				return $this->storage_error();
			}
		}
		return $this->get( $supplier_id, $product_id );
	}

	/** @return true|WP_Error */
	public function delete( int $supplier_id, int $product_id ) {
		if ( ! $this->repository->find( $supplier_id, $product_id ) ) {
			return $this->relation_not_found();
		}
		return $this->repository->delete( $supplier_id, $product_id ) ? true : $this->storage_error();
	}

	/** @return array<string,mixed>|WP_Error */
	public function get( int $supplier_id, int $product_id ) {
		$relation = $this->repository->find( $supplier_id, $product_id );
		if ( ! $relation ) {
			return $this->relation_not_found();
		}
		$supplier = $this->suppliers->find( $supplier_id );
		$product  = wc_get_product( $product_id );
		if ( ! $supplier || ! $product instanceof WC_Product ) {
			return $this->relation_not_found();
		}
		$relation['effective_lead_time_days'] = null !== $relation['lead_time_days'] ? $relation['lead_time_days'] : $supplier['lead_time_days'];
		$relation['product']                  = $this->product_dto( $product );
		return $relation;
	}

	/** @return array<string,mixed>|WP_Error */
	public function list_for_supplier( int $supplier_id, int $page, int $per_page, string $search ) {
		$supplier = $this->suppliers->find( $supplier_id );
		if ( ! $supplier ) {
			return $this->supplier_not_found();
		}
		$result = $this->repository->paginate_for_supplier( $supplier_id, $page, $per_page, sanitize_text_field( $search ) );
		return $this->hydrate_products( $result, $supplier['lead_time_days'] );
	}

	/** @return array<string,mixed>|WP_Error */
	public function list_for_product( int $product_id, int $page, int $per_page ) {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! in_array( $product->get_type(), self::TYPES, true ) ) {
			return $this->product_not_found();
		}
		return $this->repository->paginate_for_product( $product_id, $page, $per_page );
	}

	/** @param array<string,mixed> $result @return array<string,mixed> */
	private function hydrate_products( array $result, ?int $supplier_lead_time ): array {
		$ids      = array_column( $result['items'], 'product_id' );
		$products = array();
		if ( array() === $ids ) {
			return $result;
		}
		foreach ( wc_get_products(
			array(
				'include' => $ids,
				'limit'   => count( $ids ),
				'status'  => array( 'publish', 'private' ),
				'type'    => self::TYPES,
			)
		) as $product ) {
			if ( $product instanceof WC_Product ) {
				$products[ $product->get_id() ] = $product;
			}
		}
		foreach ( $result['items'] as &$relation ) {
			$relation['effective_lead_time_days'] = null !== $relation['lead_time_days'] ? $relation['lead_time_days'] : $supplier_lead_time;
			$relation['product']                  = isset( $products[ $relation['product_id'] ] ) ? $this->product_dto( $products[ $relation['product_id'] ] ) : null;
		}
		unset( $relation );
		return $result;
	}

	/** @return array<string,mixed> */
	private function product_dto( WC_Product $product ): array {
		$is_variation = $product->is_type( 'variation' );
		return array(
			'id'                   => $product->get_id(),
			'parent_id'            => $is_variation ? $product->get_parent_id() : 0,
			'name'                 => $product->get_name(),
			'sku'                  => $product->get_sku(),
			'type'                 => $product->get_type(),
			'variation_attributes' => $is_variation ? wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, true ) ) : '',
			'stock_quantity'       => null !== $product->get_stock_quantity() ? (float) $product->get_stock_quantity() : null,
			'stock_status'         => $product->get_stock_status(),
			'manage_stock'         => $product->managing_stock(),
		);
	}

	private function supplier_not_found(): WP_Error {
		return new WP_Error( 'stockino_supplier_not_found', __( 'The requested supplier does not exist.', 'stockino' ), array( 'status' => 404 ) );
	}

	private function product_not_found(): WP_Error {
		return new WP_Error( 'stockino_invalid_supplier_product', __( 'Choose a supported WooCommerce product or variation.', 'stockino' ), array( 'status' => 404 ) );
	}

	private function relation_not_found(): WP_Error {
		return new WP_Error( 'stockino_supplier_product_not_found', __( 'This supplier-product relationship does not exist.', 'stockino' ), array( 'status' => 404 ) );
	}

	private function duplicate_error(): WP_Error {
		return new WP_Error( 'stockino_duplicate_supplier_product', __( 'This product is already linked to the supplier.', 'stockino' ), array( 'status' => 409 ) );
	}

	private function storage_error(): WP_Error {
		return new WP_Error( 'stockino_supplier_product_storage_failed', __( 'The supplier-product relationship could not be saved.', 'stockino' ), array( 'status' => 500 ) );
	}
}
