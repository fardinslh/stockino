<?php

namespace Stockino\Database;

final class SupplierProductRepository {
	/** @param array<string,mixed> $data */
	public function create( array $data ): int {
		global $wpdb;
		$now       = current_time( 'mysql', true );
		$data      = array_merge(
			$data,
			array(
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$previous  = $wpdb->suppress_errors( true );
		$inserted  = $wpdb->insert( $this->table(), $data );
		$duplicate = $this->is_duplicate_error();
		$wpdb->suppress_errors( $previous );
		if ( false === $inserted ) {
			throw new \RuntimeException( $duplicate ? 'duplicate_relation' : 'relation_insert_failed' );
		}
		return (int) $wpdb->insert_id;
	}

	/** @param array<string,mixed> $data */
	public function update( int $supplier_id, int $product_id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$result             = $wpdb->update(
			$this->table(),
			$data,
			array(
				'supplier_id' => $supplier_id,
				'product_id'  => $product_id,
			)
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'relation_update_failed' );
		}
		return $result > 0;
	}

	public function delete( int $supplier_id, int $product_id ): bool {
		global $wpdb;
		return (int) $wpdb->delete(
			$this->table(),
			array(
				'supplier_id' => $supplier_id,
				'product_id'  => $product_id,
			)
		) > 0;
	}

	/** @return array<string,mixed>|null */
	public function find( int $supplier_id, int $product_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE supplier_id = %d AND product_id = %d', $this->table(), $supplier_id, $product_id ), ARRAY_A );
		return $row ? $this->format( $row ) : null;
	}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function paginate_for_supplier( int $supplier_id, int $page, int $per_page, string $search = '' ): array {
		global $wpdb;
		$where  = 'sp.supplier_id = %d';
		$values = array( $supplier_id );
		if ( '' !== $search ) {
			$where   .= ' AND (p.post_title LIKE %s OR sp.supplier_sku LIKE %s OR p.ID = %d OR p.ID = %d)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = ctype_digit( $search ) ? (int) $search : 0;
			$values[] = wc_get_product_id_by_sku( $search );
		}
		return $this->paginate( $where, $values, $page, $per_page );
	}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function paginate_for_product( int $product_id, int $page, int $per_page ): array {
		return $this->paginate( 'sp.product_id = %d', array( $product_id ), $page, $per_page, true );
	}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	private function paginate( string $where, array $values, int $page, int $per_page, bool $include_supplier = false ): array {
		global $wpdb;
		$count_sql = "SELECT COUNT(*) FROM %i sp INNER JOIN %i p ON p.ID = sp.product_id WHERE {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains fixed clauses and prepared values only.
		$total  = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, array_merge( array( $this->table(), $wpdb->posts ), $values ) ) );
		$select = 'sp.*';
		$join   = '';
		$args   = array( $this->table(), $wpdb->posts );
		if ( $include_supplier ) {
			$select .= ', s.name supplier_name, s.code supplier_code, s.status supplier_status, s.lead_time_days supplier_lead_time_days';
			$join    = ' INNER JOIN %i s ON s.id = sp.supplier_id';
			$args[]  = $this->suppliers_table();
		}
		$list_sql = "SELECT {$select} FROM %i sp INNER JOIN %i p ON p.ID = sp.product_id{$join} WHERE {$where} ORDER BY sp.updated_at DESC, sp.id DESC LIMIT %d OFFSET %d";
		$args     = array_merge( $args, $values, array( $per_page, ( $page - 1 ) * $per_page ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains fixed clauses and prepared values only.
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $args ), ARRAY_A );

		return array(
			'items'      => array_map( array( $this, 'format' ), $rows ),
			'pagination' => array(
				'current_page' => $page,
				'per_page'     => $per_page,
				'total_items'  => $total,
				'total_pages'  => (int) ceil( $total / $per_page ),
			),
		);
	}

	/** @return array<string,mixed> */
	private function format( array $row ): array {
		$data = array(
			'id'                     => (int) $row['id'],
			'supplier_id'            => (int) $row['supplier_id'],
			'product_id'             => (int) $row['product_id'],
			'supplier_sku'           => null !== $row['supplier_sku'] ? (string) $row['supplier_sku'] : null,
			'lead_time_days'         => null !== $row['lead_time_days'] ? (int) $row['lead_time_days'] : null,
			'minimum_order_quantity' => null !== $row['minimum_order_quantity'] ? (string) $row['minimum_order_quantity'] : null,
			'order_multiple'         => null !== $row['order_multiple'] ? (string) $row['order_multiple'] : null,
			'notes'                  => null !== $row['notes'] ? (string) $row['notes'] : null,
			'created_at'             => $this->iso_date( (string) $row['created_at'] ),
			'updated_at'             => $this->iso_date( (string) $row['updated_at'] ),
		);
		if ( isset( $row['supplier_name'] ) ) {
			$data += array(
				'supplier_name'            => (string) $row['supplier_name'],
				'supplier_code'            => null !== $row['supplier_code'] ? (string) $row['supplier_code'] : null,
				'supplier_status'          => (string) $row['supplier_status'],
				'effective_lead_time_days' => null !== $row['lead_time_days'] ? (int) $row['lead_time_days'] : ( null !== $row['supplier_lead_time_days'] ? (int) $row['supplier_lead_time_days'] : null ),
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
		return $wpdb->prefix . 'stockino_supplier_products';
	}

	private function suppliers_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stockino_suppliers';
	}
}
