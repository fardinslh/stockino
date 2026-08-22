<?php

namespace Stockino\Marketplaces;

use Stockino\Database\MarketplaceRepository;
use WC_Product;

final class PublicationService {
	private MarketplaceRepository $repository;
	private MarketplaceConnectionService $connection_service;

	public function __construct(
		MarketplaceRepository $repository,
		MarketplaceConnectionService $connection_service
	) {
		$this->repository         = $repository;
		$this->connection_service = $connection_service;
	}

	/**
	 * @param int $product_id
	 * @param string $marketplace
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public function publish_product( int $product_id, string $marketplace = 'basalam', array $options = array() ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			throw new \InvalidArgumentException( sprintf( 'محصول ووکامرس با شناسه %d یافت نشد.', $product_id ) );
		}

		$conn = $this->repository->get_connection_by_marketplace( $marketplace );
		if ( ! $conn ) {
			throw new \RuntimeException( sprintf( 'تنظیمات اتصال بازارگاه %s یافت نشد.', $marketplace ) );
		}

		$conn_id = (int) $conn['id'];
		$link    = $this->repository->get_product_link( $conn_id, $product_id );

		$this->repository->update_product_status( $conn_id, $product_id, 'publishing' );

		try {
			$adapter             = $this->connection_service->create_adapter( $marketplace );
			$external_product_id = $link['external_product_id'] ?? null;
			$category_id         = (string) ( $options['category_external_id'] ?? ( $link['category_external_id'] ?? ( $options['category_id'] ?? '100' ) ) );
			$prep_days           = (int) ( $options['preparation_days'] ?? ( $conn['preparation_days'] ?? 1 ) );

			$product_data = $this->map_product( $product, $category_id, $prep_days, $options );
			$idempotency  = sprintf( 'pub_%d_%d_%s', $product_id, $conn_id, gmdate( 'YmdH' ) );

			if ( ! empty( $external_product_id ) ) {
				$result = $adapter->update_product( (string) $external_product_id, $product_data, $idempotency );
			} else {
				$result              = $adapter->create_product( $product_data, $idempotency );
				$external_product_id = $result['external_product_id'];
			}

			$this->repository->save_product_link(
				array(
					'connection_id'        => $conn_id,
					'product_id'           => $product_id,
					'parent_id'            => $product->get_parent_id() ? $product->get_parent_id() : null,
					'marketplace'          => $marketplace,
					'external_product_id'  => $external_product_id,
					'category_external_id' => $category_id,
					'status'               => 'published',
					'last_published_at'    => gmdate( 'Y-m-d H:i:s' ),
					'last_synced_at'       => gmdate( 'Y-m-d H:i:s' ),
					'last_error_message'   => null,
				)
			);

			$this->repository->add_log(
				$conn_id,
				$product_id,
				'publish',
				'success',
				sprintf( 'محصول «%s» با موفقیت در بازارگاه %s منتشر شد (شناسه خارجی: %s).', $product->get_name(), $marketplace, (string) $external_product_id )
			);

			return array(
				'product_id'          => $product_id,
				'marketplace'         => $marketplace,
				'status'              => 'published',
				'external_product_id' => $external_product_id,
				'message'             => 'محصول با موفقیت منتشر شد.',
			);
		} catch ( \Exception $e ) {
			$error_message = $e->getMessage();
			$this->repository->update_product_status( $conn_id, $product_id, 'error', null, $error_message );
			$this->repository->add_log(
				$conn_id,
				$product_id,
				'publish',
				'failure',
				sprintf( 'خطا در انتشار محصول «%s»: %s', $product->get_name(), $error_message )
			);

			throw new \RuntimeException( sprintf( 'خطا در انتشار محصول: %s', $error_message ), 0, $e );
		}
	}

	/**
	 * @param array<int, int> $product_ids
	 * @param string $marketplace
	 * @param array<string, mixed> $options
	 * @return array{published: array<int, array<string, mixed>>, failed: array<int, array{product_id: int, error: string}>}
	 */
	public function publish_batch( array $product_ids, string $marketplace = 'basalam', array $options = array() ): array {
		$published = array();
		$failed    = array();

		foreach ( $product_ids as $id ) {
			try {
				$published[] = $this->publish_product( (int) $id, $marketplace, $options );
			} catch ( \Exception $e ) {
				$failed[] = array(
					'product_id' => (int) $id,
					'error'      => $e->getMessage(),
				);
			}
		}

		return array(
			'published' => $published,
			'failed'    => $failed,
		);
	}

	/**
	 * @param int $product_id
	 * @param int|float|null $new_quantity
	 * @return array<int, array<string, mixed>>
	 */
	public function sync_stock_for_product( int $product_id, $new_quantity = null ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$stock   = null !== $new_quantity ? (float) $new_quantity : (float) ( $product->get_stock_quantity() ?? 0 );
		$links   = $this->repository->get_published_links_for_product( $product_id );
		$results = array();

		foreach ( $links as $link ) {
			$conn_id             = (int) $link['connection_id'];
			$marketplace         = (string) $link['marketplace_kind'];
			$external_product_id = (string) ( $link['external_product_id'] ?? '' );

			if ( empty( $external_product_id ) ) {
				continue;
			}

			try {
				$adapter = $this->connection_service->create_adapter( $marketplace );
				$adapter->update_stock( $external_product_id, $stock );

				$this->repository->save_product_link(
					array(
						'connection_id'      => $conn_id,
						'product_id'         => $product_id,
						'marketplace'        => $marketplace,
						'status'             => 'published',
						'last_synced_at'     => gmdate( 'Y-m-d H:i:s' ),
						'last_error_message' => null,
					)
				);

				$this->repository->add_log(
					$conn_id,
					$product_id,
					'sync_stock',
					'success',
					sprintf( 'موجودی محصول «%s» در بازارگاه %s به مقدار %s به‌روزرسانی شد.', $product->get_name(), $marketplace, (string) $stock )
				);

				$results[] = array(
					'product_id'          => $product_id,
					'marketplace'         => $marketplace,
					'external_product_id' => $external_product_id,
					'synced_stock'        => $stock,
					'status'              => 'success',
				);
			} catch ( \Exception $e ) {
				$err = $e->getMessage();
				$this->repository->update_product_status( $conn_id, $product_id, 'sync_failed', $external_product_id, $err );
				$this->repository->add_log(
					$conn_id,
					$product_id,
					'sync_stock',
					'failure',
					sprintf( 'خطا در همگام‌سازی موجودی محصول «%s» در %s: %s', $product->get_name(), $marketplace, $err )
				);

				$results[] = array(
					'product_id'          => $product_id,
					'marketplace'         => $marketplace,
					'external_product_id' => $external_product_id,
					'status'              => 'failed',
					'error'               => $err,
				);
			}
		}

		return $results;
	}

	/**
	 * @param array<int, int> $product_ids
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	public function sync_stock_batch( array $product_ids ): array {
		$results = array();
		foreach ( $product_ids as $id ) {
			$results[ (int) $id ] = $this->sync_stock_for_product( (int) $id );
		}
		return $results;
	}

	/**
	 * @param WC_Product $product
	 * @param string $category_id
	 * @param int $preparation_days
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function map_product( WC_Product $product, string $category_id, int $preparation_days, array $options = array() ): array {
		$price         = $product->get_price();
		$regular_price = $product->get_regular_price();
		$price         = (float) ( $price ? $price : ( $regular_price ? $regular_price : '0' ) );
		// Basalam uses Toman integers
		$price_toman = max( 1000, (int) round( $price ) );

		$weight_raw   = (float) $product->get_weight();
		$weight_grams = $weight_raw > 0 ? (int) round( $weight_raw * 1000 ) : 250;

		$description       = wp_strip_all_tags( $product->get_description() );
		$short_description = wp_strip_all_tags( $product->get_short_description() );
		$title             = $product->get_name();

		if ( empty( $description ) ) {
			$description = $title;
		}
		if ( empty( $short_description ) ) {
			$short_description = mb_substr( $description, 0, 150 );
		}

		return array(
			'title'                => $options['title'] ?? $title,
			'description'          => $options['description'] ?? $description,
			'short_description'    => $options['short_description'] ?? $short_description,
			'category_external_id' => $category_id,
			'price'                => isset( $options['price'] ) ? (int) $options['price'] : $price_toman,
			'stock'                => max( 0, (int) ( $product->get_stock_quantity() ?? 0 ) ),
			'weight_grams'         => isset( $options['weight_grams'] ) ? (int) $options['weight_grams'] : $weight_grams,
			'model'                => $product->get_sku() ? $product->get_sku() : (string) $product->get_id(),
			'preparation_days'     => $preparation_days,
		);
	}
}
