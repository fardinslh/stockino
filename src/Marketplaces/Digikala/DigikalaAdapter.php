<?php

declare(strict_types=1);

namespace Stockino\Marketplaces\Digikala;

use Stockino\Marketplaces\MarketplaceAdapterInterface;
use Stockino\Support\RetryPolicy;

final class DigikalaAdapter implements MarketplaceAdapterInterface {
	private string $api_key;
	private string $seller_id;
	private string $base_url;

	public function __construct( string $api_key = '', string $seller_id = '', string $base_url = 'https://seller.digikala.com/api/v1' ) {
		$this->api_key   = $api_key;
		$this->seller_id = $seller_id;
		$this->base_url  = rtrim( $base_url, '/' );
	}

	public function get_kind(): string {
		return 'digikala';
	}

	public function is_mock(): bool {
		return false;
	}

	public function validate_connection(): array {
		if ( empty( $this->api_key ) ) {
			throw new \InvalidArgumentException( 'کلید دسترسی API فروشندگان دیجی‌کالا وارد نشده است.' );
		}

		$response = $this->request( 'GET', '/seller/profile' );
		$seller   = $response['data'] ?? array();

		return array(
			'account_id'   => (string) ( $seller['id'] ?? $this->seller_id ?: 'dk_seller' ),
			'account_name' => (string) ( $seller['title'] ?? $seller['name'] ?? 'فروشگاه دیجی‌کالا' ),
			'identifier'   => (string) ( $seller['code'] ?? '' ),
			'status'       => 'active',
		);
	}

	public function get_categories(): array {
		$response = $this->request( 'GET', '/categories' );
		$cats     = $response['data'] ?? array();

		if ( empty( $cats ) ) {
			return array(
				array( 'id' => 'dk_cat_1', 'label' => 'کالای دیجیتال', 'parentId' => null ),
				array( 'id' => 'dk_cat_2', 'label' => 'مد و پوشاک', 'parentId' => null ),
				array( 'id' => 'dk_cat_3', 'label' => 'خانه و آشپزخانه', 'parentId' => null ),
			);
		}

		$formatted = array();
		foreach ( $cats as $c ) {
			$formatted[] = array(
				'id'       => (string) ( $c['id'] ?? '' ),
				'label'    => (string) ( $c['title'] ?? $c['name'] ?? '' ),
				'parentId' => isset( $c['parent_id'] ) ? (string) $c['parent_id'] : null,
			);
		}
		return $formatted;
	}

	public function create_product( array $product, string $idempotency_key = '' ): array {
		$payload = $this->format_digikala_payload( $product );
		$res     = $this->request( 'POST', '/seller/variants', $payload );

		$variant_id = (string) ( $res['data']['variant_id'] ?? $res['data']['id'] ?? wp_generate_uuid4() );

		return array(
			'external_product_id' => $variant_id,
			'request_id'          => $res['request_id'] ?? null,
			'status_code'         => 201,
		);
	}

	public function update_product( string $external_product_id, array $product, string $idempotency_key = '' ): array {
		$payload = $this->format_digikala_payload( $product );
		$res     = $this->request( 'PUT', "/seller/variants/{$external_product_id}", $payload );

		return array(
			'external_product_id' => $external_product_id,
			'request_id'          => $res['request_id'] ?? null,
			'status_code'         => 200,
		);
	}

	public function update_stock( string $external_product_id, int|float $stock_quantity ): array {
		$payload = array(
			'site_stock' => max( 0, (int) $stock_quantity ),
		);

		$this->request( 'PUT', "/seller/variants/{$external_product_id}/stock", $payload );

		return array(
			'external_product_id' => $external_product_id,
			'status_code'         => 200,
			'message'             => 'موجودی تنوع در دیجی‌کالا با موفقیت به‌روزرسانی شد.',
		);
	}

	public function fetch_orders( array $params = array() ): array {
		$page     = (int) ( $params['page'] ?? 1 );
		$per_page = (int) ( $params['per_page'] ?? 20 );

		$res = $this->request( 'GET', "/seller/orders?page={$page}&size={$per_page}" );
		$orders = $res['data']['orders'] ?? $res['data'] ?? array();

		$normalized = array();
		foreach ( ( is_array( $orders ) ? $orders : array() ) as $ord ) {
			$normalized[] = array(
				'id'              => (string) ( $ord['order_id'] ?? $ord['id'] ?? '' ),
				'status'          => $ord['status'] ?? 'processing',
				'total_amount'    => (float) ( $ord['total_price'] ?? $ord['price'] ?? 0 ),
				'shipping_amount' => (float) ( $ord['shipping_cost'] ?? 0 ),
				'discount_amount' => (float) ( $ord['discount'] ?? 0 ),
				'currency'        => 'IRT',
				'created_at'      => $ord['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
				'customer'        => array(
					'id'    => (string) ( $ord['customer']['id'] ?? '' ),
					'name'  => (string) ( $ord['customer']['name'] ?? 'مشتری دیجی‌کالا' ),
					'phone' => (string) ( $ord['customer']['phone'] ?? '' ),
				),
				'shipping_address' => array(
					'first_name' => (string) ( $ord['address']['first_name'] ?? 'مشتری' ),
					'last_name'  => (string) ( $ord['address']['last_name'] ?? 'دیجی‌کالا' ),
					'address_1'  => (string) ( $ord['address']['address'] ?? '' ),
					'city'       => (string) ( $ord['address']['city'] ?? 'تهران' ),
					'state'      => (string) ( $ord['address']['state'] ?? 'تهران' ),
					'postcode'   => (string) ( $ord['address']['postal_code'] ?? '0000000000' ),
				),
				'items' => array_map(
					static fn( array $it ) => array(
						'id'          => (string) ( $it['id'] ?? '' ),
						'title'       => (string) ( $it['title'] ?? 'کالای دیجی‌کالا' ),
						'sku'         => (string) ( $it['seller_code'] ?? $it['sku'] ?? '' ),
						'quantity'    => (float) ( $it['quantity'] ?? 1 ),
						'unit_price'  => (float) ( $it['price'] ?? 0 ),
						'total_price' => (float) ( ( $it['price'] ?? 0 ) * ( $it['quantity'] ?? 1 ) ),
					),
					is_array( $ord['items'] ?? null ) ? $ord['items'] : array()
				),
			);
		}

		return $normalized;
	}

	public function update_order_tracking( string $external_order_id, string $tracking_code, string $carrier = 'post' ): array {
		$payload = array(
			'tracking_number' => $tracking_code,
			'carrier_name'    => $carrier,
		);

		$this->request( 'POST', "/seller/orders/{$external_order_id}/shipping", $payload );

		return array(
			'status_code' => 200,
			'message'     => 'کد مرسوله در دیجی‌کالا ثبت شد.',
		);
	}

	/**
	 * @param array<string, mixed> $product
	 * @return array<string, mixed>
	 */
	private function format_digikala_payload( array $product ): array {
		return array(
			'seller_code'      => $product['model'] ?? null,
			'price'            => max( 1000, (int) ( $product['price'] ?? 0 ) ),
			'site_stock'       => max( 0, (int) ( $product['stock'] ?? 0 ) ),
			'lead_time'        => (int) ( $product['preparation_days'] ?? 1 ),
			'is_active'        => true,
		);
	}

	/**
	 * @param string $method
	 * @param string $path
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		$url = $this->base_url . $path;

		return RetryPolicy::execute(
			function () use ( $method, $url, $body ) {
				$headers = array(
					'Authorization' => $this->api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				);

				$args = array(
					'method'      => $method,
					'headers'     => $headers,
					'timeout'     => 20,
					'redirection' => 3,
				);

				if ( null !== $body ) {
					$args['body'] = wp_json_encode( $body );
				}

				$response = wp_remote_request( $url, $args );

				if ( is_wp_error( $response ) ) {
					throw new \RuntimeException( 'خطا در ارتباط با دیجی‌کالا: ' . $response->get_error_message() );
				}

				$status = (int) wp_remote_retrieve_response_code( $response );
				$raw    = (string) wp_remote_retrieve_body( $response );
				$data   = (array) json_decode( $raw, true );

				if ( $status >= 400 ) {
					$msg = $data['message'] ?? $data['error'] ?? "خطای سرور دیجی‌کالا ({$status})";
					throw new \RuntimeException( (string) $msg, $status );
				}

				return $data;
			},
			3,
			500
		);
	}
}
