<?php
/**
 * Plugin Name: Stockino
 * Plugin URI: https://github.com/fardinslh/stockino
 * Description: Purchasing and inventory operations for WooCommerce.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author: Stockino
 * Text Domain: stockino
 * Domain Path: /languages
 * WC requires at least: 8.5
 * WC tested up to: 11.0
 */

defined( 'ABSPATH' ) || exit;

define( 'STOCKINO_VERSION', '0.1.0' );
define( 'STOCKINO_DB_VERSION', '1.0.0' );
define( 'STOCKINO_FILE', __FILE__ );
define( 'STOCKINO_PATH', plugin_dir_path( __FILE__ ) );
define( 'STOCKINO_URL', plugin_dir_url( __FILE__ ) );

if ( PHP_VERSION_ID < 80200 ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Stockino requires PHP 8.2 or newer.', 'stockino' )
			);
		}
	);
	return;
}

$stockino_autoload = STOCKINO_PATH . 'vendor/autoload.php';
if ( file_exists( $stockino_autoload ) ) {
	require_once $stockino_autoload;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'Stockino\\';
			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}

			$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class_name, strlen( $prefix ) ) );
			$file     = STOCKINO_PATH . 'src/' . $relative . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( Stockino\Database\Installer::class, 'activate' ) );
add_action( 'plugins_loaded', array( Stockino\Plugin::class, 'boot' ), 20 );
