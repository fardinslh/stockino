<?php

namespace Stockino\Marketplaces\Basalam;

use Stockino\Marketplaces\MarketplaceAdapterInterface;

final class BasalamAdapter implements MarketplaceAdapterInterface {
	private string $access_token;
	private ?string $vendor_id;
	private int $preparation_days;
	private int $timeout_seconds;
	private string $base_url;

	public function __construct(
		string $access_token,
		?string $vendor_id = null,
		int $preparation_days = 1,
		int $timeout_seconds = 15,
		string $base_url = 'https://openapi.basalam.com'
	) {
		$this->access_token     = trim( $access_token );
		$this->vendor_id        = $vendor_id ? trim( $vendor_id ) : null;
		$this->preparation_days = max( 1, $preparation_days );
		$this->timeout_seconds  = max( 5, $timeout_seconds );
		$this->base_url         = rtrim( $base_url, '/' );
	}

	public function get_kind(): string {
		return 'basalam';
	}

	public function is_mock(): bool {
		return false;
	}

	public function validate_connection(): array {
		if ( empty( $this->access_token ) ) {
			throw new \InvalidArgumentException( 'توکن دسترسی باسلام الزامی است.' );
		}

		$whoami = $this->request( 'GET', '/v1/whoami' );
		$user_id = $whoami['token']['user_id'] ?? null;

		if ( ! $user_id ) {
			throw new \RuntimeException( 'شناسه کاربری باسلام در توکن یافت نشد. لطفاً توکن دسترسی را بررسی کنید.' );
		}

		// Try to fetch vendors for the user
		$vendor_id   = $this->vendor_id;
		$vendor_name = 'غرفه باسلام';
		$identifier  = '';

		try {
			$vendors_data = $this->request( 'GET', '/v1/user/vendors' );
			$vendors      = isset( $vendors_data['data'] ) && is_array( $vendors_data['data'] )
				? $vendors_data['data']
				: ( is_array( $vendors_data ) ? $vendors_data : array() );

			if ( ! empty( $vendors ) ) {
				$first_vendor = $vendors[0];
				$vendor_id    = (string) ( $first_vendor['id'] ?? $vendor_id );
				$vendor_name  = (string) ( $first_vendor['name'] ?? $first_vendor['title'] ?? 'غرفه باسلام' );
				$identifier   = (string) ( $first_vendor['identifier'] ?? '' );
			}
		} catch ( \Exception $e ) {
			// If /v1/user/vendors is unavailable, fallback to supplied vendor_id
		}

		if ( ! $vendor_id ) {
			$vendor_id = (string) $user_id;
		}

		return array(
			'account_id'   => $vendor_id,
			'account_name' => $vendor_name,
			'identifier'   => $identifier,
			'status'       => 'active',
		);
	}

	public function get_categories(): array {
		$response = $this->request( 'GET', '/v1/categories' );
		$nodes    = isset( $response['data'] ) && is_array( $response['data'] )
			? $response['data']
			: ( is_array( $response ) ? $response : array() );

		return $this->flatten_categories( $nodes );
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes
	 * @param string|null $parent_id
	 * @return array<int, array{id: string, label: string, parentId: string|null}>
	 */
	private function flatten_categories( array $nodes, ?string $parent_id = null ): array {
		$result = array();
		foreach ( $nodes as $node ) {
			if ( ! isset( $node['id'], $node['title'] ) ) {
				continue;
			}
			$node_id  = (string) $node['id'];
			$result[] = array(
				'id'       => $node_id,
				'label'    => (string) $node['title'],
				'parentId' => $parent_id,
			);

			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$result = array_merge( $result, $this->flatten_categories( $node['children'], $node_id ) );
			}
		}
		return $result;
	}

	public function create_product( array $product, string $idempotency_key = '' ): array {
		$vendor_id = $this->get_effective_vendor_id();
		$payload   = $this->build_product_payload( $product );

		$response = $this->request( 'POST', "/v1/vendors/{$vendor_id}/products", $payload );
		$external_id = (string) ( $response['id'] ?? $response['data']['id'] ?? '' );

		if ( '' === $external_id ) {
			throw new \RuntimeException( 'شناسه محصول ساخته‌شده در پاسخ باسلام یافت نشد.' );
		}

		return array(
			'external_product_id' => $external_id,
			'request_id'          => null,
			'status_code'         => 201,
		);
	}

	public function update_product( string $external_product_id, array $product, string $idempotency_key = '' ): array {
		$payload  = $this->build_product_payload( $product );
		$response = $this->request( 'PUT', "/v1/products/{$external_product_id}", $payload );

		return array(
			'external_product_id' => $external_product_id,
			'request_id'          => null,
			'status_code'         => 200,
		);
	}

	public function update_stock( string $external_product_id, int|float $stock_quantity ): array {
		$payload = array(
			'stock' => max( 0, (int) $stock_quantity ),
		);
		$this->request( 'PATCH', "/v1/products/{$external_product_id}", $payload );

		return array(
			'external_product_id' => $external_product_id,
			'status_code'         => 200,
			'message'             => 'موجودی باسلام با موفقیت به‌روزرسانی شد.',
		);
	}

	public function fetch_orders( array $params = array() ): array {
		$vendor_id = $this->get_effective_vendor_id();
		$query     = http_build_query(
			array_filter(
				array(
					'page'     => (int) ( $params['page'] ?? 1 ),
					'per_page' => (int) ( $params['per_page'] ?? 20 ),
					'status'   => $params['status'] ?? null,
				)
			)
		);

		$path     = "/v1/vendors/{$vendor_id}/orders" . ( '' !== $query ? "?{$query}" : '' );
		$response = $this->request( 'GET', $path );

		$orders = isset( $response['data'] ) && is_array( $response['data'] )
			? $response['data']
			: ( is_array( $response ) ? $response : array() );

		return $orders;
	}

	public function update_order_tracking( string $external_order_id, string $tracking_code, string $carrier = 'post' ): array {
		$vendor_id = $this->get_effective_vendor_id();
		$path      = "/v1/vendors/{$vendor_id}/orders/{$external_order_id}/tracking";

		$payload = array(
			'tracking_code' => $tracking_code,
			'carrier'       => $carrier,
			'shipped_at'    => gmdate( 'c' ),
		);

		try {
			$res = $this->request( 'POST', $path, $payload );
			return array(
				'status_code' => 200,
				'message'     => 'کد رهگیری با موفقیت در باسلام ثبت شد.',
			);
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'خطا در ثبت کد رهگیری باسلام: ' . $e->getMessage() );
		}
	}

	/**
	 * @param array<string, mixed> $product
	 * @return array<string, mixed>
	 */
	private function build_product_payload( array $product ): array {
		$category_id = (int) ( $product['category_external_id'] ?? 0 );
		$weight      = max( 50, (int) ( $product['weight_grams'] ?? 200 ) );
		$price       = (int) ( $product['price'] ?? 0 );
		$stock       = max( 0, (int) ( $product['stock'] ?? 0 ) );
		$title       = (string) ( $product['title'] ?? '' );
		$description = (string) ( $product['description'] ?? $title );
		$brief       = (string) ( $product['short_description'] ?? mb_substr( $description, 0, 150 ) );

		return array(
			'name'             => $title,
			'brief'            => '' !== $brief ? $brief : $title,
			'description'      => '' !== $description ? $description : $title,
			'category_id'      => $category_id > 0 ? $category_id : 100,
			'primary_price'    => $price,
			'stock'            => $stock,
			'weight'           => $weight,
			'package_weight'   => $weight + 50,
			'preparation_days' => (int) ( $product['preparation_days'] ?? $this->preparation_days ),
			'sku'              => ! empty( $product['model'] ) ? (string) $product['model'] : null,
			'status'           => 2976, // Published
			'is_wholesale'     => false,
		);
	}

	private function get_effective_vendor_id(): string {
		if ( ! empty( $this->vendor_id ) ) {
			return $this->vendor_id;
		}

		$conn = $this->validate_connection();
		$this->vendor_id = $conn['account_id'];
		return $this->vendor_id;
	}

	/**
	 * @param string $method
	 * @param string $path
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>
	 * @throws \Exception
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		$url  = $this->base_url . $path;
		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout_seconds,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $this->access_token,
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'خطا در ارتباط با سرور باسلام: ' . $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body_str    = (string) wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body_str, true );
		$data        = is_array( $decoded ) ? $decoded : array();

		if ( $status_code >= 400 ) {
			$error_detail = $data['detail'] ?? $data['message'] ?? $data['error'] ?? null;
			if ( is_array( $error_detail ) ) {
				$error_detail = implode( ' | ', array_map( 'strval', $error_detail ) );
			}
			$error_message = $error_detail
				? sprintf( 'خطای باسلام (%d): %s', $status_code, (string) $error_detail )
				: sprintf( 'درخواست باسلام با کد خطای %d رد شد.', $status_code );

			throw new \RuntimeException( $error_message, $status_code );
		}

		return $data;
	}
}
