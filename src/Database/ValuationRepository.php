<?php

namespace Stockino\Database;

use Stockino\Costing\FixedDecimal;

final class ValuationRepository {
	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function paginate( int $page, int $per_page, string $search, string $stock_status, string $cost_status, string $sort, string $direction ): array {
		global $wpdb;
		$where  = array( "p.post_type IN ('product','product_variation')", "p.post_status IN ('publish','private')", 'lookup.stock_quantity IS NOT NULL' );
		$values = array();
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(p.post_title LIKE %s OR lookup.sku LIKE %s OR CAST(p.ID AS CHAR) = %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $search;
		}
		$stock_expression = 'CAST(COALESCE(lookup.stock_quantity, 0) AS DECIMAL(20,6))';
		if ( 'positive' === $stock_status ) {
			$where[] = "{$stock_expression} > 0";
		} elseif ( 'zero' === $stock_status ) {
			$where[] = "{$stock_expression} = 0";
		} elseif ( 'negative' === $stock_status ) {
			$where[] = "{$stock_expression} < 0";
		}
		if ( 'costed' === $cost_status ) {
			$where[] = 'cost.id IS NOT NULL';
		} elseif ( 'uncosted' === $cost_status ) {
			$where[] = 'cost.id IS NULL';
		}
		$where_sql = implode( ' AND ', $where );
		$joins     = 'FROM %i p
			INNER JOIN %i lookup ON lookup.product_id = p.ID
			LEFT JOIN %i cost ON cost.stock_owner_id = p.ID';
		$tables    = array( $wpdb->posts, $wpdb->wc_product_meta_lookup, $this->costs_table() );
		$count_sql = "SELECT COUNT(*) {$joins} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- All dynamic clauses are fixed whitelists and values are prepared.
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, array_merge( $tables, $values ) ) );

		$sorts    = array(
			'name'         => 'p.post_title',
			'sku'          => 'lookup.sku',
			'stock'        => $stock_expression,
			'average_cost' => 'cost.average_unit_cost',
			'value'        => "CASE WHEN {$stock_expression} > 0 AND cost.average_unit_cost IS NOT NULL THEN {$stock_expression} * cost.average_unit_cost ELSE 0 END",
			'updated'      => 'cost.updated_at',
		);
		$order_by = $sorts[ $sort ] ?? $sorts['name'];
		$dir      = 'desc' === strtolower( $direction ) ? 'DESC' : 'ASC';
		$list_sql = "SELECT p.ID stock_owner_id, p.post_title product_name, p.post_parent parent_id,
			lookup.sku, {$stock_expression} current_stock, cost.average_unit_cost,
			cost.currency_snapshot, cost.updated_at cost_updated_at
			{$joins} WHERE {$where_sql} ORDER BY {$order_by} {$dir}, p.ID ASC LIMIT %d OFFSET %d";
		$args     = array_merge( $tables, $values, array( $per_page, ( $page - 1 ) * $per_page ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Whitelisted ORDER BY and prepared values only.
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $args ), ARRAY_A );
		return array(
			'items'      => array_map( array( $this, 'format_row' ), $rows ),
			'pagination' => array(
				'current_page' => $page,
				'per_page'     => $per_page,
				'total_items'  => $total,
				'total_pages'  => (int) ceil( $total / $per_page ),
			),
		);
	}

	/** @return array<string,mixed>|null */
	public function find( int $stock_owner_id ): ?array {
		$result = $this->find_rows( $stock_owner_id );
		return $result[0] ?? null;
	}

	/** @return array<string,mixed> */
	public function stats(): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				COUNT(*) total_stock_owners,
				SUM(CASE WHEN CAST(COALESCE(lookup.stock_quantity,0) AS DECIMAL(20,6)) > 0 THEN 1 ELSE 0 END) positive_stock_owners,
				SUM(CASE WHEN cost.id IS NOT NULL THEN 1 ELSE 0 END) costed_stock_owners,
				SUM(CASE WHEN CAST(COALESCE(lookup.stock_quantity,0) AS DECIMAL(20,6)) > 0 AND cost.id IS NULL THEN 1 ELSE 0 END) uncosted_positive_stock_owners,
				CAST(COALESCE(SUM(CASE WHEN CAST(COALESCE(lookup.stock_quantity,0) AS DECIMAL(20,6)) > 0 AND cost.id IS NOT NULL
					THEN CAST(lookup.stock_quantity AS DECIMAL(20,6)) * cost.average_unit_cost ELSE 0 END),0) AS DECIMAL(30,6)) total_known_value
				FROM %i p
				INNER JOIN %i lookup ON lookup.product_id = p.ID AND lookup.stock_quantity IS NOT NULL
				LEFT JOIN %i cost ON cost.stock_owner_id = p.ID
				WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private')",
				$wpdb->posts,
				$wpdb->wc_product_meta_lookup,
				$this->costs_table()
			),
			ARRAY_A
		);
		return array(
			'total_known_value'              => (string) ( $row['total_known_value'] ?? '0.000000' ),
			'costed_stock_owners'            => (int) ( $row['costed_stock_owners'] ?? 0 ),
			'uncosted_positive_stock_owners' => (int) ( $row['uncosted_positive_stock_owners'] ?? 0 ),
			'positive_stock_owners'          => (int) ( $row['positive_stock_owners'] ?? 0 ),
			'total_stock_owners'             => (int) ( $row['total_stock_owners'] ?? 0 ),
			'is_partial'                     => (int) ( $row['uncosted_positive_stock_owners'] ?? 0 ) > 0,
			'currency'                       => get_woocommerce_currency(),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function find_rows( int $stock_owner_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID stock_owner_id, p.post_title product_name, p.post_parent parent_id, lookup.sku,
				CAST(COALESCE(lookup.stock_quantity,0) AS DECIMAL(20,6)) current_stock, cost.average_unit_cost,
				cost.currency_snapshot, cost.updated_at cost_updated_at
				FROM %i p
				INNER JOIN %i lookup ON lookup.product_id = p.ID AND lookup.stock_quantity IS NOT NULL
				LEFT JOIN %i cost ON cost.stock_owner_id = p.ID
				WHERE p.ID = %d AND p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private')",
				$wpdb->posts,
				$wpdb->wc_product_meta_lookup,
				$this->costs_table(),
				$stock_owner_id
			),
			ARRAY_A
		);
		return array_map( array( $this, 'format_row' ), $rows );
	}

	/** @return array<string,mixed> */
	private function format_row( array $row ): array {
		$stock    = FixedDecimal::normalize_quantity( (string) $row['current_stock'] ) ?? '0.000000';
		$average  = null !== $row['average_unit_cost'] ? (string) $row['average_unit_cost'] : null;
		$positive = FixedDecimal::compare( $stock, '0.000000' ) > 0;
		return array(
			'stock_owner_id'    => (int) $row['stock_owner_id'],
			'product_name'      => (string) $row['product_name'],
			'parent_id'         => (int) $row['parent_id'] > 0 ? (int) $row['parent_id'] : null,
			'sku'               => null !== $row['sku'] ? (string) $row['sku'] : null,
			'current_stock'     => $stock,
			'average_unit_cost' => $average,
			'inventory_value'   => FixedDecimal::inventory_value( $stock, $average ),
			'cost_status'       => null === $average ? 'uncosted' : 'costed',
			'stock_status'      => $positive ? 'positive' : ( FixedDecimal::compare( $stock, '0.000000' ) < 0 ? 'negative' : 'zero' ),
			'currency_snapshot' => $row['currency_snapshot'] ? (string) $row['currency_snapshot'] : get_woocommerce_currency(),
			'cost_updated_at'   => $row['cost_updated_at'] ? $this->iso_date( (string) $row['cost_updated_at'] ) : null,
		);
	}

	private function iso_date( string $value ): string {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date ? $date->format( DATE_RFC3339 ) : $value;
	}

	private function costs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stockino_inventory_costs';
	}
}
