<?php

declare(strict_types=1);

namespace Stockino\Orders;

use WC_Order;

final class OrderinoBridge {
	public function register(): void {
		// Display marketplace source badge in WooCommerce admin order lists
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_column' ), 20, 2 );
		add_filter( 'manage_shop_order_posts_columns', array( $this, 'add_order_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_order_column' ), 20, 2 );

		// Hook into Orderino / WooCommerce status changes
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 20, 4 );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function add_order_column( array $columns ): array {
		$new_columns = array();
		foreach ( $columns as $key => $title ) {
			$new_columns[ $key ] = $title;
			if ( 'order_status' === $key || 'order_number' === $key ) {
				$new_columns['stockino_channel'] = __( 'کانال فروش', 'stockino' );
			}
		}
		if ( ! isset( $new_columns['stockino_channel'] ) ) {
			$new_columns['stockino_channel'] = __( 'کانال فروش', 'stockino' );
		}
		return $new_columns;
	}

	public function render_order_column( string $column, WC_Order $order ): void {
		if ( 'stockino_channel' !== $column ) {
			return;
		}

		$marketplace = $order->get_meta( '_stockino_marketplace' );
		$ext_id      = $order->get_meta( '_stockino_marketplace_order_id' );

		if ( ! empty( $marketplace ) ) {
			$label = 'basalam' === $marketplace ? 'باسلام' : $marketplace;
			printf(
				'<span class="badge" style="background:#e0e7ff;color:#3730a3;padding:3px 7px;border-radius:4px;font-size:11px;font-weight:600;">%s (#%s)</span>',
				esc_html( $label ),
				esc_html( (string) $ext_id )
			);
		} else {
			echo '<span style="color:#94a3b8;font-size:11px;">فروشگاه مستقیم</span>';
		}
	}

	public function render_legacy_order_column( string $column, int $post_id ): void {
		if ( 'stockino_channel' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order instanceof WC_Order ) {
			$this->render_order_column( $column, $order );
		}
	}

	public function on_order_status_changed( int $order_id, string $status_from, string $status_to, WC_Order $order ): void {
		$marketplace = $order->get_meta( '_stockino_marketplace' );
		$ext_id      = $order->get_meta( '_stockino_marketplace_order_id' );

		if ( empty( $marketplace ) || empty( $ext_id ) ) {
			return;
		}

		// When order status is updated by admin or Orderino, emit action
		do_action( 'stockino_marketplace_order_status_changed', $marketplace, $ext_id, $status_to, $order );
	}
}
