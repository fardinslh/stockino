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
}
