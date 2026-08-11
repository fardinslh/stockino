<?php

namespace Stockino\Database;

final class InventoryCostRepository {
	/** @return array<string,mixed>|null */
	public function find_current( int $stock_owner_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE stock_owner_id = %d', $this->costs_table(), $stock_owner_id ), ARRAY_A );
		return $row ? $this->format_current( $row ) : null;
	}

	/** @param array<string,mixed> $movement */
	public function record( array $movement, string $average_after, ?int $last_receipt_item_id ): int {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$now       = current_time( 'mysql', true );
			$movement  = array_merge(
				$movement,
				array(
					'average_cost_after' => $average_after,
					'created_by'         => get_current_user_id() > 0 ? get_current_user_id() : null,
					'created_at'         => $now,
				)
			);
			$old       = $wpdb->suppress_errors( true );
			$inserted  = $wpdb->insert( $this->movements_table(), $movement );
			$duplicate = str_contains( strtolower( (string) $wpdb->last_error ), 'duplicate' );
			$wpdb->suppress_errors( $old );
			if ( false === $inserted ) {
				throw new \RuntimeException( $duplicate ? 'duplicate_cost_movement' : 'cost_movement_insert_failed' );
			}
			$movement_id = (int) $wpdb->insert_id;
			$last_sql    = null === $last_receipt_item_id ? 'NULL' : '%d';
			$sql         = "INSERT INTO %i (stock_owner_id, average_unit_cost, currency_snapshot, last_receipt_item_id, created_at, updated_at)
				VALUES (%d, %s, %s, {$last_sql}, %s, %s)
				ON DUPLICATE KEY UPDATE average_unit_cost = VALUES(average_unit_cost), currency_snapshot = VALUES(currency_snapshot),
				last_receipt_item_id = COALESCE(VALUES(last_receipt_item_id), last_receipt_item_id), updated_at = VALUES(updated_at)";
			$args        = array( $this->costs_table(), (int) $movement['stock_owner_id'], $average_after, (string) $movement['currency_snapshot'] );
			if ( null !== $last_receipt_item_id ) {
				$args[] = $last_receipt_item_id;
			}
			$args[] = $now;
			$args[] = $now;
			$result = $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains only a fixed optional NULL/%d placeholder selected above.
				$wpdb->prepare( $sql, $args )
			);
			if ( false === $result ) {
				throw new \RuntimeException( 'inventory_cost_update_failed' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'inventory_cost_commit_failed' );
			}
			return $movement_id;
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function history( int $stock_owner_id, int $page, int $per_page ): array {
		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE stock_owner_id = %d', $this->movements_table(), $stock_owner_id ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT cm.*, po.po_number, pr.receipt_number, u.display_name actor_name
				FROM %i cm
				LEFT JOIN %i po ON po.id = cm.purchase_order_id
				LEFT JOIN %i pr ON pr.id = cm.receipt_id
				LEFT JOIN %i u ON u.ID = cm.created_by
				WHERE cm.stock_owner_id = %d ORDER BY cm.created_at DESC, cm.id DESC LIMIT %d OFFSET %d',
				$this->movements_table(),
				$wpdb->prefix . 'stockino_purchase_orders',
				$wpdb->prefix . 'stockino_purchase_receipts',
				$wpdb->users,
				$stock_owner_id,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		return array(
			'items'      => array_map( array( $this, 'format_movement' ), $rows ),
			'pagination' => array(
				'current_page' => $page,
				'per_page'     => $per_page,
				'total_items'  => $total,
				'total_pages'  => (int) ceil( $total / $per_page ),
			),
		);
	}

	/** @return array<string,mixed> */
	private function format_current( array $row ): array {
		return array(
			'id'                   => (int) $row['id'],
			'stock_owner_id'       => (int) $row['stock_owner_id'],
			'average_unit_cost'    => (string) $row['average_unit_cost'],
			'currency_snapshot'    => (string) $row['currency_snapshot'],
			'last_receipt_item_id' => $row['last_receipt_item_id'] ? (int) $row['last_receipt_item_id'] : null,
			'created_at'           => $this->iso_date( (string) $row['created_at'] ),
			'updated_at'           => $this->iso_date( (string) $row['updated_at'] ),
		);
	}

	/** @return array<string,mixed> */
	private function format_movement( array $row ): array {
		$nullable_ids = array( 'source_variation_id', 'purchase_order_id', 'purchase_order_item_id', 'receipt_id', 'receipt_item_id', 'created_by' );
		foreach ( $nullable_ids as $field ) {
			$row[ $field ] = $row[ $field ] ? (int) $row[ $field ] : null;
		}
		foreach ( array( 'id', 'stock_owner_id', 'source_product_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['actor_name'] = $row['actor_name'] ? (string) $row['actor_name'] : null;
		$row['created_at'] = $this->iso_date( (string) $row['created_at'] );
		return $row;
	}

	private function iso_date( string $value ): string {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date ? $date->format( DATE_RFC3339 ) : $value;
	}

	private function costs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stockino_inventory_costs';
	}

	private function movements_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stockino_inventory_cost_movements';
	}
}
