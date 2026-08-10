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
}
