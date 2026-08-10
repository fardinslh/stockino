<?php

namespace Stockino\Purchasing;

interface ReceiveLock {
	public function acquire( int $purchase_order_id ): bool;

	public function release( int $purchase_order_id ): void;
}
