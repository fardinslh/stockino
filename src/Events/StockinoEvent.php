<?php

declare(strict_types=1);

namespace Stockino\Events;

final class StockinoEvent {
	public const PRODUCT_UPDATED     = 'stockino.product.updated';
	public const INVENTORY_CHANGED   = 'stockino.inventory.changed';
	public const ORDER_CREATED       = 'stockino.order.created';
	public const ORDER_UPDATED       = 'stockino.order.updated';
	public const PUBLICATION_CHANGED = 'stockino.publication.changed';
	public const SYNC_FAILED         = 'stockino.sync.failed';

	/**
	 * @param array<string, mixed> $payload
	 */
	public function __construct(
		public readonly string $event_name,
		public readonly array $payload = array(),
		public readonly \DateTimeImmutable $occurred_at = new \DateTimeImmutable()
	) {}
}
