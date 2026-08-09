<?php

namespace Stockino;

use Stockino\Admin\AdminPage;
use Stockino\Database\Installer;
use Stockino\REST\RestApi;

final class Plugin {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		load_plugin_textdomain( 'stockino', false, dirname( plugin_basename( STOCKINO_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'woocommerce_notice' ) );
			return;
		}

		self::$booted = true;
		Installer::maybe_upgrade();
		( new AdminPage() )->register();
		( new RestApi() )->register();

		do_action( 'stockino_loaded' );
	}

	public static function woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Stockino requires WooCommerce. Install and activate WooCommerce to use inventory features.', 'stockino' )
		);
	}
}
