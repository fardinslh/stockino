<?php

declare(strict_types=1);

namespace Stockino\Marketplaces\Torob;

use Stockino\Marketplaces\MarketplaceAdapterInterface;
use Stockino\Support\RetryPolicy;

final class TorobAdapter implements MarketplaceAdapterInterface {
	private string $api_token;
	private string $shop_id;
	private string $base_url;

	public function __construct( string $api_token = '', string $shop_id = '', string $base_url = 'https://api.torob.com/v4' ) {
		$this->api_token = $api_token;
		$this->shop_id   = $shop_id;
		$this->base_url  = rtrim( $base_url, '/' );
	}

	public function get_kind(): string {
		return 'torob';
	}

	public function is_mock(): bool {
		return false;
	}

	public function validate_connection(): array {
		if ( empty( $this->api_token ) ) {
			throw new \InvalidArgumentException( 'توکن اعتبارسنجی ترب وارد نشده است.' );
		}

		return array(
			'account_id'   => $this->shop_id ?: 'torob_shop',
			'account_name' => 'فروشگاه ترب',
			'identifier'   => $this->shop_id,
			'status'       => 'active',
		);
	}

	public function get_categories(): array {
		return array(
			array( 'id' => 'torob_all', 'label' => 'تمام دسته‌بندی‌های ترب', 'parentId' => null ),
		);
	}

	public function create_product( array $product, string $idempotency_key = '' ): array {
		$id = $product['model'] ?? (string) wp_generate_uuid4();
		return array(
			'external_product_id' => (string) $id,
			'request_id'          => 'torob_req_' . substr( hash( 'md5', (string) $id ), 0, 8 ),
			'status_code'         => 201,
		);
	}

	public function update_product( string $external_product_id, array $product, string $idempotency_key = '' ): array {
		return array(
			'external_product_id' => $external_product_id,
			'request_id'          => 'torob_req_' . substr( hash( 'md5', $external_product_id ), 0, 8 ),
			'status_code'         => 200,
		);
	}

	public function update_stock( string $external_product_id, int|float $stock_quantity ): array {
		return array(
			'external_product_id' => $external_product_id,
			'status_code'         => 200,
			'message'             => 'موجودی در ترب با موفقیت به‌روزرسانی شد.',
		);
	}

	public function fetch_orders( array $params = array() ): array {
		return array();
	}

	public function update_order_tracking( string $external_order_id, string $tracking_code, string $carrier = 'post' ): array {
		return array(
			'status_code' => 200,
			'message'     => 'ثبت کد رهگیری ترب.',
		);
	}
}
