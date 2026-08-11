<?php

namespace Stockino\Database;

use Stockino\Suppliers\PurchasingQuantity;

final class PurchaseReceiptRepository {
	private readonly \Closure $number_finalizer;

	public function __construct( ?\Closure $number_finalizer = null ) {
		$this->number_finalizer = $number_finalizer ?? static function ( string $table, int $id, string $column, string $number ): bool {
			global $wpdb;
			return false !== $wpdb->update( $table, array( $column => $number ), array( 'id' => $id ) );
		};
	}

	/** @param array<string,mixed> $data */
	public function create( array $data ): int {
		global $wpdb;
		$now       = current_time( 'mysql', true );
		$temporary = 'RCV-PENDING-' . wp_generate_uuid4();
		$data      = array_merge(
			$data,
			array(
				'receipt_number' => $temporary,
				'status'         => 'processing',
				'created_by'     => get_current_user_id() > 0 ? get_current_user_id() : null,
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$old       = $wpdb->suppress_errors( true );
		$ok        = $wpdb->insert( $this->receipts_table(), $data );
		$dupe      = str_contains( strtolower( (string) $wpdb->last_error ), 'duplicate' );
		$wpdb->suppress_errors( $old );
		if ( false === $ok ) {
			throw new \RuntimeException( $dupe ? 'duplicate_idempotency_key' : 'receipt_insert_failed' );
		}
		$id = (int) $wpdb->insert_id;
		try {
			$finalized = ( $this->number_finalizer )( $this->receipts_table(), $id, 'receipt_number', sprintf( 'RCV-%06d', $id ) );
		} catch ( \Throwable $exception ) {
			$finalized = false;
		}
		if ( ! $finalized ) {
			$deleted = $wpdb->delete(
				$this->receipts_table(),
				array(
					'id'             => $id,
					'receipt_number' => $temporary,
					'status'         => 'processing',
				)
			);
			if ( 1 !== $deleted ) {
				$wpdb->update(
					$this->receipts_table(),
					array( 'status' => 'requires_attention' ),
					array(
						'id'     => $id,
						'status' => 'processing',
					)
				);
			}
			throw new \RuntimeException( 1 === $deleted ? 'receipt_number_failed' : 'receipt_number_ambiguous' );
		}
		return $id;
	}

	/** @param array<string,mixed> $data */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$result             = $wpdb->update( $this->receipts_table(), $data, array( 'id' => $id ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'receipt_update_failed' );
		}
		return $result > 0;
	}

	/** @return array<string,mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.*, COUNT(ri.id) item_count,
				COALESCE(SUM(CASE WHEN ri.status = 'completed' THEN ri.quantity_received ELSE 0 END), 0) confirmed_units,
				COALESCE(SUM(CASE WHEN ri.status = 'requires_attention' THEN ri.quantity_received ELSE 0 END), 0) attention_units,
				COALESCE(SUM(CASE WHEN ri.status = 'failed' THEN ri.quantity_received ELSE 0 END), 0) failed_units
				FROM %i r LEFT JOIN %i ri ON ri.receipt_id = r.id WHERE r.id = %d GROUP BY r.id",
				$this->receipts_table(),
				$this->receipt_items_table(),
				$id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$receipt          = $this->format_receipt( $row );
		$receipt['items'] = $this->items( $id );
		return $receipt;
	}

	/** @return array<string,mixed>|null */
	public function find_by_key( string $key ): ?array {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE idempotency_key = %s', $this->receipts_table(), $key ) );
		return $id > 0 ? $this->find( $id ) : null;
	}

	public function delete_processing( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->receipt_items_table(), array( 'receipt_id' => $id ) );
		$wpdb->delete(
			$this->receipts_table(),
			array(
				'id'     => $id,
				'status' => 'processing',
			)
		);
	}

	/** @param array<string,mixed> $data */
	public function create_item( array $data ): int {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array_merge(
			$data,
			array(
				'status'     => 'pending',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( false === $wpdb->insert( $this->receipt_items_table(), $data ) ) {
			throw new \RuntimeException( 'receipt_item_insert_failed' );
		}
		return (int) $wpdb->insert_id;
	}

	/** @param array<string,mixed> $data */
	public function update_item( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$result             = $wpdb->update( $this->receipt_items_table(), $data, array( 'id' => $id ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'receipt_item_update_failed' );
		}
		return $result > 0;
	}

	/** @return array<int,array<string,mixed>> */
	public function items( int $receipt_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE receipt_id = %d ORDER BY id ASC', $this->receipt_items_table(), $receipt_id ), ARRAY_A );
		return array_map( array( $this, 'format_item' ), $rows );
	}

	public function accounted_quantity( int $purchase_order_item_id ): string {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(quantity_received), 0) FROM %i WHERE purchase_order_item_id = %d AND status IN ('completed', 'requires_attention')",
				$this->receipt_items_table(),
				$purchase_order_item_id
			)
		);
		return PurchasingQuantity::normalize_nonnegative( $value ?? '0' ) ?? '0.000000';
	}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function paginate_for_order( int $order_id, int $page, int $per_page ): array {
		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE purchase_order_id = %d', $this->receipts_table(), $order_id ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, COUNT(ri.id) item_count,
				COALESCE(SUM(CASE WHEN ri.status = 'completed' THEN ri.quantity_received ELSE 0 END), 0) confirmed_units,
				COALESCE(SUM(CASE WHEN ri.status = 'requires_attention' THEN ri.quantity_received ELSE 0 END), 0) attention_units,
				COALESCE(SUM(CASE WHEN ri.status = 'failed' THEN ri.quantity_received ELSE 0 END), 0) failed_units
				FROM %i r LEFT JOIN %i ri ON ri.receipt_id = r.id
				WHERE r.purchase_order_id = %d GROUP BY r.id ORDER BY r.created_at DESC, r.id DESC LIMIT %d OFFSET %d",
				$this->receipts_table(),
				$this->receipt_items_table(),
				$order_id,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		return array(
			'items'      => array_map( array( $this, 'format_receipt' ), $rows ),
			'pagination' => array(
				'current_page' => $page,
				'per_page'     => $per_page,
				'total_items'  => $total,
				'total_pages'  => (int) ceil( $total / $per_page ),
			),
		);
	}

	/** @return array<string,mixed> */
	private function format_receipt( array $row ): array {
		return array(
			'id'                => (int) $row['id'],
			'receipt_number'    => (string) $row['receipt_number'],
			'purchase_order_id' => (int) $row['purchase_order_id'],
			'idempotency_key'   => (string) $row['idempotency_key'],
			'status'            => (string) $row['status'],
			'note'              => null !== $row['note'] ? (string) $row['note'] : null,
			'created_by'        => $row['created_by'] ? (int) $row['created_by'] : null,
			'item_count'        => (int) ( $row['item_count'] ?? 0 ),
			'received_units'    => PurchasingQuantity::normalize_nonnegative( $row['confirmed_units'] ?? '0' ) ?? '0.000000',
			'confirmed_units'   => PurchasingQuantity::normalize_nonnegative( $row['confirmed_units'] ?? '0' ) ?? '0.000000',
			'attention_units'   => PurchasingQuantity::normalize_nonnegative( $row['attention_units'] ?? '0' ) ?? '0.000000',
			'failed_units'      => PurchasingQuantity::normalize_nonnegative( $row['failed_units'] ?? '0' ) ?? '0.000000',
			'created_at'        => $this->iso_date( (string) $row['created_at'] ),
			'completed_at'      => $row['completed_at'] ? $this->iso_date( (string) $row['completed_at'] ) : null,
			'updated_at'        => $this->iso_date( (string) $row['updated_at'] ),
		);
	}

	/** @return array<string,mixed> */
	private function format_item( array $row ): array {
		return array(
			'id'                     => (int) $row['id'],
			'receipt_id'             => (int) $row['receipt_id'],
			'purchase_order_item_id' => (int) $row['purchase_order_item_id'],
			'product_id'             => (int) $row['product_id'],
			'stock_owner_id'         => (int) $row['stock_owner_id'],
			'quantity_received'      => PurchasingQuantity::normalize_nonnegative( $row['quantity_received'] ) ?? '0.000000',
			'quantity_before'        => null !== $row['quantity_before'] ? PurchasingQuantity::normalize_nonnegative( $row['quantity_before'] ) : null,
			'quantity_after'         => null !== $row['quantity_after'] ? PurchasingQuantity::normalize_nonnegative( $row['quantity_after'] ) : null,
			'movement_id'            => $row['movement_id'] ? (int) $row['movement_id'] : null,
			'status'                 => (string) $row['status'],
			'error_code'             => null !== $row['error_code'] ? (string) $row['error_code'] : null,
			'error_message'          => null !== $row['error_message'] ? (string) $row['error_message'] : null,
			'created_at'             => $this->iso_date( (string) $row['created_at'] ),
			'updated_at'             => $this->iso_date( (string) $row['updated_at'] ),
		);
	}

	private function iso_date( string $value ): string {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date ? $date->format( DATE_RFC3339 ) : $value;
	}

	private function receipts_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stockino_purchase_receipts';
	}

	private function receipt_items_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stockino_purchase_receipt_items';
	}
}
