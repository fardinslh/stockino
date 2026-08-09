<?php

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stockino\Inventory\AdjustmentReason;

final class AdjustmentReasonTest extends TestCase {
	public function test_accepts_documented_reason_identifiers(): void {
		self::assertTrue( AdjustmentReason::is_valid( 'manual_adjustment' ) );
		self::assertTrue( AdjustmentReason::is_valid( 'damaged' ) );
		self::assertTrue( AdjustmentReason::is_valid( 'correction' ) );
		self::assertTrue( AdjustmentReason::is_valid( 'found_stock' ) );
		self::assertTrue( AdjustmentReason::is_valid( 'internal_use' ) );
		self::assertTrue( AdjustmentReason::is_valid( 'other' ) );
	}

	public function test_rejects_labels_and_unknown_identifiers(): void {
		self::assertFalse( AdjustmentReason::is_valid( 'اصلاح دستی' ) );
		self::assertFalse( AdjustmentReason::is_valid( 'inventory_count' ) );
	}
}
