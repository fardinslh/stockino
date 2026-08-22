<?php

namespace Stockino\Database;

final class MarketplaceRepository {
	private string $connections_table;
	private string $products_table;
	private string $logs_table;

	public function __construct() {
		global $wpdb;
		$this->connections_table = $wpdb->prefix . 'stockino_marketplace_connections';
		$this->products_table    = $wpdb->prefix . 'stockino_marketplace_products';
		$this->logs_table        = $wpdb->prefix . 'stockino_marketplace_logs';
	}

	/** @return array<int, array<string, mixed>> */
	public function get_connections(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $this->connections_table ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string, mixed>|null */
	public function get_connection( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->connections_table, $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string, mixed>|null */
	public function get_connection_by_marketplace( string $marketplace ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE marketplace = %s', $this->connections_table, $marketplace ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array{
	 *   marketplace: string,
	 *   name: string,
	 *   status?: string,
	 *   credentials?: string|null,
	 *   vendor_id?: string|null,
	 *   vendor_name?: string|null,
	 *   vendor_identifier?: string|null,
	 *   preparation_days?: int
	 * } $data
	 */
	public function save_connection( array $data ): int {
		global $wpdb;
		$now      = gmdate( 'Y-m-d H:i:s' );
		$existing = $this->get_connection_by_marketplace( $data['marketplace'] );

		if ( $existing ) {
			$wpdb->update(
				$this->connections_table,
				array(
					'name'              => $data['name'],
					'status'            => $data['status'] ?? $existing['status'],
					'credentials'       => array_key_exists( 'credentials', $data ) ? $data['credentials'] : $existing['credentials'],
					'vendor_id'         => array_key_exists( 'vendor_id', $data ) ? $data['vendor_id'] : $existing['vendor_id'],
					'vendor_name'       => array_key_exists( 'vendor_name', $data ) ? $data['vendor_name'] : $existing['vendor_name'],
					'vendor_identifier' => array_key_exists( 'vendor_identifier', $data ) ? $data['vendor_identifier'] : $existing['vendor_identifier'],
					'preparation_days'  => (int) ( $data['preparation_days'] ?? $existing['preparation_days'] ?? 1 ),
					'updated_at'        => $now,
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
			return (int) $existing['id'];
		}

		$wpdb->insert(
			$this->connections_table,
			array(
				'marketplace'       => $data['marketplace'],
				'name'              => $data['name'],
				'status'            => $data['status'] ?? 'disconnected',
				'credentials'       => $data['credentials'] ?? null,
				'vendor_id'         => $data['vendor_id'] ?? null,
				'vendor_name'       => $data['vendor_name'] ?? null,
				'vendor_identifier' => $data['vendor_identifier'] ?? null,
				'preparation_days'  => (int) ( $data['preparation_days'] ?? 1 ),
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function update_connection_status( int $id, string $status, ?string $vendor_id = null, ?string $vendor_name = null, ?string $vendor_identifier = null ): void {
		global $wpdb;
		$fields  = array(
			'status'     => $status,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$formats = array( '%s', '%s' );

		if ( null !== $vendor_id ) {
			$fields['vendor_id'] = $vendor_id;
			$formats[]           = '%s';
		}
		if ( null !== $vendor_name ) {
			$fields['vendor_name'] = $vendor_name;
			$formats[]             = '%s';
		}
		if ( null !== $vendor_identifier ) {
			$fields['vendor_identifier'] = $vendor_identifier;
			$formats[]                   = '%s';
		}

		$wpdb->update(
			$this->connections_table,
			$fields,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
	}

	/** @return array<string, mixed>|null */
	public function get_product_link( int $connection_id, int $product_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE connection_id = %d AND product_id = %d',
				$this->products_table,
				$connection_id,
				$product_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function get_published_links_for_product( int $product_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, c.marketplace as marketplace_kind, c.credentials, c.vendor_id as conn_vendor_id, c.preparation_days, c.status as conn_status FROM %i p INNER JOIN %i c ON p.connection_id = c.id WHERE p.product_id = %d AND p.status = 'published' AND c.status = 'active'",
				$this->products_table,
				$this->connections_table,
				$product_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array{
	 *   connection_id: int,
	 *   product_id: int,
	 *   parent_id?: int|null,
	 *   marketplace: string,
	 *   external_product_id?: string|null,
	 *   external_vendor_id?: string|null,
	 *   status?: string,
	 *   category_external_id?: string|null,
	 *   auto_sync_stock?: int,
	 *   last_published_at?: string|null,
	 *   last_synced_at?: string|null,
	 *   last_error_message?: string|null
	 * } $data
	 */
	public function save_product_link( array $data ): int {
		global $wpdb;
		$now      = gmdate( 'Y-m-d H:i:s' );
		$existing = $this->get_product_link( (int) $data['connection_id'], (int) $data['product_id'] );

		if ( $existing ) {
			$fields = array(
				'parent_id'            => $data['parent_id'] ?? $existing['parent_id'],
				'external_product_id'  => array_key_exists( 'external_product_id', $data ) ? $data['external_product_id'] : $existing['external_product_id'],
				'external_vendor_id'   => array_key_exists( 'external_vendor_id', $data ) ? $data['external_vendor_id'] : $existing['external_vendor_id'],
				'status'               => $data['status'] ?? $existing['status'],
				'category_external_id' => array_key_exists( 'category_external_id', $data ) ? $data['category_external_id'] : $existing['category_external_id'],
				'auto_sync_stock'      => array_key_exists( 'auto_sync_stock', $data ) ? (int) $data['auto_sync_stock'] : (int) $existing['auto_sync_stock'],
				'last_published_at'    => array_key_exists( 'last_published_at', $data ) ? $data['last_published_at'] : $existing['last_published_at'],
				'last_synced_at'       => array_key_exists( 'last_synced_at', $data ) ? $data['last_synced_at'] : $existing['last_synced_at'],
				'last_error_message'   => array_key_exists( 'last_error_message', $data ) ? $data['last_error_message'] : $existing['last_error_message'],
				'updated_at'           => $now,
			);
			$wpdb->update(
				$this->products_table,
				$fields,
				array( 'id' => (int) $existing['id'] )
			);
			return (int) $existing['id'];
		}

		$wpdb->insert(
			$this->products_table,
			array(
				'connection_id'        => (int) $data['connection_id'],
				'product_id'           => (int) $data['product_id'],
				'parent_id'            => $data['parent_id'] ?? null,
				'marketplace'          => $data['marketplace'],
				'external_product_id'  => $data['external_product_id'] ?? null,
				'external_vendor_id'   => $data['external_vendor_id'] ?? null,
				'status'               => $data['status'] ?? 'not_published',
				'category_external_id' => $data['category_external_id'] ?? null,
				'auto_sync_stock'      => (int) ( $data['auto_sync_stock'] ?? 1 ),
				'last_published_at'    => $data['last_published_at'] ?? null,
				'last_synced_at'       => $data['last_synced_at'] ?? null,
				'last_error_message'   => $data['last_error_message'] ?? null,
				'created_at'           => $now,
				'updated_at'           => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function update_product_status( int $connection_id, int $product_id, string $status, ?string $external_product_id = null, ?string $error_message = null ): void {
		global $wpdb;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$fields = array(
			'status'             => $status,
			'last_error_message' => $error_message,
			'updated_at'         => $now,
		);

		if ( 'published' === $status ) {
			$fields['last_published_at'] = $now;
			$fields['last_synced_at']    = $now;
		}

		if ( null !== $external_product_id ) {
			$fields['external_product_id'] = $external_product_id;
		}

		$existing = $this->get_product_link( $connection_id, $product_id );
		if ( $existing ) {
			$wpdb->update(
				$this->products_table,
				$fields,
				array( 'id' => (int) $existing['id'] )
			);
		} else {
			$conn = $this->get_connection( $connection_id );
			$this->save_product_link(
				array_merge(
					$fields,
					array(
						'connection_id' => $connection_id,
						'product_id'    => $product_id,
						'marketplace'   => $conn['marketplace'] ?? 'mock',
					)
				)
			);
		}
	}

	/**
	 * @param array{
	 *   page?: int,
	 *   per_page?: int,
	 *   search?: string,
	 *   status?: string,
	 *   marketplace?: string
	 * } $params
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
	 */
	public function list_publication_products( array $params ): array {
		global $wpdb;
		$page        = max( 1, (int) ( $params['page'] ?? 1 ) );
		$per_page    = min( 100, max( 1, (int) ( $params['per_page'] ?? 20 ) ) );
		$offset      = ( $page - 1 ) * $per_page;
		$search      = trim( (string) ( $params['search'] ?? '' ) );
		$status      = trim( (string) ( $params['status'] ?? '' ) );
		$marketplace = trim( (string) ( $params['marketplace'] ?? 'basalam' ) );

		$posts_table = $wpdb->posts;
		$where       = "WHERE p.post_type IN ('product', 'product_variation') AND p.post_status = 'publish'";
		$args        = array();

		if ( '' !== $search ) {
			$where .= ' AND (p.post_title LIKE %s OR p.ID = %d)';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
			$args[] = is_numeric( $search ) ? (int) $search : 0;
		}

		if ( '' !== $status ) {
			if ( 'not_published' === $status ) {
				$where .= " AND (mp.status IS NULL OR mp.status = 'not_published')";
			} else {
				$where .= ' AND mp.status = %s';
				$args[] = $status;
			}
		}

		$join = "LEFT JOIN {$this->products_table} mp ON p.ID = mp.product_id";
		if ( '' !== $marketplace ) {
			$join .= $wpdb->prepare( ' AND mp.marketplace = %s', $marketplace );
		}

		$count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$posts_table} p {$join} {$where}";
		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query uses trusted table names and fixed clauses; all values are prepared.
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query contains only trusted table names and fixed clauses.
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$query_sql  = "SELECT p.ID as product_id, p.post_title as product_name, p.post_parent as parent_id, p.post_type, mp.id as link_id, mp.marketplace, mp.status as publication_status, mp.external_product_id, mp.category_external_id, mp.auto_sync_stock, mp.last_published_at, mp.last_synced_at, mp.last_error_message FROM {$posts_table} p {$join} {$where} ORDER BY p.ID DESC LIMIT %d OFFSET %d";
		$query_args = array_merge( $args, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query uses trusted table names and fixed clauses; all values are prepared.
		$rows  = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_args ), ARRAY_A );
		$items = array();

		foreach ( ( is_array( $rows ) ? $rows : array() ) as $row ) {
			$wc_product = wc_get_product( (int) $row['product_id'] );
			if ( ! $wc_product ) {
				continue;
			}

			$items[] = array(
				'product_id'           => (int) $row['product_id'],
				'product_name'         => $wc_product->get_name(),
				'sku'                  => (string) $wc_product->get_sku(),
				'price'                => (string) $wc_product->get_price(),
				'regular_price'        => (string) $wc_product->get_regular_price(),
				'stock_quantity'       => $wc_product->get_stock_quantity(),
				'stock_status'         => $wc_product->get_stock_status(),
				'product_type'         => $wc_product->get_type(),
				'parent_id'            => (int) $row['parent_id'],
				'marketplace'          => $row['marketplace'] ?? $marketplace,
				'publication_status'   => $row['publication_status'] ?? 'not_published',
				'external_product_id'  => $row['external_product_id'] ?? null,
				'category_external_id' => $row['category_external_id'] ?? null,
				'auto_sync_stock'      => isset( $row['auto_sync_stock'] ) ? (bool) $row['auto_sync_stock'] : true,
				'last_published_at'    => $row['last_published_at'] ?? null,
				'last_synced_at'       => $row['last_synced_at'] ?? null,
				'last_error_message'   => $row['last_error_message'] ?? null,
			);
		}

			return array(
				'items'        => $items,
				'total'        => $total,
				'total_items'  => $total,
				'page'         => $page,
				'current_page' => $page,
				'per_page'     => $per_page,
				'total_pages'  => max( 1, (int) ceil( $total / $per_page ) ),
			);
	}

	/** @return array{total: int, published: int, not_published: int, in_progress: int, failed: int} */
	public function get_stats(): array {
		global $wpdb;
		$posts_table = $wpdb->posts;
		$total_wc    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM %i WHERE post_type IN ('product', 'product_variation') AND post_status = 'publish'", $posts_table ) );
		$counts      = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) as count FROM %i GROUP BY status', $this->products_table ), ARRAY_A );

		$stats = array(
			'total'         => $total_wc,
			'published'     => 0,
			'not_published' => $total_wc,
			'in_progress'   => 0,
			'failed'        => 0,
		);

		$published = 0;
		if ( is_array( $counts ) ) {
			foreach ( $counts as $c ) {
				$status = (string) $c['status'];
				$cnt    = (int) $c['count'];
				if ( 'published' === $status ) {
					$stats['published'] += $cnt;
					$published          += $cnt;
				} elseif ( 'publishing' === $status ) {
					$stats['in_progress'] += $cnt;
					$published            += $cnt;
				} elseif ( in_array( $status, array( 'error', 'sync_failed' ), true ) ) {
					$stats['failed'] += $cnt;
					$published       += $cnt;
				}
			}
		}

		$stats['not_published'] = max( 0, $total_wc - $published );
		return $stats;
	}

	public function add_log( int $connection_id, ?int $product_id, string $action, string $status, string $message, ?string $payload = null ): int {
		global $wpdb;
		$wpdb->insert(
			$this->logs_table,
			array(
				'connection_id'    => $connection_id,
				'product_id'       => $product_id,
				'action'           => $action,
				'status'           => $status,
				'message'          => $message,
				'payload_snapshot' => $payload,
				'created_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
	 */
	public function get_logs( int $page = 1, int $per_page = 20, ?int $connection_id = null, ?int $product_id = null ): array {
		global $wpdb;
		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where = 'WHERE 1=1';
		$args  = array();

		if ( null !== $connection_id && $connection_id > 0 ) {
			$where .= ' AND l.connection_id = %d';
			$args[] = $connection_id;
		}
		if ( null !== $product_id && $product_id > 0 ) {
			$where .= ' AND l.product_id = %d';
			$args[] = $product_id;
		}

		$count_sql = "SELECT COUNT(*) FROM {$this->logs_table} l {$where}";
		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query uses trusted table names and fixed clauses; all values are prepared.
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query contains only trusted table names and fixed clauses.
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$query_sql  = "SELECT l.*, c.marketplace, c.name as connection_name, p.post_title as product_name FROM {$this->logs_table} l LEFT JOIN {$this->connections_table} c ON l.connection_id = c.id LEFT JOIN {$wpdb->posts} p ON l.product_id = p.ID {$where} ORDER BY l.id DESC LIMIT %d OFFSET %d";
		$query_args = array_merge( $args, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query uses trusted table names and fixed clauses; all values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_args ), ARRAY_A );

		return array(
			'items'        => is_array( $rows ) ? $rows : array(),
			'total'        => $total,
			'total_items'  => $total,
			'page'         => $page,
			'current_page' => $page,
			'per_page'     => $per_page,
			'total_pages'  => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}
}
