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
}
