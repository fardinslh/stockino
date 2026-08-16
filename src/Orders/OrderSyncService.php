<?php

declare(strict_types=1);

namespace Stockino\Orders;

use Stockino\Database\MarketplaceRepository;
use Stockino\Domain\Order\CanonicalOrder;
use Stockino\Domain\Order\CanonicalOrderItem;
use Stockino\Marketplaces\MarketplaceConnectionService;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

final class OrderSyncService {
	private MarketplaceRepository $repository;
	private MarketplaceConnectionService $connections;
	private OrderNormalizer $normalizer;

	public function __construct(
		MarketplaceRepository $repository,
		MarketplaceConnectionService $connections,
		?OrderNormalizer $normalizer = null
	) {
		$this->repository  = $repository;
		$this->connections = $connections;
		$this->normalizer  = $normalizer ?? new OrderNormalizer();
	}

	/**
	 * @param string $marketplace
	 * @param array<string, mixed> $params
	 * @return array{
	 *   total_fetched: int,
	 *   created_count: int,
	 *   updated_count: int,
	 *   failed_count: int,
	 *   orders: array<int, array<string, mixed>>,
	 *   errors: array<int, array{external_order_id: string, error: string}>
	 * }
	 */
	public function fetch_and_sync_orders( string $marketplace = 'basalam', array $params = array() ): array {
		$adapter = $this->connections->create_adapter( $marketplace );
		$conn    = $this->repository->get_connection_by_marketplace( $marketplace );
		$conn_id = $conn ? (int) $conn['id'] : 0;

		$raw_orders = $adapter->fetch_orders( $params );

		$created_count = 0;
		$updated_count = 0;
		$failed_count  = 0;
		$synced_orders = array();
		$errors        = array();

		foreach ( $raw_orders as $raw ) {
			try {
				$canonical = $this->normalizer->normalize( $raw, $marketplace );
				$result    = $this->sync_canonical_order( $canonical, $conn_id );

				if ( 'created' === $result['action'] ) {
					++$created_count;
				} elseif ( 'updated' === $result['action'] ) {
					++$updated_count;
				}

				$synced_orders[] = array_merge(
					$canonical->to_array(),
					array( 'wc_order_id' => $result['wc_order_id'], 'action' => $result['action'] )
				);
			} catch ( \Exception $e ) {
				++$failed_count;
				$ext_id = (string) ( $raw['id'] ?? $raw['order_id'] ?? 'unknown' );
				$errors[] = array(
					'external_order_id' => $ext_id,
					'error'             => $e->getMessage(),
				);

				if ( $conn_id > 0 ) {
					$this->repository->add_log(
						$conn_id,
						null,
						'sync_order',
						'failure',
						sprintf( 'خطا در همگام‌سازی سفارش %s: %s', $ext_id, $e->getMessage() )
					);
				}
			}
		}

		return array(
			'total_fetched' => count( $raw_orders ),
			'created_count' => $created_count,
			'updated_count' => $updated_count,
			'failed_count'  => $failed_count,
			'orders'        => $synced_orders,
			'errors'        => $errors,
		);
	}

	/**
	 * @param CanonicalOrder $order
	 * @param int $conn_id
	 * @return array{action: 'created'|'updated'|'skipped', wc_order_id: int}
	 */
	public function sync_canonical_order( CanonicalOrder $order, int $conn_id = 0 ): array {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return array( 'action' => 'skipped', 'wc_order_id' => 0 );
		}

		$existing_order_id = $this->find_existing_wc_order_id( $order->external_order_id, $order->marketplace );

		if ( $existing_order_id > 0 ) {
			$wc_order = wc_get_order( $existing_order_id );
			if ( $wc_order instanceof WC_Order ) {
				$wc_status = $this->map_to_wc_status( $order->status );
				if ( $wc_order->get_status() !== $wc_status ) {
					$wc_order->set_status( $wc_status, sprintf( 'تغییر وضعیت از بازارگاه %s به %s', $order->marketplace, $order->status ) );
					$wc_order->save();
				}
				return array( 'action' => 'updated', 'wc_order_id' => $existing_order_id );
			}
		}

		// Create New WooCommerce Order
		$wc_order = wc_create_order();
		if ( ! $wc_order instanceof WC_Order ) {
			throw new \RuntimeException( 'امکان ایجاد سفارش جدید در ووکامرس وجود ندارد.' );
		}

		// Set addresses
		$wc_order->set_address( $order->shipping_address->to_array(), 'shipping' );
		$wc_order->set_address( $order->shipping_address->to_array(), 'billing' );

		// Set customer note
		if ( $order->customer_note ) {
			$wc_order->set_customer_note( $order->customer_note );
		}

		// Add Line items
		foreach ( $order->items as $item ) {
			$product_to_add = null;
			if ( $item->wc_variation_id && function_exists( 'wc_get_product' ) ) {
				$product_to_add = wc_get_product( $item->wc_variation_id );
			} elseif ( $item->wc_product_id && function_exists( 'wc_get_product' ) ) {
				$product_to_add = wc_get_product( $item->wc_product_id );
			}

			if ( $product_to_add ) {
				$item_id = $wc_order->add_product(
					$product_to_add,
					(int) $item->quantity,
					array(
						'subtotal' => $item->total_price,
						'total'    => $item->total_price,
					)
				);
			} else {
				// Custom fee / line item
				$item_obj = new WC_Order_Item_Product();
				$item_obj->set_name( $item->product_name );
				$item_obj->set_quantity( (int) $item->quantity );
				$item_obj->set_subtotal( (string) $item->total_price );
				$item_obj->set_total( (string) $item->total_price );
				$wc_order->add_item( $item_obj );
			}
		}

		// Add Shipping item if applicable
		if ( $order->shipping_amount > 0 ) {
			$shipping_item = new WC_Order_Item_Shipping();
			$shipping_item->set_method_title( $order->shipping_method ?: 'ارسال بازارگاه' );
			$shipping_item->set_total( (string) $order->shipping_amount );
			$wc_order->add_item( $shipping_item );
		}

		// Set Payment Method
		$payment_title = 'basalam' === $order->marketplace ? 'پرداخت آنلاین باسلام' : ( 'بازارگاه ' . $order->marketplace );
		$wc_order->set_payment_method( 'marketplace_' . $order->marketplace );
		$wc_order->set_payment_method_title( $payment_title );

		// Set Status
		$wc_status = $this->map_to_wc_status( $order->status );
		$wc_order->set_status( $wc_status );

		// Set Stockino & Orderino Meta
		$wc_order->update_meta_data( '_stockino_marketplace', $order->marketplace );
		$wc_order->update_meta_data( '_stockino_marketplace_order_id', $order->external_order_id );
		$wc_order->update_meta_data( '_stockino_synced_at', gmdate( 'Y-m-d H:i:s' ) );
		// Orderino source tracking metadata
		$wc_order->update_meta_data( '_orderino_source', 'marketplace_' . $order->marketplace );
		$wc_order->update_meta_data( '_orderino_source_label', $payment_title );

		if ( $order->shipping_tracking_number ) {
			$wc_order->update_meta_data( '_shipping_tracking_number', $order->shipping_tracking_number );
			$wc_order->update_meta_data( '_orderino_tracking_code', $order->shipping_tracking_number );
		}

		$wc_order->calculate_totals( false );
		$wc_order->save();

		$wc_order_id = $wc_order->get_id();

		// Add order note
		$wc_order->add_order_note(
			sprintf(
				'سفارش از بازارگاه «%s» با شناسه %s با موفقیت دریافت و همگام‌سازی شد.',
				$order->marketplace,
				$order->external_order_id
			)
		);

		if ( $conn_id > 0 ) {
			$this->repository->add_log(
				$conn_id,
				null,
				'sync_order',
				'success',
				sprintf( 'سفارش %s بازارگاه %s به سفارش #%d ووکامرس تبدیل شد.', $order->external_order_id, $order->marketplace, $wc_order_id )
			);
		}

		do_action( 'stockino_order_synced', $wc_order_id, $order );

		return array( 'action' => 'created', 'wc_order_id' => $wc_order_id );
	}

	private function find_existing_wc_order_id( string $external_order_id, string $marketplace ): int {
		global $wpdb;
		$sql = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_stockino_marketplace_order_id' AND meta_value = %s LIMIT 1";
		$id  = $wpdb->get_var( $wpdb->prepare( $sql, $external_order_id ) );

		if ( $id ) {
			return (int) $id;
		}

		// Also check WooCommerce HPOS table if active
		$hpos_table = $wpdb->prefix . 'wc_orders_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
			$hpos_sql = "SELECT order_id FROM {$hpos_table} WHERE meta_key = '_stockino_marketplace_order_id' AND meta_value = %s LIMIT 1";
			$hpos_id  = $wpdb->get_var( $wpdb->prepare( $hpos_sql, $external_order_id ) );
			if ( $hpos_id ) {
				return (int) $hpos_id;
			}
		}

		return 0;
	}

	private function map_to_wc_status( string $canonical_status ): string {
		return match ( $canonical_status ) {
			CanonicalOrder::STATUS_PENDING => 'pending',
			CanonicalOrder::STATUS_PROCESSING, CanonicalOrder::STATUS_CONFIRMED => 'processing',
			CanonicalOrder::STATUS_SHIPPED, CanonicalOrder::STATUS_DELIVERED => 'completed',
			CanonicalOrder::STATUS_CANCELLED => 'cancelled',
			CanonicalOrder::STATUS_RETURNED => 'refunded',
			default => 'processing',
		};
	}
}
