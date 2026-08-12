<?php

namespace Stockino\Database;

final class ReorderRepository {
	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function paginate( int $page, int $per_page, string $search, string $state, int $supplier_id, int $category_id, string $sort, ?string $global_threshold ): array {
		global $wpdb;
		list( $select, $values ) = $this->base_select( $search, $supplier_id, $category_id, $global_threshold );
		$having                  = $this->state_having( $state );
		$count_sql               = "SELECT COUNT(*) FROM ({$select} {$having}) stockino_reorder_rows";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed query fragments; every request value is prepared.
		$total = (int) $wpdb->get_var( $this->prepare( $count_sql, $values ) );
		$sorts = array(
			'urgency'  => 'urgency_rank ASC, p.post_title ASC',
			'name'     => 'p.post_title ASC',
			'stock'    => 'current_stock ASC, p.post_title ASC',
			'incoming' => 'confirmed_incoming DESC, p.post_title ASC',
			'position' => 'inventory_position ASC, p.post_title ASC',
		);
		$order = $sorts[ $sort ] ?? $sorts['urgency'];
		$sql   = "{$select} {$having} ORDER BY {$order}, stock_owner_id ASC LIMIT %d OFFSET %d";
		$args  = array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ORDER BY is allowlisted and all request values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'items'      => array_map( array( $this, 'format_owner' ), $rows ),
			'pagination' => array(
				'current_page' => $page,
				'per_page'     => $per_page,
				'total_items'  => $total,
				'total_pages'  => (int) ceil( $total / $per_page ),
			),
		);
	}

	/** @return array<string,mixed>|null */
	public function find( int $stock_owner_id, ?string $global_threshold ): ?array {
		$rows = $this->find_many( array( $stock_owner_id ), $global_threshold );
		return $rows[0] ?? null;
	}

	/** @param array<int,int> $stock_owner_ids @return array<int,array<string,mixed>> */
	public function find_many( array $stock_owner_ids, ?string $global_threshold ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $stock_owner_ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}
		list( $select, $values ) = $this->base_select( '', 0, 0, $global_threshold );
		$placeholders            = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql                     = "{$select} AND p.ID IN ({$placeholders}) ORDER BY p.ID ASC";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IN placeholders are generated from validated integer IDs.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $values, $ids ) ), ARRAY_A );
		return array_map( array( $this, 'format_owner' ), $rows );
	}

	/** @return array<string,int> */
	public function stats( ?string $global_threshold ): array {
		global $wpdb;
		list( $select, $values ) = $this->base_select( '', 0, 0, $global_threshold );
		$sql                     = "SELECT
			SUM(state IN ('reorder_needed','no_supplier','supplier_selection_required')) reorder_needed,
			SUM(urgency_rank = 1 AND state IN ('reorder_needed','no_supplier','supplier_selection_required')) critical,
			SUM(state = 'covered_by_incoming') covered_by_incoming,
			SUM(state = 'no_supplier') no_supplier,
			SUM(state = 'attention_required') attention_required
			FROM ({$select}) stockino_reorder_stats";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed query and prepared global threshold.
		$row = $wpdb->get_row( $this->prepare( $sql, $values ), ARRAY_A );
		return array(
			'reorder_needed'      => (int) ( $row['reorder_needed'] ?? 0 ),
			'critical'            => (int) ( $row['critical'] ?? 0 ),
			'covered_by_incoming' => (int) ( $row['covered_by_incoming'] ?? 0 ),
			'no_supplier'         => (int) ( $row['no_supplier'] ?? 0 ),
			'attention_required'  => (int) ( $row['attention_required'] ?? 0 ),
		);
	}

	/** @return array{suppliers:array<int,array<string,mixed>>,categories:array<int,array<string,mixed>>} */
	public function filters(): array {
		global $wpdb;
		$suppliers  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, name FROM %i WHERE status = 'active' ORDER BY name ASC, id ASC LIMIT %d", $wpdb->prefix . 'stockino_suppliers', 200 ),
			ARRAY_A
		);
		$categories = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id id, t.name FROM %i t INNER JOIN %i tt ON tt.term_id = t.term_id
				WHERE tt.taxonomy = 'product_cat' ORDER BY t.name ASC LIMIT %d",
				$wpdb->terms,
				$wpdb->term_taxonomy,
				200
			),
			ARRAY_A
		);
		return array(
			'suppliers'  => array_map(
				static fn( array $row ): array => array(
					'id'   => (int) $row['id'],
					'name' => (string) $row['name'],
				),
				$suppliers
			),
			'categories' => array_map(
				static fn( array $row ): array => array(
					'id'   => (int) $row['id'],
					'name' => (string) $row['name'],
				),
				$categories
			),
		);
	}

	/** @param array<int,int> $stock_owner_ids @return array<int,array<int,array<string,mixed>>> */
	public function supplier_candidates( array $stock_owner_ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $stock_owner_ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$posts        = $wpdb->posts;
		$lookup       = $wpdb->wc_product_meta_lookup;
		$relations    = $wpdb->prefix . 'stockino_supplier_products';
		$suppliers    = $wpdb->prefix . 'stockino_suppliers';
		$orders       = $wpdb->prefix . 'stockino_purchase_orders';
		$order_items  = $wpdb->prefix . 'stockino_purchase_order_items';
		$movements    = $wpdb->prefix . 'stockino_inventory_cost_movements';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT mapped.stock_owner_id, mapped.source_product_id, mapped.source_product_name, mapped.source_sku,
			sp.supplier_id, s.name supplier_name, s.code supplier_code, sp.supplier_sku,
			sp.minimum_order_quantity, sp.order_multiple,
			COALESCE(sp.lead_time_days, s.lead_time_days) effective_lead_time_days,
			actual.unit_cost latest_actual_unit_cost, defaults.ordered_unit_cost latest_ordered_unit_cost
			FROM (
				SELECT sp0.id relation_id, sp0.product_id source_product_id, src.post_title source_product_name, sl.sku source_sku,
					CASE WHEN sl.stock_quantity IS NOT NULL THEN sp0.product_id
						WHEN src.post_type = 'product_variation' AND pl.stock_quantity IS NOT NULL THEN src.post_parent ELSE NULL END stock_owner_id
				FROM {$relations} sp0
				INNER JOIN {$posts} src ON src.ID = sp0.product_id AND src.post_status IN ('publish','private')
				LEFT JOIN {$lookup} sl ON sl.product_id = src.ID
				LEFT JOIN {$lookup} pl ON pl.product_id = src.post_parent
			) mapped
			INNER JOIN {$relations} sp ON sp.id = mapped.relation_id
			INNER JOIN {$suppliers} s ON s.id = sp.supplier_id AND s.status = 'active'
			LEFT JOIN (
				SELECT latest.supplier_id, latest.source_product_id, cm.unit_cost
				FROM (
					SELECT po.supplier_id, COALESCE(cm0.source_variation_id, cm0.source_product_id) source_product_id, MAX(cm0.id) movement_id
					FROM {$movements} cm0 INNER JOIN {$orders} po ON po.id = cm0.purchase_order_id
					WHERE cm0.movement_type = 'purchase_receipt' GROUP BY po.supplier_id, COALESCE(cm0.source_variation_id, cm0.source_product_id)
				) latest INNER JOIN {$movements} cm ON cm.id = latest.movement_id
			) actual ON actual.supplier_id = sp.supplier_id AND actual.source_product_id = sp.product_id
			LEFT JOIN (
				SELECT latest_po.supplier_id, latest_po.product_id, poi.ordered_unit_cost
				FROM (
					SELECT po.supplier_id, poi0.product_id, MAX(poi0.id) item_id
					FROM {$order_items} poi0 INNER JOIN {$orders} po ON po.id = poi0.purchase_order_id
					WHERE poi0.ordered_unit_cost IS NOT NULL AND po.status IN ('ordered','partially_received','received')
					GROUP BY po.supplier_id, poi0.product_id
				) latest_po INNER JOIN {$order_items} poi ON poi.id = latest_po.item_id
			) defaults ON defaults.supplier_id = sp.supplier_id AND defaults.product_id = sp.product_id
			WHERE mapped.stock_owner_id IN ({$placeholders})
			ORDER BY mapped.stock_owner_id ASC, mapped.source_product_id ASC, sp.supplier_id ASC";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Trusted table names; owner IDs use generated placeholders.
		$rows    = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );
		$grouped = array();
		foreach ( $rows as $row ) {
			$owner_id               = (int) $row['stock_owner_id'];
			$grouped[ $owner_id ][] = $this->format_candidate( $row );
		}
		return $grouped;
	}

	/** @param array<int,int> $stock_owner_ids @return array<int,int> */
	public function active_generated_replenishment_ids( array $stock_owner_ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $stock_owner_ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT DISTINCT i.reorder_stock_owner_id FROM %i i INNER JOIN %i po ON po.id = i.purchase_order_id
			WHERE i.reorder_stock_owner_id IN ({$placeholders}) AND po.status IN ('draft','ordered','partially_received')";
		$args         = array_merge( array( $wpdb->prefix . 'stockino_purchase_order_items', $wpdb->prefix . 'stockino_purchase_orders' ), $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The bounded IN list uses generated placeholders and validated integer IDs.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function incoming( int $stock_owner_id, int $page, int $per_page ): array {
		global $wpdb;
		$mapped = $this->mapped_order_items_sql();
		$count  = "SELECT COUNT(*) FROM ({$mapped}) incoming WHERE stock_owner_id = %d AND status IN ('ordered','partially_received')";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed derived query and prepared ID.
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count, $stock_owner_id ) );
		$sql   = "SELECT * FROM ({$mapped}) incoming WHERE stock_owner_id = %d AND status IN ('ordered','partially_received')
			ORDER BY expected_date ASC, purchase_order_id ASC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed derived query and prepared pagination values.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $stock_owner_id, $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		return array(
			'items'      => array_map( array( $this, 'format_incoming' ), $rows ),
			'pagination' => array(
				'current_page' => $page,
				'per_page'     => $per_page,
				'total_items'  => $total,
				'total_pages'  => (int) ceil( $total / $per_page ),
			),
		);
	}

	/** @return array{string,array<int,mixed>} */
	private function base_select( string $search, int $supplier_id, int $category_id, ?string $global_threshold ): array {
		global $wpdb;
		$posts         = $wpdb->posts;
		$lookup        = $wpdb->wc_product_meta_lookup;
		$postmeta      = $wpdb->postmeta;
		$settings      = $wpdb->prefix . 'stockino_reorder_settings';
		$relations     = $wpdb->prefix . 'stockino_supplier_products';
		$suppliers     = $wpdb->prefix . 'stockino_suppliers';
		$receipt_items = $wpdb->prefix . 'stockino_purchase_receipt_items';
		$where         = array( "p.post_type IN ('product','product_variation')", "p.post_status IN ('publish','private')", 'lookup.stock_quantity IS NOT NULL' );
		$values        = array();
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(p.post_title LIKE %s OR lookup.sku LIKE %s OR CAST(p.ID AS CHAR) = %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $search;
		}
		if ( $category_id > 0 ) {
			$where[]  = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tr.object_id = IF(p.post_type = 'product_variation', p.post_parent, p.ID) AND tt.taxonomy = 'product_cat' AND tt.term_id = %d)";
			$values[] = $category_id;
		}
		if ( $supplier_id > 0 ) {
			$where[]  = "EXISTS (SELECT 1 FROM {$relations} sf INNER JOIN {$suppliers} ss ON ss.id = sf.supplier_id AND ss.status = 'active'
				INNER JOIN {$posts} spost ON spost.ID = sf.product_id AND spost.post_status IN ('publish','private')
				LEFT JOIN {$lookup} slookup ON slookup.product_id = spost.ID LEFT JOIN {$lookup} splookup ON splookup.product_id = spost.post_parent
				WHERE sf.supplier_id = %d AND ((slookup.stock_quantity IS NOT NULL AND sf.product_id = p.ID)
					OR (spost.post_type = 'product_variation' AND splookup.stock_quantity IS NOT NULL AND spost.post_parent = p.ID)))";
			$values[] = $supplier_id;
		}
		$where_sql         = implode( ' AND ', $where );
		$incoming          = $this->incoming_aggregate_sql();
		$relation_summary  = "SELECT mapped.stock_owner_id, COUNT(*) candidate_count, COUNT(DISTINCT mapped.source_product_id) source_count
			FROM (
				SELECT sp.product_id source_product_id,
					CASE WHEN sl.stock_quantity IS NOT NULL THEN sp.product_id
						WHEN src.post_type = 'product_variation' AND pl.stock_quantity IS NOT NULL THEN src.post_parent ELSE NULL END stock_owner_id
				FROM {$relations} sp INNER JOIN {$suppliers} s ON s.id = sp.supplier_id AND s.status = 'active'
				INNER JOIN {$posts} src ON src.ID = sp.product_id AND src.post_status IN ('publish','private')
				LEFT JOIN {$lookup} sl ON sl.product_id = src.ID LEFT JOIN {$lookup} pl ON pl.product_id = src.post_parent
			) mapped WHERE mapped.stock_owner_id IS NOT NULL GROUP BY mapped.stock_owner_id";
		$attention         = "SELECT stock_owner_id,
			SUM(CASE WHEN status = 'requires_attention' OR costing_status = 'requires_attention' THEN quantity_received ELSE 0 END) attention_incoming,
			MAX(status = 'requires_attention' OR costing_status = 'requires_attention') attention_required
			FROM {$receipt_items} GROUP BY stock_owner_id";
		$global_sql        = null === $global_threshold ? 'NULL' : "'" . esc_sql( $global_threshold ) . "'";
		$own_point         = "NULLIF((SELECT own.meta_value FROM {$postmeta} own WHERE own.post_id = p.ID AND own.meta_key = '_low_stock_amount' LIMIT 1), '')";
		$parent_point      = "CASE WHEN p.post_type = 'product_variation' THEN NULLIF((SELECT parent.meta_value FROM {$postmeta} parent WHERE parent.post_id = p.post_parent AND parent.meta_key = '_low_stock_amount' LIMIT 1), '') END";
		$woo_point         = "COALESCE({$own_point}, {$parent_point})";
		$point             = "COALESCE(rs.custom_reorder_point, {$woo_point}, {$global_sql})";
		$current           = 'CAST(COALESCE(lookup.stock_quantity,0) AS DECIMAL(20,6))';
		$incoming_quantity = 'CAST(COALESCE(inc.confirmed_incoming,0) AS DECIMAL(20,6))';
		$position          = "({$current} + {$incoming_quantity})";
		$preferred_owner   = "CASE WHEN prefl.stock_quantity IS NOT NULL THEN rs.preferred_product_id
			WHEN prefp.post_type = 'product_variation' AND prefpl.stock_quantity IS NOT NULL THEN prefp.post_parent ELSE NULL END";
		$preferred_valid   = "(COALESCE(prefs.status = 'active' AND prefrel.id IS NOT NULL AND {$preferred_owner} = p.ID,0) = 1)";
		$state             = "CASE
			WHEN COALESCE(att.attention_required,0) = 1 THEN 'attention_required'
			WHEN {$point} IS NULL THEN 'threshold_unknown'
			WHEN {$position} > {$point} AND {$current} <= {$point} THEN 'covered_by_incoming'
			WHEN {$position} > {$point} THEN 'healthy'
			WHEN COALESCE(rels.candidate_count,0) = 0 THEN 'no_supplier'
			WHEN COALESCE(rels.source_count,0) > 1 AND NOT {$preferred_valid} THEN 'supplier_selection_required'
			ELSE 'reorder_needed' END";
		$urgency           = "CASE WHEN COALESCE(att.attention_required,0) = 1 THEN 0
			WHEN {$point} IS NOT NULL AND {$current} <= 0 AND {$position} <= {$point} THEN 1
			WHEN {$point} IS NOT NULL AND {$position} < {$point} THEN 2
			WHEN {$point} IS NOT NULL AND {$position} = {$point} THEN 3
			ELSE 4 END";
		$sql               = "SELECT p.ID stock_owner_id, p.post_title product_name, p.post_parent parent_id, lookup.sku,
			{$current} current_stock, {$woo_point} woo_low_stock_amount,
			{$incoming_quantity} confirmed_incoming, CAST(COALESCE(att.attention_incoming,0) AS DECIMAL(20,6)) attention_incoming,
			COALESCE(att.attention_required,0) attention_required, {$position} inventory_position,
			rs.custom_reorder_point, rs.custom_target_stock, rs.preferred_supplier_id, rs.preferred_product_id,
			rs.updated_at settings_updated_at, COALESCE(rels.candidate_count,0) supplier_candidate_count,
			COALESCE(rels.source_count,0) supplier_source_count, {$state} state, {$urgency} urgency_rank
			FROM {$posts} p INNER JOIN {$lookup} lookup ON lookup.product_id = p.ID
			LEFT JOIN {$settings} rs ON rs.stock_owner_id = p.ID
			LEFT JOIN ({$incoming}) inc ON inc.stock_owner_id = p.ID
			LEFT JOIN ({$attention}) att ON att.stock_owner_id = p.ID
			LEFT JOIN ({$relation_summary}) rels ON rels.stock_owner_id = p.ID
			LEFT JOIN {$relations} prefrel ON prefrel.supplier_id = rs.preferred_supplier_id AND prefrel.product_id = rs.preferred_product_id
			LEFT JOIN {$suppliers} prefs ON prefs.id = rs.preferred_supplier_id
			LEFT JOIN {$posts} prefp ON prefp.ID = rs.preferred_product_id
			LEFT JOIN {$lookup} prefl ON prefl.product_id = prefp.ID
			LEFT JOIN {$lookup} prefpl ON prefpl.product_id = prefp.post_parent
			WHERE {$where_sql}";
		return array( $sql, $values );
	}

	private function state_having( string $state ): string {
		$allowed = array( 'healthy', 'covered_by_incoming', 'reorder_needed', 'threshold_unknown', 'no_supplier', 'supplier_selection_required', 'attention_required' );
		return in_array( $state, $allowed, true ) ? "HAVING state = '" . esc_sql( $state ) . "'" : '';
	}

	private function incoming_aggregate_sql(): string {
		$mapped = $this->mapped_order_items_sql();
		return "SELECT stock_owner_id,
			SUM(CASE WHEN status IN ('ordered','partially_received') THEN remaining_quantity ELSE 0 END) confirmed_incoming
			FROM ({$mapped}) mapped_incoming GROUP BY stock_owner_id";
	}

	private function mapped_order_items_sql(): string {
		global $wpdb;
		$posts    = $wpdb->posts;
		$lookup   = $wpdb->wc_product_meta_lookup;
		$orders   = $wpdb->prefix . 'stockino_purchase_orders';
		$items    = $wpdb->prefix . 'stockino_purchase_order_items';
		$received = $wpdb->prefix . 'stockino_purchase_receipt_items';
		return "SELECT po.id purchase_order_id, po.po_number, po.status, po.expected_date, po.supplier_id,
			i.product_id source_product_id, i.ordered_quantity, i.received_quantity confirmed_received_quantity,
			GREATEST(i.received_quantity,COALESCE(receipts.accounted_quantity,0)) received_quantity,
			COALESCE(receipts.attention_quantity,0) attention_quantity,
			GREATEST(i.ordered_quantity - GREATEST(i.received_quantity,COALESCE(receipts.accounted_quantity,0)),0) remaining_quantity,
			CASE WHEN sl.stock_quantity IS NOT NULL THEN i.product_id
				WHEN src.post_type = 'product_variation' AND pl.stock_quantity IS NOT NULL THEN src.post_parent ELSE NULL END stock_owner_id
			FROM {$items} i INNER JOIN {$orders} po ON po.id = i.purchase_order_id
			LEFT JOIN (
				SELECT purchase_order_item_id,
					SUM(CASE WHEN status IN ('completed','requires_attention') THEN quantity_received ELSE 0 END) accounted_quantity,
					SUM(CASE WHEN status = 'requires_attention' OR costing_status = 'requires_attention' THEN quantity_received ELSE 0 END) attention_quantity
				FROM {$received} GROUP BY purchase_order_item_id
			) receipts ON receipts.purchase_order_item_id = i.id
			LEFT JOIN {$posts} src ON src.ID = i.product_id LEFT JOIN {$lookup} sl ON sl.product_id = src.ID
			LEFT JOIN {$lookup} pl ON pl.product_id = src.post_parent";
	}

	/** @return array<string,mixed> */
	private function format_owner( array $row ): array {
		return array(
			'stock_owner_id'           => (int) $row['stock_owner_id'],
			'product_name'             => (string) $row['product_name'],
			'parent_id'                => (int) $row['parent_id'] > 0 ? (int) $row['parent_id'] : null,
			'sku'                      => null !== $row['sku'] ? (string) $row['sku'] : null,
			'current_stock'            => (string) $row['current_stock'],
			'woo_low_stock_amount'     => null !== $row['woo_low_stock_amount'] ? (string) $row['woo_low_stock_amount'] : null,
			'confirmed_incoming'       => (string) $row['confirmed_incoming'],
			'attention_incoming'       => (string) $row['attention_incoming'],
			'attention_required'       => (bool) $row['attention_required'],
			'custom_reorder_point'     => null !== $row['custom_reorder_point'] ? (string) $row['custom_reorder_point'] : null,
			'custom_target_stock'      => null !== $row['custom_target_stock'] ? (string) $row['custom_target_stock'] : null,
			'preferred_supplier_id'    => $row['preferred_supplier_id'] ? (int) $row['preferred_supplier_id'] : null,
			'preferred_product_id'     => $row['preferred_product_id'] ? (int) $row['preferred_product_id'] : null,
			'settings_updated_at'      => $row['settings_updated_at'] ? $this->iso_date( (string) $row['settings_updated_at'] ) : null,
			'supplier_candidate_count' => (int) $row['supplier_candidate_count'],
			'supplier_source_count'    => (int) $row['supplier_source_count'],
		);
	}

	/** @return array<string,mixed> */
	private function format_candidate( array $row ): array {
		$lead = null !== $row['effective_lead_time_days'] ? (int) $row['effective_lead_time_days'] : null;
		return array(
			'supplier_id'               => (int) $row['supplier_id'],
			'supplier_name'             => (string) $row['supplier_name'],
			'supplier_code'             => null !== $row['supplier_code'] ? (string) $row['supplier_code'] : null,
			'source_product_id'         => (int) $row['source_product_id'],
			'source_product_name'       => (string) $row['source_product_name'],
			'source_sku'                => null !== $row['source_sku'] ? (string) $row['source_sku'] : null,
			'supplier_sku'              => null !== $row['supplier_sku'] ? (string) $row['supplier_sku'] : null,
			'minimum_order_quantity'    => null !== $row['minimum_order_quantity'] ? (string) $row['minimum_order_quantity'] : null,
			'order_multiple'            => null !== $row['order_multiple'] ? (string) $row['order_multiple'] : null,
			'effective_lead_time_days'  => $lead,
			'default_ordered_unit_cost' => null !== $row['latest_actual_unit_cost'] ? (string) $row['latest_actual_unit_cost'] : ( null !== $row['latest_ordered_unit_cost'] ? (string) $row['latest_ordered_unit_cost'] : null ),
			'default_cost_source'       => null !== $row['latest_actual_unit_cost'] ? 'latest_confirmed_receipt' : ( null !== $row['latest_ordered_unit_cost'] ? 'latest_po_default' : null ),
		);
	}

	/** @return array<string,mixed> */
	private function format_incoming( array $row ): array {
		return array(
			'purchase_order_id'           => (int) $row['purchase_order_id'],
			'po_number'                   => (string) $row['po_number'],
			'status'                      => (string) $row['status'],
			'expected_date'               => $row['expected_date'] ? (string) $row['expected_date'] : null,
			'supplier_id'                 => (int) $row['supplier_id'],
			'source_product_id'           => (int) $row['source_product_id'],
			'ordered_quantity'            => (string) $row['ordered_quantity'],
			'received_quantity'           => (string) $row['received_quantity'],
			'confirmed_received_quantity' => (string) $row['confirmed_received_quantity'],
			'attention_quantity'          => (string) $row['attention_quantity'],
			'remaining_quantity'          => (string) $row['remaining_quantity'],
		);
	}

	private function iso_date( string $value ): string {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date ? $date->format( DATE_RFC3339 ) : $value;
	}

	/** @param array<int,mixed> $values */
	private function prepare( string $sql, array $values ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL without values is assembled exclusively from fixed table names and allow-listed clauses above.
		return array() === $values ? $sql : $wpdb->prepare( $sql, $values );
	}
}
