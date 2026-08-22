<?php

namespace Stockino\Admin;

final class AdminPage {
	private string $inventory_hook   = '';
	private string $supplier_hook    = '';
	private string $purchasing_hook  = '';
	private string $valuation_hook   = '';
	private string $reorder_hook     = '';
	private string $marketplace_hook = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'script_loader_tag', array( $this, 'mark_script_as_module' ), 10, 3 );
	}

	public function add_menu(): void {
		$this->inventory_hook = (string) add_menu_page(
			__( 'Inventory Management', 'stockino' ),
			__( 'Stockino', 'stockino' ),
			'manage_woocommerce',
			'stockino',
			array( $this, 'render' ),
			'dashicons-archive',
			56
		);

		add_submenu_page(
			'stockino',
			__( 'Inventory Management', 'stockino' ),
			__( 'Inventory', 'stockino' ),
			'manage_woocommerce',
			'stockino',
			array( $this, 'render' )
		);

		$this->supplier_hook = (string) add_submenu_page(
			'stockino',
			__( 'Supplier Management', 'stockino' ),
			__( 'Suppliers', 'stockino' ),
			'manage_woocommerce',
			'stockino-suppliers',
			array( $this, 'render' )
		);

		$this->purchasing_hook = (string) add_submenu_page(
			'stockino',
			__( 'Purchase Orders', 'stockino' ),
			__( 'Purchase Orders', 'stockino' ),
			'manage_woocommerce',
			'stockino-purchase-orders',
			array( $this, 'render' )
		);

		$this->valuation_hook = (string) add_submenu_page(
			'stockino',
			__( 'Inventory Valuation', 'stockino' ),
			__( 'Valuation', 'stockino' ),
			'manage_woocommerce',
			'stockino-valuation',
			array( $this, 'render' )
		);

		$this->reorder_hook = (string) add_submenu_page(
			'stockino',
			__( 'Reorder Recommendations', 'stockino' ),
			__( 'Reorder Recommendations', 'stockino' ),
			'manage_woocommerce',
			'stockino-reorder',
			array( $this, 'render' )
		);

		$this->marketplace_hook = (string) add_submenu_page(
			'stockino',
			__( 'Marketplaces & Publishing', 'stockino' ),
			__( 'Marketplaces', 'stockino' ),
			'manage_woocommerce',
			'stockino-marketplaces',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		echo '<div class="wrap"><div id="stockino-admin-root"></div></div>';
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( $this->inventory_hook, $this->supplier_hook, $this->purchasing_hook, $this->valuation_hook, $this->reorder_hook, $this->marketplace_hook ), true ) ) {
			return;
		}

		$entry = $this->manifest_entry();
		if ( null === $entry ) {
			wp_enqueue_style( 'stockino-admin-fallback', false, array(), STOCKINO_VERSION );
			wp_add_inline_style( 'stockino-admin-fallback', '#stockino-admin-root{padding:24px;direction:rtl}' );
			return;
		}

		$handle = 'stockino-admin';
		// Hashed Vite filenames provide cache busting. A query-string version would duplicate shared ES modules across lazy chunks.
		wp_enqueue_script( $handle, STOCKINO_URL . 'dist/' . $entry['file'], array( 'wp-i18n' ), null, true );
		wp_set_script_translations( $handle, 'stockino', STOCKINO_PATH . 'languages' );
		wp_localize_script(
			$handle,
			'stockinoSettings',
			array(
				'root'     => esc_url_raw( rest_url( 'stockino/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'locale'   => determine_locale(),
				'currency' => get_woocommerce_currency(),
				'page'     => $hook_suffix === $this->supplier_hook ? 'suppliers' : ( $hook_suffix === $this->purchasing_hook ? 'purchase-orders' : ( $hook_suffix === $this->valuation_hook ? 'valuation' : ( $hook_suffix === $this->reorder_hook ? 'reorder' : ( $hook_suffix === $this->marketplace_hook ? 'marketplaces' : 'inventory' ) ) ) ),
				'adminUrl' => admin_url( 'admin.php' ),
			)
		);

		foreach ( $entry['css'] ?? array() as $index => $css ) {
			wp_enqueue_style( $handle . '-' . $index, STOCKINO_URL . 'dist/' . $css, array(), STOCKINO_VERSION );
		}
	}

	public function mark_script_as_module( string $tag, string $handle, string $src ): string {
		if ( 'stockino-admin' !== $handle ) {
			return $tag;
		}

		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- The script is enqueued above; only its module tag is replaced.
		return sprintf( '<script type="module" src="%s" id="stockino-admin-js"></script>', esc_url( $src ) );
	}

	/** @return array{file:string,css?:array<int,string>}|null */
	private function manifest_entry(): ?array {
		$manifest_path = STOCKINO_PATH . 'dist/.vite/manifest.json';
		if ( ! is_readable( $manifest_path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Trusted local build manifest.
		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		$entry    = is_array( $manifest ) ? ( $manifest['admin/src/main.tsx'] ?? null ) : null;
		if ( null === $entry && is_array( $manifest ) ) {
			foreach ( $manifest as $candidate ) {
				if ( is_array( $candidate ) && ! empty( $candidate['isEntry'] ) && isset( $candidate['file'] ) ) {
					$entry = $candidate;
					break;
				}
			}
		}

		return is_array( $entry ) && isset( $entry['file'] ) ? $entry : null;
	}
}
