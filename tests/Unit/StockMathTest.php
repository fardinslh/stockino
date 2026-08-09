<?php

namespace Stockino\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stockino\Inventory\StockMath;

final class StockMathTest extends TestCase {
	/** @return array<string,array{float,string,float,array<string,float>}> */
	public static function calculations(): array {
		return array(
			'delta increase' => array( 12.0, 'delta', 8.0, array( 'before' => 12.0, 'delta' => 8.0, 'after' => 20.0 ) ),
			'delta decrease' => array( 12.0, 'delta', -3.0, array( 'before' => 12.0, 'delta' => -3.0, 'after' => 9.0 ) ),
			'set quantity'   => array( 12.0, 'set', 25.0, array( 'before' => 12.0, 'delta' => 13.0, 'after' => 25.0 ) ),
			'negative delta' => array( 2.0, 'delta', -5.0, array( 'before' => 2.0, 'delta' => -5.0, 'after' => -3.0 ) ),
		);
	}

	#[DataProvider( 'calculations' )]
	public function test_calculates_adjustments( float $before, string $mode, float $quantity, array $expected ): void {
		self::assertSame( $expected, StockMath::calculate( $before, $mode, $quantity ) );
	}

	public function test_rejects_unknown_mode(): void {
		$this->expectException( InvalidArgumentException::class );
		StockMath::calculate( 10.0, 'replace', 12.0 );
	}
}
