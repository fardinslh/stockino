<?php

declare(strict_types=1);

namespace Stockino\Publishing;

use Stockino\Database\MarketplaceRepository;
use Stockino\Domain\Product\CanonicalProduct;
use Stockino\Domain\Publication\PublicationResult;
use Stockino\Domain\Publication\PublicationState;
use Stockino\Marketplaces\MarketplaceAdapterInterface;
use Stockino\Marketplaces\MarketplaceConnectionService;
use WC_Product;

final class PublishingEngine {
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
	 * @param PublicationControlOptions|null $options
	 * @return PublicationResult
	 */
	public function publish( int $product_id, string $marketplace = 'basalam', ?PublicationControlOptions $options = null ): PublicationResult {
		$options = $options ?? new PublicationControlOptions();

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return new PublicationResult(
				false,
				PublicationState::STATUS_FAILED,
				sprintf( 'محصول ووکامرس با شناسه %d یافت نشد.', $product_id ),
				null,
				array( 'PRODUCT_NOT_FOUND' ),
				404
			);
		}

		$conn = $this->repository->get_connection_by_marketplace( $marketplace );
		if ( ! $conn ) {
			return new PublicationResult(
				false,
				PublicationState::STATUS_FAILED,
				sprintf( 'تنظیمات اتصال بازارگاه %s یافت نشد.', $marketplace ),
				null,
				array( 'MARKETPLACE_NOT_CONFIGURED' ),
				400
			);
		}

		$conn_id = (int) $conn['id'];
		$link    = $this->repository->get_product_link( $conn_id, $product_id );

		// Dry Run / Preview Mode
		if ( $options->dry_run ) {
			$preview_data = $this->build_payload( $product, $options, $conn );
			return new PublicationResult(
				true,
				PublicationState::STATUS_READY,
				'پیش‌نمایش انتشار با موفقیت آماده شد (حالت آزمایشی).',
				$link['external_product_id'] ?? null,
				array(),
				200
			);
		}

		// Update to PENDING
		$this->repository->update_product_status( $conn_id, $product_id, 'publishing' );

		try {
			$adapter             = $this->connection_service->create_adapter( $marketplace );
			$external_product_id = $link['external_product_id'] ?? null;
			$payload             = $this->build_payload( $product, $options, $conn );
			$idempotency_key     = sprintf( 'pub_%d_%d_%s', $product_id, $conn_id, gmdate( 'YmdH' ) );

			if ( ! empty( $external_product_id ) ) {
				$res = $adapter->update_product( (string) $external_product_id, $payload, $idempotency_key );
			} else {
				$res                 = $adapter->create_product( $payload, $idempotency_key );
				$external_product_id = $res['external_product_id'];
			}

			$this->repository->save_product_link(
				array(
					'connection_id'        => $conn_id,
					'product_id'           => $product_id,
					'parent_id'            => $product->get_parent_id() ?: null,
					'marketplace'          => $marketplace,
					'external_product_id'  => $external_product_id,
					'category_external_id' => $payload['category_external_id'],
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
				sprintf( 'محصول «%s» در %s منتشر شد (شناسه خارجی: %s).', $product->get_name(), $marketplace, (string) $external_product_id )
			);

			return new PublicationResult(
				true,
				PublicationState::STATUS_PUBLISHED,
				'محصول با موفقیت در بازارگاه منتشر شد.',
				$external_product_id,
				array(),
				200
			);
		} catch ( \Exception $e ) {
			$err = $e->getMessage();
			$this->repository->update_product_status( $conn_id, $product_id, 'error', null, $err );
			$this->repository->add_log(
				$conn_id,
				$product_id,
				'publish',
				'failure',
				sprintf( 'خطا در انتشار محصول «%s»: %s', $product->get_name(), $err )
			);

			return new PublicationResult(
				false,
				PublicationState::STATUS_FAILED,
				$err,
				null,
				array( $err ),
				500
			);
		}
	}

	/**
	 * @param WC_Product $product
	 * @param PublicationControlOptions $options
	 * @param array<string, mixed> $conn
	 * @return array<string, mixed>
	 */
	private function build_payload( WC_Product $product, PublicationControlOptions $options, array $conn ): array {
		$title = $product->get_name();
		$desc  = $options->sync_description ? wp_strip_all_tags( $product->get_description() ?: $title ) : $title;
		$brief = $options->sync_description ? wp_strip_all_tags( $product->get_short_description() ?: mb_substr( $desc, 0, 150 ) ) : $title;

		$price = $options->sync_price
			? (int) round( (float) ( $product->get_price() ?: ( $product->get_regular_price() ?: 0 ) ) )
			: 10000;

		$stock = $options->sync_inventory
			? max( 0, (int) ( $product->get_stock_quantity() ?? 0 ) )
			: 0;

		$weight_raw   = (float) $product->get_weight();
		$weight_grams = $weight_raw > 0 ? (int) round( $weight_raw * 1000 ) : 250;

		$cat_id = $options->category_override ?: '100';
		$prep   = $options->preparation_days ?: (int) ( $conn['preparation_days'] ?? 1 );

		return array(
			'title'                => $title,
			'description'          => $desc,
			'short_description'    => $brief,
			'category_external_id' => $cat_id,
			'price'                => max( 1000, $price ),
			'stock'                => $stock,
			'weight_grams'         => $weight_grams,
			'model'                => $product->get_sku() ?: (string) $product->get_id(),
			'preparation_days'     => $prep,
		);
	}
}
