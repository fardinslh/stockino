<?php

namespace Stockino\Purchasing;

use Stockino\Database\PurchaseOrderRepository;
use Stockino\Database\PurchaseReceiptRepository;
use Stockino\Inventory\InventoryMutation;
use Stockino\Suppliers\PurchasingQuantity;
use WC_Product;
use WP_Error;

final class PurchaseReceivingService {
	private const RECEIVABLE = array( 'ordered', 'partially_received' );

	public function __construct(
		private readonly PurchaseOrderRepository $orders,
		private readonly PurchaseReceiptRepository $receipts,
		private readonly InventoryMutation $mutations,
		private readonly ReceiveLock $lock
	) {}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function receive( int $order_id, array $input ) {
		$key = sanitize_text_field( (string) ( $input['idempotency_key'] ?? '' ) );
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,100}$/', $key ) ) {
			return $this->error( 'stockino_invalid_idempotency_key', 'Provide a valid idempotency key for this receiving operation.', 400 );
		}
		$existing = $this->receipts->find_by_key( $key );
		if ( $existing ) {
			return $this->existing_receipt( $existing, $order_id );
		}
		$note = sanitize_textarea_field( (string) ( $input['note'] ?? '' ) );
		if ( strlen( $note ) > 1000 ) {
			return $this->error( 'stockino_receipt_note_too_long', 'The receipt note must be 1000 characters or fewer.', 400 );
		}
		try {
			$receipt_id = $this->receipts->create(
				array(
					'purchase_order_id' => $order_id,
					'idempotency_key'   => $key,
					'note'              => '' === $note ? null : $note,
				)
			);
		} catch ( \RuntimeException $exception ) {
			if ( 'duplicate_idempotency_key' === $exception->getMessage() ) {
				$existing = $this->receipts->find_by_key( $key );
				return $existing ? $this->existing_receipt( $existing, $order_id ) : $this->storage_error();
			}
			return $this->storage_error();
		}

		if ( ! $this->lock->acquire( $order_id ) ) {
			$this->receipts->delete_processing( $receipt_id );
			return $this->error( 'stockino_purchase_order_busy', 'Another receipt is being processed for this purchase order. Try again shortly.', 409 );
		}
		try {
			$order = $this->orders->find( $order_id );
			if ( ! $order || ! in_array( $order['status'], self::RECEIVABLE, true ) ) {
				$this->receipts->delete_processing( $receipt_id );
				return $this->error( 'stockino_purchase_order_not_receivable', 'This purchase order cannot receive goods in its current state.', 409 );
			}
			$validated = $this->validate_lines( $order_id, $input['items'] ?? array() );
			if ( is_wp_error( $validated ) ) {
				$this->receipts->delete_processing( $receipt_id );
				return $validated;
			}
			return $this->process( $order_id, $receipt_id, $validated, $note );
		} finally {
			$this->lock->release( $order_id );
		}
	}

	/** @return array<string,mixed>|WP_Error */
	public function get( int $receipt_id ) {
		$receipt = $this->receipts->find( $receipt_id );
		return $receipt ? $receipt : $this->error( 'stockino_purchase_receipt_not_found', 'The purchase receipt does not exist.', 404 );
	}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function list_for_order( int $order_id, int $page, int $per_page ): array {
		return $this->receipts->paginate_for_order( $order_id, $page, $per_page );
	}

	/** @param mixed $raw_lines @return array<int,array<string,mixed>>|WP_Error */
	private function validate_lines( int $order_id, mixed $raw_lines ) {
		if ( ! is_array( $raw_lines ) ) {
			return $this->quantity_error();
		}
		$items = array();
		foreach ( $this->orders->items( $order_id ) as $item ) {
			$items[ $item['id'] ] = $item;
		}
		$validated = array();
		$seen      = array();
		foreach ( $raw_lines as $raw ) {
			if ( ! is_array( $raw ) ) {
				return $this->quantity_error();
			}
			$item_id = absint( $raw['item_id'] ?? 0 );
			$value   = $raw['quantity'] ?? '';
			if ( '' === (string) $value || '0' === (string) $value || '0.000000' === (string) $value ) {
				continue;
			}
			$quantity = PurchasingQuantity::normalize( $value );
			if ( $item_id <= 0 || null === $quantity ) {
				return $this->quantity_error();
			}
			if ( isset( $seen[ $item_id ] ) || ! isset( $items[ $item_id ] ) ) {
				return $this->error( 'stockino_invalid_receipt_item', 'A receipt line does not belong to this purchase order or was repeated.', 400 );
			}
			$seen[ $item_id ] = true;
			$item             = $items[ $item_id ];
			$accounted        = $this->receipts->accounted_quantity( $item_id );
			$current_received = PurchasingQuantity::compare( $accounted, $item['received_quantity'] ) > 0 ? $accounted : $item['received_quantity'];
			$remaining        = PurchasingQuantity::subtract( $item['ordered_quantity'], $current_received );
			if ( PurchasingQuantity::compare( $quantity, $remaining ) > 0 ) {
				return $this->error( 'stockino_purchase_over_receive', 'The requested receipt quantity exceeds the remaining ordered quantity.', 409 );
			}
			$product = wc_get_product( $item['product_id'] );
			if ( ! $product instanceof WC_Product ) {
				return $this->error( 'stockino_receipt_product_missing', 'A purchase-order product no longer exists. No inventory was changed.', 409 );
			}
			$owner_id = $product->get_stock_managed_by_id();
			$owner    = wc_get_product( $owner_id );
			if ( $owner_id <= 0 || ! $owner instanceof WC_Product || ! $owner->managing_stock() || null === $owner->get_stock_quantity() ) {
				return $this->error( 'stockino_receipt_stock_not_managed', 'Stock management is disabled or has no valid stock owner for a receipt line.', 409 );
			}
			$effective = PurchasingQuantity::normalize( wc_stock_amount( (float) $quantity ) );
			if ( $effective !== $quantity ) {
				return $this->error( 'stockino_receipt_stock_precision', 'WooCommerce cannot apply the requested receipt quantity without changing its precision.', 409 );
			}
			$validated[] = array(
				'item'     => $item,
				'product'  => $product,
				'owner'    => $owner,
				'quantity' => $quantity,
			);
		}
		return array() === $validated ? $this->quantity_error() : $validated;
	}

	/** @param array<int,array<string,mixed>> $lines @return array<string,mixed>|WP_Error */
	private function process( int $order_id, int $receipt_id, array $lines, string $note ) {
		$pending = array();
		try {
			foreach ( $lines as $line ) {
				$pending[] = array(
					'id'   => $this->receipts->create_item(
						array(
							'receipt_id'             => $receipt_id,
							'purchase_order_item_id' => $line['item']['id'],
							'product_id'             => $line['item']['product_id'],
							'stock_owner_id'         => $line['owner']->get_id(),
							'quantity_received'      => $line['quantity'],
						)
					),
					'line' => $line,
				);
			}
		} catch ( \RuntimeException $exception ) {
			$this->mark_attention( $receipt_id );
			return $this->attention_error( $receipt_id, false );
		}

		$any_stock_changed = false;
		try {
			foreach ( $pending as $index => $entry ) {
				$line       = $entry['line'];
				$product    = $line['product'];
				$owner      = $line['owner'];
				$is_variant = $product->is_type( 'variation' );
				$result     = $this->mutations->mutate(
					$owner,
					'delta',
					(float) $line['quantity'],
					array(
						'product_id'     => $is_variant ? $product->get_parent_id() : $owner->get_id(),
						'variation_id'   => $is_variant ? $product->get_id() : null,
						'movement_type'  => 'purchase_receipt',
						'reason'         => 'purchase_receipt',
						'reference_type' => 'purchase_receipt',
						'reference_id'   => (string) $entry['id'],
						'note'           => '' === $note ? null : $note,
						'metadata'       => array(
							'purchase_order_id'      => $order_id,
							'purchase_order_item_id' => $line['item']['id'],
							'receipt_id'             => $receipt_id,
							'source_product_id'      => $product->get_id(),
							'stock_owner_id'         => $owner->get_id(),
						),
					)
				);
				if ( is_wp_error( $result ) ) {
					$data              = $result->get_error_data();
					$stock_changed     = is_array( $data ) && ! empty( $data['stock_changed'] );
					$any_stock_changed = $any_stock_changed || $stock_changed;
					if ( $stock_changed ) {
						$this->orders->increment_received( $line['item']['id'], $line['quantity'] );
					}
					$this->receipts->update_item(
						$entry['id'],
						array(
							'status'          => $stock_changed ? 'requires_attention' : 'failed',
							'quantity_before' => is_array( $data ) ? ( $data['quantity_before'] ?? null ) : null,
							'quantity_after'  => is_array( $data ) ? ( $data['quantity_after'] ?? null ) : null,
							'error_code'      => $result->get_error_code(),
							'error_message'   => $result->get_error_message(),
						)
					);
					$this->fail_remaining( $pending, $index + 1 );
					$this->derive_order_status( $order_id );
					$this->mark_attention( $receipt_id );
					return $this->attention_error( $receipt_id, $stock_changed );
				}
				$any_stock_changed = true;
				if ( ! $this->orders->increment_received( $line['item']['id'], $line['quantity'] ) ) {
					$this->receipts->update_item(
						$entry['id'],
						array(
							'status'          => 'requires_attention',
							'quantity_before' => $result['quantity_before'],
							'quantity_after'  => $result['quantity_after'],
							'movement_id'     => $result['movement_id'],
							'error_code'      => 'stockino_received_quantity_failed',
							'error_message'   => 'Stock changed but the purchase-order received quantity could not be updated.',
						)
					);
					$this->fail_remaining( $pending, $index + 1 );
					$this->mark_attention( $receipt_id );
					return $this->attention_error( $receipt_id, true );
				}
				$this->receipts->update_item(
					$entry['id'],
					array(
						'status'          => 'completed',
						'quantity_before' => $result['quantity_before'],
						'quantity_after'  => $result['quantity_after'],
						'movement_id'     => $result['movement_id'],
					)
				);
			}

			$this->derive_order_status( $order_id );
			$this->receipts->update(
				$receipt_id,
				array(
					'status'       => 'completed',
					'completed_at' => current_time( 'mysql', true ),
				)
			);
			return $this->receipts->find( $receipt_id ) ?? $this->storage_error();
		} catch ( \Throwable $exception ) {
			do_action( 'stockino_purchase_receipt_persistence_failed', $receipt_id, $exception );
			try {
				$this->mark_attention( $receipt_id );
			} catch ( \Throwable $ignored ) {
				// The action above is the final reporting path when receipt persistence is unavailable.
			}
			return $this->attention_error( $receipt_id, $any_stock_changed );
		}
	}

	/** @param array<int,array<string,mixed>> $pending */
	private function fail_remaining( array $pending, int $start ): void {
		for ( $index = $start; $index < count( $pending ); ++$index ) {
			$this->receipts->update_item(
				$pending[ $index ]['id'],
				array(
					'status'        => 'failed',
					'error_code'    => 'stockino_receipt_halted',
					'error_message' => 'This line was not attempted because an earlier line requires attention.',
				)
			);
		}
	}

	private function derive_order_status( int $order_id ): void {
		$items         = $this->orders->items( $order_id );
		$has_received  = false;
		$has_remaining = false;
		foreach ( $items as $item ) {
			$has_received  = $has_received || ! PurchasingQuantity::is_zero( $item['received_quantity'] );
			$has_remaining = $has_remaining || ! PurchasingQuantity::is_zero( $item['remaining_quantity'] );
		}
		if ( $has_received ) {
			$this->orders->update(
				$order_id,
				$has_remaining
					? array(
						'status'       => 'partially_received',
						'completed_at' => null,
					)
					: array(
						'status'       => 'received',
						'completed_at' => current_time( 'mysql', true ),
					)
			);
		}
	}

	/** @param array<string,mixed> $receipt @return array<string,mixed>|WP_Error */
	private function existing_receipt( array $receipt, int $order_id ) {
		if ( $receipt['purchase_order_id'] !== $order_id ) {
			return $this->error( 'stockino_idempotency_key_conflict', 'This idempotency key belongs to another purchase order.', 409 );
		}
		if ( 'completed' === $receipt['status'] ) {
			$receipt['idempotent_replay'] = true;
			return $receipt;
		}
		$code = 'processing' === $receipt['status'] ? 'stockino_receipt_processing' : 'stockino_receipt_requires_attention';
		return new WP_Error(
			$code,
			'This idempotency key already belongs to a receipt that cannot be replayed.',
			array(
				'status'  => 409,
				'receipt' => $receipt,
			)
		);
	}

	private function mark_attention( int $receipt_id ): void {
		$this->receipts->update( $receipt_id, array( 'status' => 'requires_attention' ) );
	}

	private function attention_error( int $receipt_id, bool $stock_changed ): WP_Error {
		return new WP_Error(
			'stockino_receipt_requires_attention',
			'Receiving did not complete normally. Review the recorded receipt before taking further action.',
			array(
				'status'        => 500,
				'stock_changed' => $stock_changed,
				'receipt'       => $this->receipts->find( $receipt_id ),
			)
		);
	}

	private function quantity_error(): WP_Error {
		return $this->error( 'stockino_invalid_receipt_quantity', 'Select at least one positive receipt quantity within DECIMAL(20,6) precision.', 400 );
	}

	private function storage_error(): WP_Error {
		return $this->error( 'stockino_receipt_storage_failed', 'The purchase receipt could not be saved.', 500 );
	}

	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
