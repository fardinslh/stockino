<?php

namespace Stockino\Reorder;

use Stockino\Costing\FixedDecimal;
use Stockino\Database\ReorderRepository;
use Stockino\Database\ReorderSettingsRepository;
use Stockino\Purchasing\PurchaseOrderService;
use WP_Error;

final class ReorderService {
	private const MAX_BATCH = 50;

	public function __construct(
		private readonly ReorderRepository $repository,
		private readonly ReorderSettingsRepository $settings,
		private readonly ReorderCalculator $calculator,
		private readonly PurchaseOrderService $orders,
		private readonly ReorderLock $lock
	) {}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function list( int $page, int $per_page, string $search, string $state, int $supplier_id, int $category_id, string $sort ): array {
		$result          = $this->repository->paginate( $page, $per_page, sanitize_text_field( $search ), sanitize_key( $state ), $supplier_id, $category_id, sanitize_key( $sort ), $this->global_threshold() );
		$result['items'] = $this->calculate_many( $result['items'] );
		return $result;
	}

	/** @return array<string,int> */
	public function stats(): array {
		return $this->repository->stats( $this->global_threshold() );
	}

	/** @return array{suppliers:array<int,array<string,mixed>>,categories:array<int,array<string,mixed>>} */
	public function filters(): array {
		return $this->repository->filters();
	}

	/** @return array<string,mixed>|WP_Error */
	public function get( int $stock_owner_id ) {
		$row = $this->repository->find( $stock_owner_id, $this->global_threshold() );
		if ( ! $row ) {
			return $this->error( 'stockino_reorder_owner_not_found', 'The active WooCommerce stock owner does not exist.', 404 );
		}
		return $this->calculate_many( array( $row ) )[0];
	}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>}|WP_Error */
	public function incoming( int $stock_owner_id, int $page, int $per_page ) {
		if ( ! $this->repository->find( $stock_owner_id, $this->global_threshold() ) ) {
			return $this->error( 'stockino_reorder_owner_not_found', 'The active WooCommerce stock owner does not exist.', 404 );
		}
		return $this->repository->incoming( $stock_owner_id, $page, $per_page );
	}

	/** @return array<string,mixed>|WP_Error */
	public function get_settings( int $stock_owner_id ) {
		$recommendation = $this->get( $stock_owner_id );
		if ( is_wp_error( $recommendation ) ) {
			return $recommendation;
		}
		$candidates = $this->repository->supplier_candidates( array( $stock_owner_id ) );
		return array(
			'stock_owner_id'        => $stock_owner_id,
			'custom_reorder_point'  => $recommendation['custom_reorder_point'],
			'custom_target_stock'   => $recommendation['custom_target_stock'],
			'preferred_supplier_id' => $recommendation['preferred_supplier_id'],
			'preferred_product_id'  => $recommendation['preferred_product_id'],
			'updated_at'            => $recommendation['settings_updated_at'],
			'candidates'            => $candidates[ $stock_owner_id ] ?? array(),
		);
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function update_settings( int $stock_owner_id, array $input ) {
		if ( ! $this->lock->acquire_many( array( $stock_owner_id ) ) ) {
			return $this->error( 'stockino_reorder_owner_busy', 'Another replenishment operation is updating this stock owner. Try again shortly.', 409 );
		}
		try {
			$owner = $this->repository->find( $stock_owner_id, $this->global_threshold() );
			if ( ! $owner ) {
				return $this->error( 'stockino_reorder_owner_not_found', 'The active WooCommerce stock owner does not exist.', 404 );
			}
			$current = $this->settings->find( $stock_owner_id ) ?? array(
				'custom_reorder_point'  => null,
				'custom_target_stock'   => null,
				'preferred_supplier_id' => null,
				'preferred_product_id'  => null,
			);
			$data    = array(
				'custom_reorder_point'  => $this->decimal_field( $input, 'custom_reorder_point', $current['custom_reorder_point'] ),
				'custom_target_stock'   => $this->decimal_field( $input, 'custom_target_stock', $current['custom_target_stock'] ),
				'preferred_supplier_id' => array_key_exists( 'preferred_supplier_id', $input ) ? ( absint( $input['preferred_supplier_id'] ) ? absint( $input['preferred_supplier_id'] ) : null ) : $current['preferred_supplier_id'],
				'preferred_product_id'  => array_key_exists( 'preferred_product_id', $input ) ? ( absint( $input['preferred_product_id'] ) ? absint( $input['preferred_product_id'] ) : null ) : $current['preferred_product_id'],
			);
			if ( is_wp_error( $data['custom_reorder_point'] ) || is_wp_error( $data['custom_target_stock'] ) ) {
				return $this->error( 'stockino_invalid_reorder_decimal', 'Reorder settings must be non-negative decimals with no more than six places.', 400 );
			}
			$effective_point = $data['custom_reorder_point'] ?? FixedDecimal::normalize_cost( $owner['woo_low_stock_amount'] ?? null ) ?? $this->global_threshold();
			if ( null !== $data['custom_target_stock'] && ( null === $effective_point || FixedDecimal::compare( $data['custom_target_stock'], $effective_point ) < 0 ) ) {
				return $this->error( 'stockino_invalid_reorder_target', 'Custom target stock must be at least the effective reorder point.', 400 );
			}
			$has_supplier = null !== $data['preferred_supplier_id'];
			$has_product  = null !== $data['preferred_product_id'];
			if ( $has_supplier !== $has_product ) {
				return $this->error( 'stockino_invalid_preferred_supplier', 'Choose both a preferred supplier and its linked source product, or clear both.', 400 );
			}
			if ( $has_supplier && ! $this->candidate_exists( $stock_owner_id, (int) $data['preferred_supplier_id'], (int) $data['preferred_product_id'] ) ) {
				return $this->error( 'stockino_invalid_preferred_supplier', 'The preferred supplier must be active and linked to a current product sharing this stock owner.', 409 );
			}
			try {
				$this->settings->save( $stock_owner_id, $data );
			} catch ( \RuntimeException $exception ) {
				return $this->error( 'stockino_reorder_settings_failed', 'The reorder settings could not be saved.', 500 );
			}
			return $this->get_settings( $stock_owner_id );
		} finally {
			$this->lock->release_many( array( $stock_owner_id ) );
		}
	}

	/** @param mixed $raw_ids @return array<string,mixed>|WP_Error */
	public function create_purchase_orders( mixed $raw_ids ) {
		if ( ! is_array( $raw_ids ) || array() === $raw_ids || count( $raw_ids ) > self::MAX_BATCH ) {
			return $this->error( 'stockino_invalid_reorder_batch', 'Select between 1 and 50 recommendations.', 400 );
		}
		$ids = array_values( array_unique( array_map( 'absint', $raw_ids ) ) );
		if ( in_array( 0, $ids, true ) || count( $ids ) !== count( $raw_ids ) ) {
			return $this->error( 'stockino_invalid_reorder_batch', 'Every selected recommendation must have a unique valid stock-owner ID.', 400 );
		}
		if ( ! $this->lock->acquire_many( $ids ) ) {
			return $this->error( 'stockino_reorder_owner_busy', 'Another replenishment operation is processing one of these stock owners. Try again shortly.', 409 );
		}
		try {
			$rows            = $this->repository->find_many( $ids, $this->global_threshold() );
			$recommendations = array_column( $this->calculate_many( $rows ), null, 'stock_owner_id' );
			$active          = array_flip( $this->repository->active_generated_replenishment_ids( $ids ) );
			$groups          = array();
			$skipped         = array();
			foreach ( $ids as $owner_id ) {
				$recommendation = $recommendations[ $owner_id ] ?? null;
				if ( null === $recommendation ) {
					$skipped[] = $this->skip( $owner_id, 'not_found' );
					continue;
				}
				if ( isset( $active[ $owner_id ] ) ) {
					$skipped[] = $this->skip( $owner_id, 'already_replenishing' );
					continue;
				}
				if ( 'reorder_needed' !== $recommendation['state'] || null === $recommendation['supplier'] || FixedDecimal::compare( $recommendation['recommended_quantity'], '0.000000' ) <= 0 ) {
					$skipped[] = $this->skip( $owner_id, 'stale_or_unresolved', $recommendation['state'] );
					continue;
				}
				$supplier_id              = (int) $recommendation['supplier']['supplier_id'];
				$groups[ $supplier_id ][] = $recommendation;
			}

			$created = array();
			foreach ( $groups as $supplier_id => $recommendations_for_supplier ) {
				$lead_times = array_filter( array_column( array_column( $recommendations_for_supplier, 'supplier' ), 'effective_lead_time_days' ), static fn( $value ): bool => null !== $value );
				$lead_time  = array() === $lead_times ? null : max( $lead_times );
				$expected   = null === $lead_time ? '' : ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+' . $lead_time . ' days' )->format( 'Y-m-d' );
				$items      = array_map(
					static fn( array $recommendation ): array => array(
						'product_id'             => $recommendation['supplier']['source_product_id'],
						'ordered_quantity'       => $recommendation['recommended_quantity'],
						'ordered_unit_cost'      => $recommendation['supplier']['default_ordered_unit_cost'],
						'reorder_stock_owner_id' => $recommendation['stock_owner_id'],
						'notes'                  => 'Created from Stockino deterministic reorder recommendation.',
					),
					$recommendations_for_supplier
				);
				$order      = $this->orders->create_reorder_draft(
					array(
						'supplier_id'   => $supplier_id,
						'expected_date' => $expected,
						'notes'         => 'Draft generated from Stockino Phase 5 reorder recommendations. Review before ordering.',
					),
					$items
				);
				if ( is_wp_error( $order ) ) {
					foreach ( $recommendations_for_supplier as $recommendation ) {
						$skipped[] = $this->skip( (int) $recommendation['stock_owner_id'], 'purchase_order_failed', $order->get_error_code() );
					}
					continue;
				}
				$created[] = array(
					'purchase_order'  => $order,
					'stock_owner_ids' => array_map( static fn( array $item ): int => (int) $item['stock_owner_id'], $recommendations_for_supplier ),
				);
			}
			return array(
				'created' => $created,
				'skipped' => $skipped,
			);
		} finally {
			$this->lock->release_many( $ids );
		}
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	private function calculate_many( array $rows ): array {
		$ids        = array_map( static fn( array $row ): int => (int) $row['stock_owner_id'], $rows );
		$candidates = $this->repository->supplier_candidates( $ids );
		$threshold  = $this->global_threshold();
		return array_map(
			fn( array $row ): array => $this->calculator->calculate( $row, $candidates[ (int) $row['stock_owner_id'] ] ?? array(), $threshold ),
			$rows
		);
	}

	private function candidate_exists( int $owner_id, int $supplier_id, int $product_id ): bool {
		$candidates = $this->repository->supplier_candidates( array( $owner_id ) );
		foreach ( $candidates[ $owner_id ] ?? array() as $candidate ) {
			if ( $supplier_id === (int) $candidate['supplier_id'] && $product_id === (int) $candidate['source_product_id'] ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $input @return string|null|WP_Error */
	private function decimal_field( array $input, string $key, mixed $current ) {
		if ( ! array_key_exists( $key, $input ) ) {
			return $current;
		}
		if ( null === $input[ $key ] || ( is_string( $input[ $key ] ) && '' === trim( $input[ $key ] ) ) ) {
			return null;
		}
		$normalized = FixedDecimal::normalize_cost( $input[ $key ] );
		return $normalized ?? $this->error( 'stockino_invalid_reorder_decimal', 'Invalid reorder decimal.', 400 );
	}

	private function global_threshold(): ?string {
		return FixedDecimal::normalize_cost( get_option( 'woocommerce_notify_low_stock_amount', '' ) );
	}

	/** @return array<string,mixed> */
	private function skip( int $stock_owner_id, string $reason, ?string $detail = null ): array {
		return array(
			'stock_owner_id' => $stock_owner_id,
			'reason'         => $reason,
			'detail'         => $detail,
		);
	}

	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
