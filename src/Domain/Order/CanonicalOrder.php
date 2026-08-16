<?php

declare(strict_types=1);

namespace Stockino\Domain\Order;

final class CanonicalOrder {
	public const STATUS_PENDING    = 'PENDING';
	public const STATUS_CONFIRMED  = 'CONFIRMED';
	public const STATUS_PROCESSING = 'PROCESSING';
	public const STATUS_SHIPPED    = 'SHIPPED';
	public const STATUS_DELIVERED  = 'DELIVERED';
	public const STATUS_CANCELLED  = 'CANCELLED';
	public const STATUS_RETURNED   = 'RETURNED';

	/**
	 * @param array<int, CanonicalOrderItem> $items
	 * @param array<string, mixed> $metadata
	 */
	public function __construct(
		public readonly string $external_order_id,
		public readonly string $marketplace,
		public readonly string $status,
		public readonly float $total_amount,
		public readonly float $shipping_amount,
		public readonly float $discount_amount,
		public readonly string $currency,
		public readonly CanonicalCustomer $customer,
		public readonly CanonicalAddress $shipping_address,
		public readonly ?CanonicalAddress $billing_address,
		public readonly array $items,
		public readonly \DateTimeImmutable $created_at,
		public readonly ?string $customer_note = null,
		public readonly ?string $shipping_tracking_number = null,
		public readonly ?string $shipping_method = null,
		public readonly ?string $payment_method = null,
		public readonly array $metadata = array(),
		public readonly ?int $wc_order_id = null
	) {}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'external_order_id'        => $this->external_order_id,
			'marketplace'              => $this->marketplace,
			'status'                   => $this->status,
			'total_amount'             => $this->total_amount,
			'shipping_amount'          => $this->shipping_amount,
			'discount_amount'          => $this->discount_amount,
			'currency'                 => $this->currency,
			'customer'                 => $this->customer->to_array(),
			'shipping_address'         => $this->shipping_address->to_array(),
			'billing_address'          => $this->billing_address ? $this->billing_address->to_array() : null,
			'items'                    => array_map( static fn( CanonicalOrderItem $item ) => $item->to_array(), $this->items ),
			'created_at'               => $this->created_at->format( \DateTimeInterface::ATOM ),
			'customer_note'            => $this->customer_note,
			'shipping_tracking_number' => $this->shipping_tracking_number,
			'shipping_method'          => $this->shipping_method,
			'payment_method'           => $this->payment_method,
			'metadata'                 => $this->metadata,
			'wc_order_id'              => $this->wc_order_id,
		);
	}
}
