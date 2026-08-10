<?php

namespace Stockino\Purchasing;

use Stockino\Database\PurchaseOrderRepository;
use Stockino\Database\SupplierProductRepository;
use Stockino\Database\SupplierRepository;
use Stockino\Suppliers\PurchasingQuantity;
use WC_Product;
use WP_Error;

final class PurchaseOrderService {
	private const TYPES = array( 'simple', 'variable', 'variation' );

	public function __construct(
		private readonly PurchaseOrderRepository $orders,
		private readonly SupplierRepository $suppliers,
		private readonly SupplierProductRepository $relations
	) {}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function list( int $page, int $per_page, string $search, string $status, int $supplier_id, string $date_from, string $date_to ): array {
		return $this->orders->paginate(
			$page,
			$per_page,
			sanitize_text_field( $search ),
			sanitize_key( $status ),
			$supplier_id,
			$this->date( $date_from ) ?? '',
			$this->date( $date_to ) ?? ''
		);
	}

	/** @return array<string,int> */
	public function stats(): array {
		return $this->orders->stats();
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function create( array $input ) {
		$supplier = $this->suppliers->find( absint( $input['supplier_id'] ?? 0 ) );
		if ( ! $supplier ) {
			return $this->error( 'stockino_supplier_not_found', 'Choose an existing supplier.', 404 );
		}
		if ( 'active' !== $supplier['status'] ) {
			return $this->error( 'stockino_supplier_inactive', 'Choose an active supplier for a new purchase order.', 409 );
		}
		$data = $this->order_input( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data += array(
			'supplier_id'            => (int) $supplier['id'],
			'supplier_name_snapshot' => $supplier['name'],
			'supplier_code_snapshot' => $supplier['code'],
		);
		try {
			$id = $this->orders->create( $data );
		} catch ( \RuntimeException $exception ) {
			return $this->storage_error();
		}
		return $this->get( $id );
	}

	/** @return array<string,mixed>|WP_Error */
	public function get( int $id ) {
		$order = $this->orders->find( $id );
		if ( ! $order ) {
			return $this->not_found();
		}
		$order['items'] = $this->orders->items( $id );
		return $order;
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function update( int $id, array $input ) {
		$order = $this->orders->find( $id );
		if ( ! $order ) {
			return $this->not_found();
		}
		$data = $this->order_input( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( array() !== $data ) {
			try {
				$this->orders->update( $id, $data );
			} catch ( \RuntimeException $exception ) {
				return $this->storage_error();
			}
		}
		return $this->get( $id );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function add_item( int $order_id, array $input ) {
		$order = $this->orders->find( $order_id );
		if ( ! $order ) {
			return $this->not_found();
		}
		if ( 'draft' !== $order['status'] ) {
			return $this->locked();
		}
		$supplier = $this->suppliers->find( $order['supplier_id'] );
		if ( ! $supplier || 'active' !== $supplier['status'] ) {
			return $this->error( 'stockino_supplier_inactive', 'Reactivate this supplier before adding purchase-order lines.', 409 );
		}
		$product_id = absint( $input['product_id'] ?? 0 );
		$relation   = $this->relations->find( $order['supplier_id'], $product_id );
		$product    = wc_get_product( $product_id );
		if ( ! $relation || ! $product instanceof WC_Product || ! in_array( $product->get_type(), self::TYPES, true ) ) {
			return $this->error( 'stockino_invalid_purchase_product', 'Choose a current product linked to this supplier.', 409 );
		}
		$quantity = PurchasingQuantity::normalize( $input['ordered_quantity'] ?? null );
		if ( null === $quantity ) {
			return $this->quantity_error();
		}
		$is_variation = $product->is_type( 'variation' );
		$data         = array(
			'purchase_order_id'     => $order_id,
			'product_id'            => $product_id,
			'product_name_snapshot' => $product->get_name(),
			'sku_snapshot'          => $product->get_sku() ? $product->get_sku() : null,
			'product_type_snapshot' => $product->get_type(),
			'variation_snapshot'    => $is_variation ? wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, true ) ) : null,
			'supplier_sku_snapshot' => $relation['supplier_sku'],
			'ordered_quantity'      => $quantity,
			'notes'                 => $this->notes( $input['notes'] ?? '' ),
		);
		try {
			$item_id = $this->orders->create_item( $data );
		} catch ( \RuntimeException $exception ) {
			return 'duplicate_purchase_order_item' === $exception->getMessage()
				? $this->error( 'stockino_duplicate_purchase_order_item', 'This product already has a line on the purchase order.', 409 )
				: $this->storage_error();
		}
		return $this->orders->find_item( $order_id, $item_id );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function update_item( int $order_id, int $item_id, array $input ) {
		$order = $this->orders->find( $order_id );
		$item  = $this->orders->find_item( $order_id, $item_id );
		if ( ! $order || ! $item ) {
			return $this->error( 'stockino_purchase_order_item_not_found', 'The purchase-order line does not exist.', 404 );
		}
		if ( 'draft' !== $order['status'] ) {
			return $this->locked();
		}
		$data = array();
		if ( array_key_exists( 'ordered_quantity', $input ) ) {
			$quantity = PurchasingQuantity::normalize( $input['ordered_quantity'] );
			if ( null === $quantity ) {
				return $this->quantity_error();
			}
			$data['ordered_quantity'] = $quantity;
		}
		if ( array_key_exists( 'notes', $input ) ) {
			$data['notes'] = $this->notes( $input['notes'] );
		}
		try {
			$this->orders->update_item( $item_id, $data );
		} catch ( \RuntimeException $exception ) {
			return $this->storage_error();
		}
		return $this->orders->find_item( $order_id, $item_id );
	}

	/** @return true|WP_Error */
	public function delete_item( int $order_id, int $item_id ) {
		$order = $this->orders->find( $order_id );
		if ( ! $order || ! $this->orders->find_item( $order_id, $item_id ) ) {
			return $this->error( 'stockino_purchase_order_item_not_found', 'The purchase-order line does not exist.', 404 );
		}
		if ( 'draft' !== $order['status'] ) {
			return $this->locked();
		}
		return $this->orders->delete_item( $order_id, $item_id ) ? true : $this->storage_error();
	}

	/** @return array<string,mixed>|WP_Error */
	public function mark_ordered( int $id ) {
		$order = $this->orders->find( $id );
		if ( ! $order ) {
			return $this->not_found();
		}
		if ( 'draft' !== $order['status'] ) {
			return $this->error( 'stockino_invalid_purchase_order_transition', 'Only draft purchase orders can be marked ordered.', 409 );
		}
		$supplier = $this->suppliers->find( $order['supplier_id'] );
		$items    = $this->orders->items( $id );
		if ( ! $supplier || 'active' !== $supplier['status'] ) {
			return $this->error( 'stockino_supplier_inactive', 'Reactivate the supplier before marking this purchase order as ordered.', 409 );
		}
		if ( array() === $items ) {
			return $this->error( 'stockino_purchase_order_empty', 'Add at least one line before marking this purchase order as ordered.', 409 );
		}
		$now = current_time( 'mysql', true );
		try {
			$this->orders->update(
				$id,
				array(
					'status'     => 'ordered',
					'order_date' => $order['order_date'] ? $order['order_date'] : current_time( 'Y-m-d' ),
					'ordered_at' => $now,
				)
			);
		} catch ( \RuntimeException $exception ) {
			return $this->storage_error();
		}
		return $this->get( $id );
	}

	/** @return array<string,mixed>|WP_Error */
	public function cancel( int $id ) {
		$order = $this->orders->find( $id );
		if ( ! $order ) {
			return $this->not_found();
		}
		if ( ! in_array( $order['status'], array( 'draft', 'ordered', 'partially_received' ), true ) ) {
			return $this->error( 'stockino_invalid_purchase_order_transition', 'This purchase order cannot be cancelled.', 409 );
		}
		try {
			$this->orders->update(
				$id,
				array(
					'status'       => 'cancelled',
					'cancelled_at' => current_time( 'mysql', true ),
				)
			);
		} catch ( \RuntimeException $exception ) {
			return $this->storage_error();
		}
		return $this->get( $id );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	private function order_input( array $input ) {
		$data = array();
		foreach ( array(
			'supplier_reference' => 190,
			'notes'              => 10000,
		) as $field => $maximum ) {
			if ( array_key_exists( $field, $input ) ) {
				$value = 'notes' === $field ? sanitize_textarea_field( $input[ $field ] ) : sanitize_text_field( $input[ $field ] );
				if ( strlen( $value ) > $maximum ) {
					return $this->error( 'stockino_purchase_order_field_too_long', 'A purchase-order field exceeds its supported length.', 400 );
				}
				$data[ $field ] = '' === $value ? null : $value;
			}
		}
		foreach ( array( 'order_date', 'expected_date' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$date = $this->date( (string) $input[ $field ] );
				if ( '' !== (string) $input[ $field ] && null === $date ) {
					return $this->error( 'stockino_invalid_purchase_order_date', 'Use a valid YYYY-MM-DD date.', 400 );
				}
				$data[ $field ] = $date;
			}
		}
		return $data;
	}

	private function date( string $value ): ?string {
		if ( '' === $value ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
	}

	private function notes( mixed $value ): ?string {
		$notes = sanitize_textarea_field( (string) $value );
		return '' === $notes ? null : substr( $notes, 0, 10000 );
	}

	private function not_found(): WP_Error {
		return $this->error( 'stockino_purchase_order_not_found', 'The purchase order does not exist.', 404 );
	}

	private function locked(): WP_Error {
		return $this->error( 'stockino_purchase_order_locked', 'Purchase-order lines are locked after the order leaves draft status.', 409 );
	}

	private function quantity_error(): WP_Error {
		return $this->error( 'stockino_invalid_purchase_quantity', 'Enter a positive quantity within DECIMAL(20,6) precision.', 400 );
	}

	private function storage_error(): WP_Error {
		return $this->error( 'stockino_purchase_order_storage_failed', 'The purchase order could not be saved.', 500 );
	}

	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
