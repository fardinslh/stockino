<?php

declare(strict_types=1);

namespace Stockino\Orders;

use Stockino\Database\MarketplaceRepository;
use Stockino\Marketplaces\MarketplaceConnectionService;
use WC_Order;

final class FulfillmentDispatchService {
	private MarketplaceRepository $repository;
	private MarketplaceConnectionService $connections;

	public function __construct(
		MarketplaceRepository $repository,
		MarketplaceConnectionService $connections
	) {
		$this->repository  = $repository;
		$this->connections = $connections;
	}

	public function register(): void {
		// Listen to Order status changes (e.g. Completed or Shipped)
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 25, 4 );

		// Listen to Orderino shipment creation / tracking code update hook if available
		add_action( 'orderino_shipment_created', array( $this, 'on_orderino_shipment_created' ), 20, 2 );
	}

	public function on_order_status_changed( int $order_id, string $from, string $to, WC_Order $order ): void {
		if ( ! in_array( $to, array( 'completed', 'shipped', 'shipping' ), true ) ) {
			return;
		}

		$marketplace = (string) $order->get_meta( '_stockino_marketplace' );
		$ext_id      = (string) $order->get_meta( '_stockino_marketplace_order_id' );

		if ( empty( $marketplace ) || empty( $ext_id ) ) {
			return;
		}

		// Look for tracking code from Orderino or WooCommerce meta
		$tracking = (string) $order->get_meta( '_orderino_tracking_code' );
		if ( ! $tracking ) {
			$tracking = (string) $order->get_meta( '_shipping_tracking_number' );
		}
		if ( ! $tracking ) {
			$tracking = (string) $order->get_meta( 'tracking_code' );
		}
		if ( ! $tracking ) {
			$tracking = 'TRACK-' . $order_id;
		}
		$this->dispatch_fulfillment( $order, $marketplace, $ext_id, $tracking );
	}

	/**
	 * @param int $order_id
	 * @param array<string, mixed> $shipment_data
	 */
	public function on_orderino_shipment_created( int $order_id, array $shipment_data ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$marketplace = (string) $order->get_meta( '_stockino_marketplace' );
		$ext_id      = (string) $order->get_meta( '_stockino_marketplace_order_id' );

		if ( empty( $marketplace ) || empty( $ext_id ) ) {
			return;
		}

		$tracking = (string) ( $shipment_data['tracking_code'] ?? $shipment_data['barcode'] ?? '' );
		if ( '' !== $tracking ) {
			$this->dispatch_fulfillment( $order, $marketplace, $ext_id, $tracking );
		}
	}

	public function dispatch_fulfillment( WC_Order $order, string $marketplace, string $external_order_id, string $tracking_code, string $carrier = 'post' ): void {
		// Avoid dispatching duplicate fulfillment updates
		$already_dispatched = $order->get_meta( '_stockino_fulfillment_dispatched' );
		if ( '1' === $already_dispatched && $order->get_meta( '_stockino_last_dispatched_tracking' ) === $tracking_code ) {
			return;
		}

		$conn    = $this->repository->get_connection_by_marketplace( $marketplace );
		$conn_id = $conn ? (int) $conn['id'] : 0;

		try {
			$adapter = $this->connections->create_adapter( $marketplace );
			$res     = $adapter->update_order_tracking( $external_order_id, $tracking_code, $carrier );

			$order->update_meta_data( '_stockino_fulfillment_dispatched', '1' );
			$order->update_meta_data( '_stockino_last_dispatched_tracking', $tracking_code );
			$order->update_meta_data( '_stockino_fulfillment_dispatched_at', gmdate( 'Y-m-d H:i:s' ) );
			$order->save();

			$order->add_order_note(
				sprintf(
					'اطلاعات ارسال و کد رهگیری «%s» با موفقیت به بازارگاه %s ارسال شد.',
					$tracking_code,
					'basalam' === $marketplace ? 'باسلام' : $marketplace
				)
			);

			if ( $conn_id > 0 ) {
				$this->repository->add_log(
					$conn_id,
					null,
					'dispatch_fulfillment',
					'success',
					sprintf( 'کد رهگیری %s برای سفارش %s در %s با موفقیت ثبت شد.', $tracking_code, $external_order_id, $marketplace )
				);
			}
		} catch ( \Exception $e ) {
			$order->add_order_note(
				sprintf(
					'خطا در ارسال کد رهگیری به %s: %s',
					$marketplace,
					$e->getMessage()
				)
			);

			if ( $conn_id > 0 ) {
				$this->repository->add_log(
					$conn_id,
					null,
					'dispatch_fulfillment',
					'failure',
					sprintf( 'خطا در ثبت کد رهگیری سفارش %s در %s: %s', $external_order_id, $marketplace, $e->getMessage() )
				);
			}
		}
	}
}
