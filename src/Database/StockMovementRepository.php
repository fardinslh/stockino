<?php

namespace Stockino\Database;

use WC_Product;

final class StockMovementRepository {
	/** @param array<string,mixed> $movement */
	public function insert( array $movement ): int {
		global $wpdb;

		$data = wp_parse_args(
			$movement,
			array(
				'variation_id'   => null,
				'reference_type' => null,
				'reference_id'   => null,
				'actor_id'       => get_current_user_id() ? get_current_user_id() : null,
				'note'           => null,
				'metadata'       => null,
				'created_at'     => current_time( 'mysql', true ),
			)
		);

		if ( is_array( $data['metadata'] ) ) {
			$data['metadata'] = wp_json_encode( $data['metadata'] );
		}

		$inserted = $wpdb->insert( $this->table(), $data );
		if ( false === $inserted ) {
			throw new \RuntimeException( 'The stock movement could not be recorded.' );
		}

		return (int) $wpdb->insert_id;
	}

	/** @param array<int,int> $target_ids @return array<int,array<string,mixed>> */
	public function latest_for_products( array $target_ids ): array {
		global $wpdb;

		$target_ids = array_values( array_unique( array_filter( array_map( 'absint', $target_ids ) ) ) );
		if ( array() === $target_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $target_ids ), '%d' ) );
		$table        = $this->table();
		$query        = "SELECT m.* FROM {$table} m INNER JOIN (
			SELECT MAX(id) AS latest_id FROM {$table}
			WHERE COALESCE(variation_id, product_id) IN ({$placeholders})
			GROUP BY COALESCE(variation_id, product_id)
		) latest ON latest.latest_id = m.id";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are generated, values are prepared below.
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $target_ids ), ARRAY_A );
		$map  = array();
		foreach ( $rows as $row ) {
			$map[ (int) ( $row['variation_id'] ? $row['variation_id'] : $row['product_id'] ) ] = $this->format_row( $row );
		}

		return $map;
	}

	/** @return array{items:array<int,array<string,mixed>>,current_page:int,per_page:int,total_items:int,total_pages:int} */
	public function paginate_for_product( int $product_id, int $page, int $per_page ): array {
		global $wpdb;

		$table  = $this->table();
		$total  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE (variation_id = %d OR (product_id = %d AND variation_id IS NULL))', $table, $product_id, $product_id ) );
		$offset = ( $page - 1 ) * $per_page;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.*, u.display_name AS actor_name FROM %i m
				LEFT JOIN %i u ON u.ID = m.actor_id
				WHERE (variation_id = %d OR (product_id = %d AND variation_id IS NULL)) ORDER BY m.created_at DESC, m.id DESC LIMIT %d OFFSET %d',
				$table,
				$wpdb->users,
				$product_id,
				$product_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'items'        => array_map( array( $this, 'format_row' ), $rows ),
			'current_page' => $page,
			'per_page'     => $per_page,
			'total_items'  => $total,
			'total_pages'  => (int) ceil( $total / $per_page ),
		);
	}

	/** @return array{product_id:int,variation_id:?int,movement_type:string,reason:string,quantity_before:float,quantity_delta:float,quantity_after:float,actor_id:?int,actor_name:?string,note:?string,created_at:string} */
	private function format_row( array $row ): array {
		$created_at = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $row['created_at'], new \DateTimeZone( 'UTC' ) );
		return array(
			'product_id'      => (int) $row['product_id'],
			'variation_id'    => $row['variation_id'] ? (int) $row['variation_id'] : null,
			'movement_type'   => (string) $row['movement_type'],
			'reason'          => (string) $row['reason'],
			'quantity_before' => (float) $row['quantity_before'],
			'quantity_delta'  => (float) $row['quantity_delta'],
			'quantity_after'  => (float) $row['quantity_after'],
			'actor_id'        => $row['actor_id'] ? (int) $row['actor_id'] : null,
			'actor_name'      => isset( $row['actor_name'] ) ? (string) $row['actor_name'] : null,
			'note'            => ! empty( $row['note'] ) ? (string) $row['note'] : null,
			'created_at'      => $created_at ? $created_at->format( DATE_RFC3339 ) : (string) $row['created_at'],
		);
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stockino_stock_movements';
	}
}
