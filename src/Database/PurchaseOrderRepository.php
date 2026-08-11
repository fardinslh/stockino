<?php

namespace Stockino\Database;

use Stockino\Suppliers\PurchasingQuantity;

final class PurchaseOrderRepository {
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
		$temporary = 'PO-PENDING-' . wp_generate_uuid4();
		$data      = array_merge(
			$data,
			array(
				'po_number'  => $temporary,
				'status'     => 'draft',
				'created_by' => get_current_user_id() > 0 ? get_current_user_id() : null,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( false === $wpdb->insert( $this->orders_table(), $data ) ) {
			throw new \RuntimeException( 'purchase_order_insert_failed' );
		}
		$id        = (int) $wpdb->insert_id;
		$po_number = sprintf( 'PO-%06d', $id );
		try {
			$finalized = ( $this->number_finalizer )( $this->orders_table(), $id, 'po_number', $po_number );
		} catch ( \Throwable $exception ) {
			$finalized = false;
		}
		if ( ! $finalized ) {
			$deleted = $wpdb->delete(
				$this->orders_table(),
				array(
					'id'        => $id,
					'po_number' => $temporary,
					'status'    => 'draft',
				)
			);
			throw new \RuntimeException( 1 === $deleted ? 'purchase_order_number_failed' : 'purchase_order_number_ambiguous' );
		}
		return $id;
	}

	/** @param array<string,mixed> $data */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$result             = $wpdb->update( $this->orders_table(), $data, array( 'id' => $id ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'purchase_order_update_failed' );
		}
		return $result > 0;
	}

	/** @return array<string,mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT po.*, COUNT(i.id) item_count,
				COALESCE(SUM(i.ordered_quantity), 0) ordered_units,
				COALESCE(SUM(i.received_quantity), 0) received_units
				FROM %i po LEFT JOIN %i i ON i.purchase_order_id = po.id
				WHERE po.id = %d GROUP BY po.id',
				$this->orders_table(),
				$this->items_table(),
				$id
			),
			ARRAY_A
		);
		return $row ? $this->format_order( $row ) : null;
	}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function paginate( int $page, int $per_page, string $search, string $status, int $supplier_id, string $date_from, string $date_to ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$values = array();
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(po.po_number LIKE %s OR po.supplier_name_snapshot LIKE %s OR po.supplier_code_snapshot LIKE %s OR po.supplier_reference LIKE %s)';
			$values  = array_merge( $values, array( $like, $like, $like, $like ) );
		}
		if ( in_array( $status, array( 'draft', 'ordered', 'partially_received', 'received', 'cancelled' ), true ) ) {
			$where[]  = 'po.status = %s';
			$values[] = $status;
		}
		if ( $supplier_id > 0 ) {
			$where[]  = 'po.supplier_id = %d';
			$values[] = $supplier_id;
		}
		if ( '' !== $date_from ) {
			$where[]  = 'po.order_date >= %s';
			$values[] = $date_from;
		}
		if ( '' !== $date_to ) {
			$where[]  = 'po.order_date <= %s';
			$values[] = $date_to;
		}
		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM %i po WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed clauses and prepared values only.
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, array_merge( array( $this->orders_table() ), $values ) ) );
		$list  = "SELECT po.*, COUNT(i.id) item_count,
			COALESCE(SUM(i.ordered_quantity), 0) ordered_units,
			COALESCE(SUM(i.received_quantity), 0) received_units
			FROM %i po LEFT JOIN %i i ON i.purchase_order_id = po.id
			WHERE {$where_sql} GROUP BY po.id ORDER BY po.created_at DESC, po.id DESC LIMIT %d OFFSET %d";
		$args  = array_merge( array( $this->orders_table(), $this->items_table() ), $values, array( $per_page, ( $page - 1 ) * $per_page ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed clauses and prepared values only.
		$rows = $wpdb->get_results( $wpdb->prepare( $list, $args ), ARRAY_A );
		return array(
			'items'      => array_map( array( $this, 'format_order' ), $rows ),
			'pagination' => array(
				'current_page' => $page,
				'per_page'     => $per_page,
				'total_items'  => $total,
				'total_pages'  => (int) ceil( $total / $per_page ),
			),
		);
	}

	/** @return array<string,int> */
	public function stats(): array {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(status = 'draft') drafts, SUM(status = 'ordered') ordered,
				SUM(status = 'partially_received') partially_received, SUM(status = 'received') received,
				SUM(status IN ('ordered','partially_received') AND expected_date < %s) overdue FROM %i",
				$today,
				$this->orders_table()
			),
			ARRAY_A
		);
		return array(
			'drafts'             => (int) ( $row['drafts'] ?? 0 ),
			'ordered'            => (int) ( $row['ordered'] ?? 0 ),
			'partially_received' => (int) ( $row['partially_received'] ?? 0 ),
			'received'           => (int) ( $row['received'] ?? 0 ),
			'overdue'            => (int) ( $row['overdue'] ?? 0 ),
		);
	}

	/** @param array<string,mixed> $data */
	public function create_item( array $data ): int {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array_merge(
			$data,
			array(
				'received_quantity' => '0.000000',
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$old  = $wpdb->suppress_errors( true );
		$ok   = $wpdb->insert( $this->items_table(), $data );
		$dupe = str_contains( strtolower( (string) $wpdb->last_error ), 'duplicate' );
		$wpdb->suppress_errors( $old );
		if ( false === $ok ) {
			throw new \RuntimeException( $dupe ? 'duplicate_purchase_order_item' : 'purchase_order_item_insert_failed' );
		}
		return (int) $wpdb->insert_id;
	}

	/** @param array<string,mixed> $data */
	public function update_item( int $item_id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$result             = $wpdb->update( $this->items_table(), $data, array( 'id' => $item_id ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'purchase_order_item_update_failed' );
		}
		return $result > 0;
	}

	public function delete_item( int $order_id, int $item_id ): bool {
		global $wpdb;
		return (int) $wpdb->delete(
			$this->items_table(),
			array(
				'id'                => $item_id,
				'purchase_order_id' => $order_id,
			)
		) > 0;
	}

	/** @return array<string,mixed>|null */
	public function find_item( int $order_id, int $item_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND purchase_order_id = %d', $this->items_table(), $item_id, $order_id ), ARRAY_A );
		return $row ? $this->format_item( $row ) : null;
	}

	/** @return array<int,array<string,mixed>> */
	public function items( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE purchase_order_id = %d ORDER BY id ASC', $this->items_table(), $order_id ), ARRAY_A );
		return array_map( array( $this, 'format_item' ), $rows );
	}

	public function increment_received( int $item_id, string $quantity ): bool {
		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET received_quantity = received_quantity + %s, updated_at = %s WHERE id = %d AND received_quantity + %s <= ordered_quantity',
				$this->items_table(),
				$quantity,
				current_time( 'mysql', true ),
				$item_id,
				$quantity
			)
		);
		return 1 === $result;
	}

	/** @return array<string,mixed> */
	private function format_order( array $row ): array {
		$ordered  = PurchasingQuantity::normalize_nonnegative( $row['ordered_units'] ?? '0' ) ?? '0.000000';
		$received = PurchasingQuantity::normalize_nonnegative( $row['received_units'] ?? '0' ) ?? '0.000000';
		return array(
			'id'                 => (int) $row['id'],
			'po_number'          => (string) $row['po_number'],
			'supplier_id'        => (int) $row['supplier_id'],
			'supplier_name'      => (string) $row['supplier_name_snapshot'],
			'supplier_code'      => null !== $row['supplier_code_snapshot'] ? (string) $row['supplier_code_snapshot'] : null,
			'status'             => (string) $row['status'],
			'supplier_reference' => null !== $row['supplier_reference'] ? (string) $row['supplier_reference'] : null,
			'order_date'         => $row['order_date'] ? $row['order_date'] : null,
			'expected_date'      => $row['expected_date'] ? $row['expected_date'] : null,
			'notes'              => null !== $row['notes'] ? (string) $row['notes'] : null,
			'item_count'         => (int) ( $row['item_count'] ?? 0 ),
			'ordered_units'      => $ordered,
			'received_units'     => $received,
			'remaining_units'    => PurchasingQuantity::subtract( $ordered, $received ),
			'created_at'         => $this->iso_date( (string) $row['created_at'] ),
			'updated_at'         => $this->iso_date( (string) $row['updated_at'] ),
			'ordered_at'         => $row['ordered_at'] ? $this->iso_date( (string) $row['ordered_at'] ) : null,
			'completed_at'       => $row['completed_at'] ? $this->iso_date( (string) $row['completed_at'] ) : null,
			'cancelled_at'       => $row['cancelled_at'] ? $this->iso_date( (string) $row['cancelled_at'] ) : null,
			'currency_snapshot'  => $row['currency_snapshot'] ? (string) $row['currency_snapshot'] : get_woocommerce_currency(),
		);
	}

	/** @return array<string,mixed> */
	private function format_item( array $row ): array {
		$ordered  = PurchasingQuantity::normalize_nonnegative( $row['ordered_quantity'] ) ?? '0.000000';
		$received = PurchasingQuantity::normalize_nonnegative( $row['received_quantity'] ) ?? '0.000000';
		return array(
			'id'                 => (int) $row['id'],
			'purchase_order_id'  => (int) $row['purchase_order_id'],
			'product_id'         => (int) $row['product_id'],
			'product_name'       => (string) $row['product_name_snapshot'],
			'sku'                => null !== $row['sku_snapshot'] ? (string) $row['sku_snapshot'] : null,
			'product_type'       => (string) $row['product_type_snapshot'],
			'variation'          => null !== $row['variation_snapshot'] ? (string) $row['variation_snapshot'] : null,
			'supplier_sku'       => null !== $row['supplier_sku_snapshot'] ? (string) $row['supplier_sku_snapshot'] : null,
			'ordered_quantity'   => $ordered,
			'received_quantity'  => $received,
			'remaining_quantity' => PurchasingQuantity::subtract( $ordered, $received ),
			'ordered_unit_cost'  => null !== $row['ordered_unit_cost'] ? (string) $row['ordered_unit_cost'] : null,
			'notes'              => null !== $row['notes'] ? (string) $row['notes'] : null,
			'created_at'         => $this->iso_date( (string) $row['created_at'] ),
			'updated_at'         => $this->iso_date( (string) $row['updated_at'] ),
		);
	}

	private function iso_date( string $value ): string {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date ? $date->format( DATE_RFC3339 ) : $value;
	}

	private function orders_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stockino_purchase_orders';
	}

	private function items_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stockino_purchase_order_items';
	}
}
