<?php

namespace Stockino\Costing;

interface CostLock {
	/** @param array<int,int> $stock_owner_ids */
	public function acquire_many( array $stock_owner_ids ): bool;

	/** @param array<int,int> $stock_owner_ids */
	public function release_many( array $stock_owner_ids ): void;
}
