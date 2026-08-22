<?php

declare(strict_types=1);

namespace Stockino\Marketplaces;

use Stockino\Database\MarketplaceRepository;
use Stockino\Events\StockinoEvent;
use Stockino\Events\StockinoEventDispatcher;
use Stockino\Orders\OrderSyncService;

final class WebhookService {
	private MarketplaceRepository $repository;
	private OrderSyncService $order_sync;

	public function __construct(
		MarketplaceRepository $repository,
		OrderSyncService $order_sync
	) {
		$this->repository = $repository;
		$this->order_sync = $order_sync;
	}

	/**
	 * @param string $marketplace
	 * @param array<string, mixed> $payload
	 * @param string $signature
	 * @return array{status: string, message: string}
	 */
	public function handle_webhook( string $marketplace, array $payload, string $signature = '' ): array {
		$event_type = (string) ( $payload['event'] ?? $payload['type'] ?? 'unknown' );

		// If order created or updated
		if ( str_contains( $event_type, 'order' ) || isset( $payload['order'] ) || isset( $payload['order_id'] ) ) {
			$conn       = $this->repository->get_connection_by_marketplace( $marketplace );
			$conn_id    = $conn ? (int) $conn['id'] : 0;
			$order_data = isset( $payload['order'] ) && is_array( $payload['order'] ) ? $payload['order'] : $payload;
			try {
				$normalizer = new \Stockino\Orders\OrderNormalizer();
				$canonical  = $normalizer->normalize( $order_data, $marketplace );
				$sync_res   = $this->order_sync->sync_canonical_order( $canonical, $conn_id );

				StockinoEventDispatcher::dispatch(
					new StockinoEvent(
						StockinoEvent::ORDER_CREATED,
						array(
							'marketplace' => $marketplace,
							'wc_order_id' => $sync_res['wc_order_id'],
						)
					)
				);

				return array(
					'status'  => 'success',
					'message' => sprintf( 'سفارش وب‌هوک با موفقیت پردازش و به سفارش #%d ووکامرس تبدیل شد.', $sync_res['wc_order_id'] ),
				);
			} catch ( \Exception $e ) {
				if ( $conn_id > 0 ) {
					$this->repository->add_log(
						$conn_id,
						null,
						'webhook_order',
						'failure',
						'خطا در پردازش وب‌هوک سفارش: ' . $e->getMessage()
					);
				}
				throw $e;
			}
		}

		return array(
			'status'  => 'ignored',
			'message' => sprintf( 'رویداد %s دریافت شد اما نیاز به عملیات فوری نداشت.', $event_type ),
		);
	}
}
