<?php

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stockino\Suppliers\PurchasingQuantity;

final class PurchasingQuantityTest extends TestCase {
	/** @return array<string,array{mixed,?string}> */
	public static function quantities(): array {
		return array(
			'minimum unit'        => array( '0.000001', '0.000001' ),
			'integer'             => array( '1', '1.000000' ),
			'decimal'             => array( '5.5', '5.500000' ),
			'maximum'             => array( '99999999999999.999999', '99999999999999.999999' ),
			'zero'                => array( '0', null ),
			'negative'            => array( '-1', null ),
			'rounds to zero'      => array( '0.0000001', null ),
			'over maximum'        => array( '100000000000000', null ),
			'rounds over maximum' => array( '99999999999999.9999999', null ),
			'scientific notation' => array( '1e-6', '0.000001' ),
			'boolean'             => array( true, null ),
		);
	}

	#[DataProvider( 'quantities' )]
	public function test_normalizes_to_decimal_twenty_six( mixed $input, ?string $expected ): void {
		self::assertSame( $expected, PurchasingQuantity::normalize( $input ) );
	}

	public function test_fixed_scale_arithmetic_has_no_float_drift(): void {
		self::assertSame( '0.300000', PurchasingQuantity::add( '0.100000', '0.200000' ) );
		self::assertSame( '0.200000', PurchasingQuantity::subtract( '0.300000', '0.100000' ) );
		self::assertGreaterThan( 0, PurchasingQuantity::compare( '10.000000', '7.000000' ) );
		self::assertTrue( PurchasingQuantity::is_zero( '0.000000' ) );
	}

	public function test_fixed_scale_arithmetic_rejects_underflow_and_overflow(): void {
		$this->expectException( \UnderflowException::class );
		PurchasingQuantity::subtract( '1.000000', '2.000000' );
	}

	public function test_fixed_scale_addition_rejects_overflow(): void {
		$this->expectException( \OverflowException::class );
		PurchasingQuantity::add( '99999999999999.999999', '0.000001' );
	}
}
