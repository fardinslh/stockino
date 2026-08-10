<?php

namespace Stockino\Inventory;

final class InventoryQuery {
	/** @param array<string,mixed> $params @return array{ids:array<int,int>,total:int} */
	public function page( array $params, int $page, int $per_page, ?int $known_total = null ): array {
		global $wpdb;

		$where  = array(
			"p.post_type IN ('product','product_variation')",
			"p.post_status IN ('publish','private')",
		);
		$values = array();

		if ( ! empty( $params['stock_status'] ) && in_array( $params['stock_status'], array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			$where[]  = 'l.stock_status = %s';
			$values[] = $params['stock_status'];
		}
		if ( ! empty( $params['type'] ) && in_array( $params['type'], array( 'simple', 'variable', 'variation' ), true ) ) {
			if ( 'variation' === $params['type'] ) {
				$where[] = "p.post_type = 'product_variation'";
			} else {
				$where[]  = "p.post_type = 'product' AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_type' AND t.slug = %s)";
				$values[] = $params['type'];
			}
		}
		if ( ! empty( $params['category'] ) ) {
			$where[]  = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} ctr INNER JOIN {$wpdb->term_taxonomy} ctt ON ctt.term_taxonomy_id = ctr.term_taxonomy_id INNER JOIN {$wpdb->terms} ct ON ct.term_id = ctt.term_id WHERE ctr.object_id = CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END AND ctt.taxonomy = 'product_cat' AND ct.slug = %s)";
			$values[] = sanitize_title( (string) $params['category'] );
		}
		if ( ! empty( $params['search'] ) ) {
			$search = sanitize_text_field( (string) $params['search'] );
			$ids    = array_filter( array( wc_get_product_id_by_sku( $search ), ctype_digit( $search ) ? (int) $search : 0 ) );
			if ( $ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$where[]      = "(p.ID IN ({$placeholders}) OR p.post_title LIKE %s)";
				$values       = array_merge( $values, array_values( $ids ) );
			} else {
				$where[] = 'p.post_title LIKE %s';
			}
			$values[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		if ( array_key_exists( 'manage_stock', $params ) && null !== $params['manage_stock'] ) {
			$where[] = ! empty( $params['manage_stock'] ) ? 'l.stock_quantity IS NOT NULL' : 'l.stock_quantity IS NULL';
		}
		if ( ! empty( $params['low_stock'] ) ) {
			$where[]  = 'l.stock_quantity <= ' . $this->effective_low_expression( $wpdb->postmeta ) . ' AND l.stock_quantity > %f';
			$values[] = (float) get_option( 'woocommerce_notify_low_stock_amount', 2 );
			$values[] = (float) get_option( 'woocommerce_notify_no_stock_amount', 0 );
		}

		$from        = "FROM {$wpdb->posts} p INNER JOIN {$wpdb->wc_product_meta_lookup} l ON l.product_id = p.ID WHERE " . implode( ' AND ', $where );
		$count_sql   = "SELECT COUNT(DISTINCT p.ID) {$from}";
		$total       = null === $known_total ? (int) $wpdb->get_var( $this->prepare( $count_sql, $values ) ) : $known_total; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$page_values = array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) );
		$page_sql    = "SELECT DISTINCT p.ID {$from} ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d";
		$ids         = $wpdb->get_col( $this->prepare( $page_sql, $page_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'ids'   => array_map( 'intval', $ids ),
			'total' => $total,
		);
	}

	/** @return array<string,int|float> */
	public function stats(): array {
		global $wpdb;
		$low = $this->effective_low_expression( $wpdb->postmeta );
		$sql = "SELECT
			SUM(CASE WHEN l.stock_quantity IS NOT NULL THEN 1 ELSE 0 END) managed_products,
			SUM(CASE WHEN l.stock_status = 'outofstock' THEN 1 ELSE 0 END) out_of_stock,
			SUM(CASE WHEN l.stock_quantity <= {$low} AND l.stock_quantity > %f THEN 1 ELSE 0 END) low_stock,
			COALESCE(SUM(l.stock_quantity), 0) total_units
			FROM {$wpdb->wc_product_meta_lookup} l INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
			WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private')";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled only from fixed fragments and WordPress-owned table names.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				$sql,
				(float) get_option( 'woocommerce_notify_low_stock_amount', 2 ),
				(float) get_option( 'woocommerce_notify_no_stock_amount', 0 )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'managed_products' => (int) ( $row['managed_products'] ?? 0 ),
			'out_of_stock'     => (int) ( $row['out_of_stock'] ?? 0 ),
			'low_stock'        => (int) ( $row['low_stock'] ?? 0 ),
			'total_units'      => (float) ( $row['total_units'] ?? 0 ),
		);
	}

	private function effective_low_expression( string $postmeta ): string {
		return "COALESCE(NULLIF((SELECT own.meta_value FROM {$postmeta} own WHERE own.post_id = p.ID AND own.meta_key = '_low_stock_amount' LIMIT 1), ''), CASE WHEN p.post_type = 'product_variation' THEN NULLIF((SELECT parent.meta_value FROM {$postmeta} parent WHERE parent.post_id = p.post_parent AND parent.meta_key = '_low_stock_amount' LIMIT 1), '') END, %f)";
	}

	/** @param array<int,mixed> $values */
	private function prepare( string $sql, array $values ): string {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Callers build SQL only from fixed fragments and WordPress-owned table names.
		return $values ? $wpdb->prepare( $sql, $values ) : $sql;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}
}
