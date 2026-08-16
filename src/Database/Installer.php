<?php

namespace Stockino\Database;

final class Installer {
	private const OPTION = 'stockino_db_version';

	public static function activate(): void {
		self::migrate();
	}

	public static function maybe_upgrade(): void {
		if ( STOCKINO_DB_VERSION !== get_option( self::OPTION ) ) {
			self::migrate();
		}
	}

	private static function migrate(): void {
		$current = (string) get_option( self::OPTION, '0.0.0' );
		if ( version_compare( $current, '1.0.0', '<' ) ) {
			self::create_stock_movements_table();
		}
		if ( version_compare( $current, '2.0.0', '<' ) ) {
			self::create_supplier_tables();
		}
		if ( version_compare( $current, '3.0.0', '<' ) ) {
			self::create_purchasing_tables();
		}
		if ( version_compare( $current, '4.0.0', '<' ) ) {
			self::create_costing_tables();
		}
		if ( version_compare( $current, '5.0.0', '<' ) ) {
			self::create_reorder_tables();
			self::create_marketplace_tables();
		}
		update_option( self::OPTION, STOCKINO_DB_VERSION, false );
	}

	private static function create_stock_movements_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'stockino_stock_movements';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			variation_id bigint(20) unsigned NULL,
			movement_type varchar(50) NOT NULL,
			reason varchar(50) NOT NULL,
			quantity_before decimal(20,6) NOT NULL,
			quantity_delta decimal(20,6) NOT NULL,
			quantity_after decimal(20,6) NOT NULL,
			reference_type varchar(50) NULL,
			reference_id varchar(100) NULL,
			actor_id bigint(20) unsigned NULL,
			note text NULL,
			metadata longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY product_created (product_id, created_at),
			KEY variation_created (variation_id, created_at),
			KEY movement_type (movement_type),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
	}

	private static function create_supplier_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$suppliers = $wpdb->prefix . 'stockino_suppliers';
		$relations = $wpdb->prefix . 'stockino_supplier_products';
		$charset   = $wpdb->get_charset_collate();
		$sql       = "CREATE TABLE {$suppliers} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			code varchar(100) NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			contact_name varchar(190) NULL,
			phone varchar(100) NULL,
			email varchar(190) NULL,
			website varchar(500) NULL,
			address text NULL,
			lead_time_days int unsigned NULL,
			notes text NULL,
			created_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY status (status),
			KEY name (name),
			KEY created_at (created_at)
		) {$charset};
		CREATE TABLE {$relations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			supplier_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			supplier_sku varchar(190) NULL,
			lead_time_days int unsigned NULL,
			minimum_order_quantity decimal(20,6) NULL,
			order_multiple decimal(20,6) NULL,
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY supplier_product (supplier_id, product_id),
			KEY supplier_id (supplier_id),
			KEY product_id (product_id)
		) {$charset};";

		dbDelta( $sql );
	}

	private static function create_purchasing_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$orders   = $wpdb->prefix . 'stockino_purchase_orders';
		$items    = $wpdb->prefix . 'stockino_purchase_order_items';
		$receipts = $wpdb->prefix . 'stockino_purchase_receipts';
		$received = $wpdb->prefix . 'stockino_purchase_receipt_items';
		$charset  = $wpdb->get_charset_collate();
		$sql      = "CREATE TABLE {$orders} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			po_number varchar(50) NOT NULL,
			supplier_id bigint(20) unsigned NOT NULL,
			supplier_name_snapshot varchar(190) NOT NULL,
			supplier_code_snapshot varchar(100) NULL,
			status varchar(30) NOT NULL DEFAULT 'draft',
			supplier_reference varchar(190) NULL,
			order_date date NULL,
			expected_date date NULL,
			notes text NULL,
			created_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			ordered_at datetime NULL,
			completed_at datetime NULL,
			cancelled_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY po_number (po_number),
			KEY supplier_id (supplier_id),
			KEY status (status),
			KEY created_at (created_at),
			KEY order_date (order_date),
			KEY expected_date (expected_date)
		) {$charset};
		CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			purchase_order_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			product_name_snapshot varchar(255) NOT NULL,
			sku_snapshot varchar(190) NULL,
			product_type_snapshot varchar(50) NOT NULL,
			variation_snapshot text NULL,
			supplier_sku_snapshot varchar(190) NULL,
			ordered_quantity decimal(20,6) NOT NULL,
			received_quantity decimal(20,6) NOT NULL DEFAULT 0,
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_product (purchase_order_id, product_id),
			KEY purchase_order_id (purchase_order_id),
			KEY product_id (product_id)
		) {$charset};
		CREATE TABLE {$receipts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			receipt_number varchar(50) NOT NULL,
			purchase_order_id bigint(20) unsigned NOT NULL,
			idempotency_key varchar(100) NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'processing',
			note text NULL,
			created_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			completed_at datetime NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY receipt_number (receipt_number),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY purchase_order_id (purchase_order_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};
		CREATE TABLE {$received} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			receipt_id bigint(20) unsigned NOT NULL,
			purchase_order_item_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			stock_owner_id bigint(20) unsigned NOT NULL,
			quantity_received decimal(20,6) NOT NULL,
			quantity_before decimal(20,6) NULL,
			quantity_after decimal(20,6) NULL,
			movement_id bigint(20) unsigned NULL,
			status varchar(30) NOT NULL DEFAULT 'pending',
			error_code varchar(100) NULL,
			error_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY receipt_order_item (receipt_id, purchase_order_item_id),
			KEY receipt_id (receipt_id),
			KEY purchase_order_item_id (purchase_order_item_id),
			KEY product_id (product_id),
			KEY movement_id (movement_id)
		) {$charset};";

		dbDelta( $sql );
	}

	private static function create_costing_tables(): void {

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$orders        = $wpdb->prefix . 'stockino_purchase_orders';
		$order_items   = $wpdb->prefix . 'stockino_purchase_order_items';
		$receipt_items = $wpdb->prefix . 'stockino_purchase_receipt_items';
		$costs         = $wpdb->prefix . 'stockino_inventory_costs';
		$movements     = $wpdb->prefix . 'stockino_inventory_cost_movements';
		$charset       = $wpdb->get_charset_collate();
		$sql           = "CREATE TABLE {$orders} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			currency_snapshot varchar(10) NULL,
			PRIMARY KEY  (id)
		) {$charset};
		CREATE TABLE {$order_items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ordered_unit_cost decimal(20,6) NULL,
			PRIMARY KEY  (id)
		) {$charset};
		CREATE TABLE {$receipt_items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actual_unit_cost decimal(20,6) NULL,
			currency_snapshot varchar(10) NULL,
			cost_movement_id bigint(20) unsigned NULL,
			costing_status varchar(30) NOT NULL DEFAULT 'pending',
			cost_error text NULL,
			PRIMARY KEY  (id),
			KEY cost_movement_id (cost_movement_id),
			KEY costing_status (costing_status)
		) {$charset};
		CREATE TABLE {$costs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stock_owner_id bigint(20) unsigned NOT NULL,
			average_unit_cost decimal(20,6) NOT NULL,
			currency_snapshot varchar(10) NOT NULL,
			last_receipt_item_id bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stock_owner_id (stock_owner_id),
			KEY last_receipt_item_id (last_receipt_item_id),
			KEY updated_at (updated_at)
		) {$charset};
		CREATE TABLE {$movements} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stock_owner_id bigint(20) unsigned NOT NULL,
			stock_owner_name_snapshot varchar(255) NOT NULL,
			source_product_id bigint(20) unsigned NOT NULL,
			source_variation_id bigint(20) unsigned NULL,
			source_product_name_snapshot varchar(255) NOT NULL,
			source_sku_snapshot varchar(190) NULL,
			purchase_order_id bigint(20) unsigned NULL,
			purchase_order_item_id bigint(20) unsigned NULL,
			receipt_id bigint(20) unsigned NULL,
			receipt_item_id bigint(20) unsigned NULL,
			movement_type varchar(30) NOT NULL,
			quantity_received decimal(20,6) NOT NULL DEFAULT 0,
			unit_cost decimal(20,6) NOT NULL,
			quantity_before decimal(20,6) NOT NULL,
			quantity_after decimal(20,6) NOT NULL,
			average_cost_before decimal(20,6) NULL,
			average_cost_after decimal(20,6) NOT NULL,
			inventory_value_before decimal(30,6) NOT NULL,
			inventory_value_after decimal(30,6) NOT NULL,
			currency_snapshot varchar(10) NOT NULL,
			reason text NULL,
			created_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY receipt_item_id (receipt_item_id),
			KEY owner_created (stock_owner_id, created_at),
			KEY purchase_order_id (purchase_order_id),
			KEY receipt_id (receipt_id),
			KEY movement_type (movement_type)
		) {$charset};";

		dbDelta( $sql );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET currency_snapshot = %s WHERE currency_snapshot IS NULL OR currency_snapshot = %s',
				$orders,
				get_woocommerce_currency(),
				''
			)
		);
	}

<<<<<<< HEAD
	private static function create_reorder_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$order_items = $wpdb->prefix . 'stockino_purchase_order_items';
		$settings    = $wpdb->prefix . 'stockino_reorder_settings';
		$charset     = $wpdb->get_charset_collate();
		$sql         = "CREATE TABLE {$order_items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reorder_stock_owner_id bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			KEY reorder_stock_owner_id (reorder_stock_owner_id)
		) {$charset};
		CREATE TABLE {$settings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stock_owner_id bigint(20) unsigned NOT NULL,
			custom_reorder_point decimal(20,6) NULL,
			custom_target_stock decimal(20,6) NULL,
			preferred_supplier_id bigint(20) unsigned NULL,
			preferred_product_id bigint(20) unsigned NULL,
			created_by bigint(20) unsigned NULL,
			updated_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stock_owner_id (stock_owner_id),
			KEY preferred_supplier_id (preferred_supplier_id),
			KEY preferred_product_id (preferred_product_id),
			KEY updated_at (updated_at)
		) {$charset};";

		dbDelta( $sql );
	}

	private static function create_marketplace_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$connections = $wpdb->prefix . 'stockino_marketplace_connections';
		$products    = $wpdb->prefix . 'stockino_marketplace_products';
		$logs        = $wpdb->prefix . 'stockino_marketplace_logs';
		$charset     = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$connections} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			marketplace varchar(50) NOT NULL,
			name varchar(190) NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'disconnected',
			credentials longtext NULL,
			vendor_id varchar(100) NULL,
			vendor_name varchar(190) NULL,
			vendor_identifier varchar(190) NULL,
			preparation_days int unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY marketplace_unique (marketplace),
			KEY status (status)
		) {$charset};
		CREATE TABLE {$products} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			parent_id bigint(20) unsigned NULL,
			marketplace varchar(50) NOT NULL,
			external_product_id varchar(100) NULL,
			external_vendor_id varchar(100) NULL,
			status varchar(30) NOT NULL DEFAULT 'not_published',
			category_external_id varchar(100) NULL,
			auto_sync_stock tinyint(1) NOT NULL DEFAULT 1,
			last_published_at datetime NULL,
			last_synced_at datetime NULL,
			last_error_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY conn_product (connection_id, product_id),
			KEY product_id (product_id),
			KEY status (status),
			KEY marketplace (marketplace)
		) {$charset};
		CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NULL,
			action varchar(50) NOT NULL,
			status varchar(30) NOT NULL,
			message text NOT NULL,
			payload_snapshot longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conn_created (connection_id, created_at),
			KEY prod_created (product_id, created_at),
			KEY action (action)
		) {$charset};";

		dbDelta( $sql );
	}
}

