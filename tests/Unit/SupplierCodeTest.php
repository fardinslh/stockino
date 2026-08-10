<?php

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stockino\Suppliers\SupplierCode;

final class SupplierCodeTest extends TestCase {
	public function test_normalizes_codes_to_stable_uppercase(): void {
		self::assertSame( 'TEHRAN-PARTS', SupplierCode::normalize( 'tehran parts' ) );
		self::assertSame( 'SUP-001', SupplierCode::normalize( 'sup-001' ) );
	}

	public function test_empty_code_stays_null(): void {
		self::assertNull( SupplierCode::normalize( '  ' ) );
	}

	public function test_invalid_characters_cannot_create_a_code(): void {
		self::assertSame( '', SupplierCode::normalize( '***' ) );
	}
}
