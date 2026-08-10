<?php

namespace Stockino\Database;

final class SupplierRepository {
	/** @param array<string,mixed> $data */
	public function create( array $data ): int {
		global $wpdb;
		$now       = current_time( 'mysql', true );
		$data      = array_merge(
			$data,
			array(
				'created_by' => get_current_user_id() ? get_current_user_id() : null,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$previous  = $wpdb->suppress_errors( true );
		$inserted  = $wpdb->insert( $this->table(), $data );
		$duplicate = $this->is_duplicate_error();
		$wpdb->suppress_errors( $previous );
		if ( false === $inserted ) {
			throw new \RuntimeException( $duplicate ? 'duplicate_code' : 'supplier_insert_failed' );
		}
		return (int) $wpdb->insert_id;
	}

	/** @param array<string,mixed> $data */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$previous           = $wpdb->suppress_errors( true );
		$result             = $wpdb->update( $this->table(), $data, array( 'id' => $id ) );
		$duplicate          = $this->is_duplicate_error();
		$wpdb->suppress_errors( $previous );
		if ( false === $result ) {
			throw new \RuntimeException( $duplicate ? 'duplicate_code' : 'supplier_update_failed' );
		}
		return $result > 0;
	}

	/** @return array<string,mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT s.*, COUNT(sp.id) linked_product_count FROM %i s LEFT JOIN %i sp ON sp.supplier_id = s.id WHERE s.id = %d GROUP BY s.id',
				$this->table(),
				$this->relations_table(),
				$id
			),
			ARRAY_A
		);
		return $row ? $this->format( $row, true ) : null;
	}

	public function code_exists( string $code, int $exclude_id = 0 ): bool {
		global $wpdb;
		$sql    = 'SELECT COUNT(*) FROM %i WHERE code = %s';
		$values = array( $this->table(), $code );
		if ( $exclude_id > 0 ) {
			$sql     .= ' AND id <> %d';
			$values[] = $exclude_id;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains fixed clauses and prepared values only.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) ) > 0;
	}

	/** @return array<string,mixed>|null */
	public function find_by_code( string $code ): ?array {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE code = %s', $this->table(), $code ) );
		return $id > 0 ? $this->find( $id ) : null;
	}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function paginate( int $page, int $per_page, string $search, string $status, ?bool $has_products ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$values = array();
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(s.name LIKE %s OR s.code LIKE %s OR s.contact_name LIKE %s OR s.email LIKE %s OR s.phone LIKE %s)';
			$values  = array_merge( $values, array_fill( 0, 5, $like ) );
		}
		if ( in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$where[]  = 's.status = %s';
			$values[] = $status;
		}
		if ( null !== $has_products ) {
			$where[]  = $has_products ? 'EXISTS (SELECT 1 FROM %i hp WHERE hp.supplier_id = s.id)' : 'NOT EXISTS (SELECT 1 FROM %i hp WHERE hp.supplier_id = s.id)';
			$values[] = $this->relations_table();
		}
		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM %i s WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains fixed clauses and prepared values only.
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, array_merge( array( $this->table() ), $values ) ) );
		$list_sql  = "SELECT s.*, COUNT(sp.id) linked_product_count FROM %i s LEFT JOIN %i sp ON sp.supplier_id = s.id WHERE {$where_sql} GROUP BY s.id ORDER BY s.name ASC, s.id ASC LIMIT %d OFFSET %d";
		$list_args = array_merge( array( $this->table(), $this->relations_table() ), $values, array( $per_page, ( $page - 1 ) * $per_page ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains fixed clauses and prepared values only.
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ), ARRAY_A );

		return array(
			'items'      => array_map( fn( array $row ): array => $this->format( $row, false ), $rows ),
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
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(status = 'active') active_suppliers, SUM(status = 'inactive') inactive_suppliers,
				(SELECT COUNT(*) FROM %i) linked_products,
				(SELECT COUNT(DISTINCT product_id) FROM %i) products_with_suppliers FROM %i",
				$this->relations_table(),
				$this->relations_table(),
				$this->table()
			),
			ARRAY_A
		);
		return array(
			'active_suppliers'        => (int) ( $row['active_suppliers'] ?? 0 ),
			'inactive_suppliers'      => (int) ( $row['inactive_suppliers'] ?? 0 ),
			'linked_products'         => (int) ( $row['linked_products'] ?? 0 ),
			'products_with_suppliers' => (int) ( $row['products_with_suppliers'] ?? 0 ),
		);
	}

	/** @return array<string,mixed> */
	private function format( array $row, bool $detail ): array {
		$data = array(
			'id'                   => (int) $row['id'],
			'name'                 => (string) $row['name'],
			'code'                 => null !== $row['code'] ? (string) $row['code'] : null,
			'status'               => (string) $row['status'],
			'contact_name'         => null !== $row['contact_name'] ? (string) $row['contact_name'] : null,
			'phone'                => null !== $row['phone'] ? (string) $row['phone'] : null,
			'email'                => null !== $row['email'] ? (string) $row['email'] : null,
			'lead_time_days'       => null !== $row['lead_time_days'] ? (int) $row['lead_time_days'] : null,
			'linked_product_count' => (int) ( $row['linked_product_count'] ?? 0 ),
			'updated_at'           => $this->iso_date( (string) $row['updated_at'] ),
		);
		if ( $detail ) {
			$data += array(
				'website'    => null !== $row['website'] ? (string) $row['website'] : null,
				'address'    => null !== $row['address'] ? (string) $row['address'] : null,
				'notes'      => null !== $row['notes'] ? (string) $row['notes'] : null,
				'created_at' => $this->iso_date( (string) $row['created_at'] ),
			);
		}
		return $data;
	}

	private function iso_date( string $value ): string {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date ? $date->format( DATE_RFC3339 ) : $value;
	}

	private function is_duplicate_error(): bool {
		global $wpdb;
		return str_contains( strtolower( (string) $wpdb->last_error ), 'duplicate' );
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stockino_suppliers';
	}

	private function relations_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stockino_supplier_products';
	}
}
