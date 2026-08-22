<?php

namespace Stockino\Marketplaces\Mock;

use Stockino\Marketplaces\MarketplaceAdapterInterface;

final class MockMarketplaceAdapter implements MarketplaceAdapterInterface {
	private string $behavior;
	private int $timeout_ms;

	public function __construct( string $behavior = 'success', int $timeout_ms = 1000 ) {
		$this->behavior   = in_array( $behavior, array( 'success', 'failure', 'timeout' ), true ) ? $behavior : 'success';
		$this->timeout_ms = $timeout_ms;
	}

	public function get_kind(): string {
		return 'mock';
	}

	public function is_mock(): bool {
		return true;
	}

	public function validate_connection(): array {
		if ( 'failure' === $this->behavior ) {
			throw new \RuntimeException( 'اتصال به بازارگاه آزمایشی با شکست مواجه شد.' );
		}
		if ( 'timeout' === $this->behavior ) {
			throw new \RuntimeException( 'درخواست به بازارگاه آزمایشی با انقضای زمان روبرو شد.' );
		}

		return array(
			'account_id'   => 'mock-vendor-101',
			'account_name' => 'غرفه آزمایشی استوکینو',
			'identifier'   => 'stockino-demo-shop',
			'status'       => 'active',
		);
	}

	public function get_categories(): array {
		return array(
			array(
				'id'       => 'mock-100',
				'label'    => 'کالای دیجیتال و الکترونیک',
				'parentId' => null,
			),
			array(
				'id'       => 'mock-101',
				'label'    => 'لوازم جانبی موبایل',
				'parentId' => 'mock-100',
			),
			array(
				'id'       => 'mock-200',
				'label'    => 'مد و پوشاک',
				'parentId' => null,
			),
			array(
				'id'       => 'mock-201',
				'label'    => 'پوشاک مردانه',
				'parentId' => 'mock-200',
			),
			array(
				'id'       => 'mock-300',
				'label'    => 'خانه و آشپزخانه',
				'parentId' => null,
			),
		);
	}

	public function create_product( array $product, string $idempotency_key = '' ): array {
		$this->check_behavior();

		$seed = '' !== $idempotency_key ? $idempotency_key : ( $product['title'] . microtime( true ) );
		$id   = 'mock_' . substr( hash( 'sha256', $seed ), 0, 12 );

		return array(
			'external_product_id' => $id,
			'request_id'          => 'req_' . substr( hash( 'md5', $id ), 0, 8 ),
			'status_code'         => 201,
		);
	}

	public function update_product( string $external_product_id, array $product, string $idempotency_key = '' ): array {
		$this->check_behavior();

		return array(
			'external_product_id' => $external_product_id,
			'request_id'          => 'req_upd_' . substr( hash( 'md5', $external_product_id ), 0, 8 ),
			'status_code'         => 200,
		);
	}

	public function update_stock( string $external_product_id, int|float $stock_quantity ): array {
		$this->check_behavior();

		return array(
			'external_product_id' => $external_product_id,
			'status_code'         => 200,
			'message'             => sprintf( 'موجودی محصول %s با موفقیت به %s تغییر یافت.', $external_product_id, (string) $stock_quantity ),
		);
	}

	public function fetch_orders( array $params = array() ): array {
		$this->check_behavior();

		return array(
			array(
				'id'               => 'mock_order_1001',
				'status'           => 'processing',
				'total_amount'     => 285000,
				'shipping_amount'  => 35000,
				'discount_amount'  => 0,
				'currency'         => 'IRT',
				'created_at'       => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
				'customer'         => array(
					'id'    => 'cust_901',
					'name'  => 'سارا رضایی',
					'phone' => '09123456789',
					'email' => 'sara.rezaei@example.test',
				),
				'shipping_address' => array(
					'first_name' => 'سارا',
					'last_name'  => 'رضایی',
					'address_1'  => 'خیابان آزادی، کوچه بهار، پلاک ۱۲',
					'city'       => 'تهران',
					'state'      => 'تهران',
					'postcode'   => '1458963214',
					'phone'      => '09123456789',
				),
				'items'            => array(
					array(
						'id'          => 'item_101',
						'title'       => 'محصول تست استوکینو',
						'sku'         => 'STK-001',
						'quantity'    => 2,
						'unit_price'  => 125000,
						'total_price' => 250000,
					),
				),
			),
		);
	}

	public function update_order_tracking( string $external_order_id, string $tracking_code, string $carrier = 'post' ): array {
		$this->check_behavior();

		return array(
			'status_code' => 200,
			'message'     => sprintf( 'کد رهگیری %s برای سفارش آزمایشی %s با موفقیت ثبت شد.', $tracking_code, $external_order_id ),
		);
	}

	private function check_behavior(): void {
		if ( 'failure' === $this->behavior ) {
			throw new \RuntimeException( 'خطای شبیه‌سازی‌شده در ارتباط با بازارگاه آزمایشی.' );
		}
		if ( 'timeout' === $this->behavior ) {
			throw new \RuntimeException( 'پاسخی در مهلت تعیین‌شده از بازارگاه آزمایشی دریافت نشد (Timeout).' );
		}
	}
}
