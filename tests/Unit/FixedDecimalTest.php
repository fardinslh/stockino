<?php

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stockino\Costing\FixedDecimal;

final class FixedDecimalTest extends TestCase {
	public function test_first_receipt_establishes_cost(): void {
		self::assertSame( '12.345678', FixedDecimal::weighted_average( '10.000000', '2.000000', null, '12.345678' ) );
	}

	public function test_second_receipt_uses_moving_weighted_average(): void {
		self::assertSame( '13.333333', FixedDecimal::weighted_average( '10.000000', '5.000000', '10.000000', '20.000000' ) );
	}

	#[DataProvider( 'reset_cases' )]
	public function test_nonpositive_or_uncosted_stock_resets_to_receipt_cost( string $quantity, ?string $average ): void {
		self::assertSame( '7.125000', FixedDecimal::weighted_average( $quantity, '2.000000', $average, '7.125000' ) );
	}

	/** @return array<string,array{string,string|null}> */
	public static function reset_cases(): array {
		return array(
			'zero stock'     => array( '0.000000', '99.000000' ),
			'negative stock' => array( '-4.000000', '99.000000' ),
			'no cost'        => array( '8.000000', null ),
		);
	}

	public function test_inventory_value_is_zero_for_nonpositive_stock(): void {
		self::assertSame( '0.000000', FixedDecimal::inventory_value( '0.000000', '9.000000' ) );
		self::assertSame( '0.000000', FixedDecimal::inventory_value( '-1.000000', '9.000000' ) );
	}

	public function test_inventory_value_uses_fixed_decimal_half_up_rounding(): void {
		self::assertSame( '10.821513', FixedDecimal::inventory_value( '1.234567', '8.765432' ) );
	}

	public function test_strict_cost_validation_distinguishes_zero_from_unknown(): void {
		self::assertSame( '0.000000', FixedDecimal::normalize_cost( '0' ) );
		self::assertNull( FixedDecimal::normalize_cost( '' ) );
		self::assertNull( FixedDecimal::normalize_cost( '1.0000001' ) );
		self::assertNull( FixedDecimal::normalize_cost( -1 ) );
		self::assertNull( FixedDecimal::normalize_cost( 0.1 ) );
	}
}
