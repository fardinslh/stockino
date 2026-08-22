<?php

namespace Stockino\Marketplaces;

interface MarketplaceAdapterInterface {
	/** @return string (e.g. 'basalam', 'mock') */
	public function get_kind(): string;

	public function is_mock(): bool;

	/**
	 * @return array{
	 *   account_id: string,
	 *   account_name: string,
	 *   identifier?: string,
	 *   status: string
	 * }
	 * @throws \Exception
	 */
	public function validate_connection(): array;

	/**
	 * @return array<int, array{id: string, label: string, parentId: string|null}>
	 * @throws \Exception
	 */
	public function get_categories(): array;

	/**
	 * @param array{
	 *   title: string,
	 *   description: string,
	 *   short_description?: string,
	 *   category_external_id: string,
	 *   price: string|int,
	 *   stock: int,
	 *   weight_grams?: int,
	 *   model?: string|null,
	 *   image_urls?: array<int, string>,
	 *   preparation_days?: int
	 * } $product
	 * @param string $idempotency_key
	 * @return array{
	 *   external_product_id: string,
	 *   request_id: string|null,
	 *   status_code: int
	 * }
	 * @throws \Exception
	 */
	public function create_product( array $product, string $idempotency_key = '' ): array;

	/**
	 * @param string $external_product_id
	 * @param array{
	 *   title: string,
	 *   description: string,
	 *   short_description?: string,
	 *   category_external_id: string,
	 *   price: string|int,
	 *   stock: int,
	 *   weight_grams?: int,
	 *   model?: string|null,
	 *   image_urls?: array<int, string>,
	 *   preparation_days?: int
	 * } $product
	 * @param string $idempotency_key
	 * @return array{
	 *   external_product_id: string,
	 *   request_id: string|null,
	 *   status_code: int
	 * }
	 * @throws \Exception
	 */
	public function update_product( string $external_product_id, array $product, string $idempotency_key = '' ): array;

	/**
	 * @param string $external_product_id
	 * @param int|float $stock_quantity
	 * @return array{
	 *   external_product_id: string,
	 *   status_code: int,
	 *   message: string
	 * }
	 * @throws \Exception
	 */
	public function update_stock( string $external_product_id, int|float $stock_quantity ): array;

	/**
	 * @param array<string, mixed> $params
	 * @return array<int, array<string, mixed>>
	 * @throws \Exception
	 */
	public function fetch_orders( array $params = array() ): array;

	/**
	 * @param string $external_order_id
	 * @param string $tracking_code
	 * @param string $carrier
	 * @return array{status_code: int, message: string}
	 * @throws \Exception
	 */
	public function update_order_tracking( string $external_order_id, string $tracking_code, string $carrier = 'post' ): array;
}
