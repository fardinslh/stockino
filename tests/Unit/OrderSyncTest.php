<?php

declare(strict_types=1);

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stockino\Domain\Order\CanonicalOrder;
use Stockino\Orders\OrderNormalizer;

final class OrderSyncTest extends TestCase {
	public function test_order_normalizer_translates_basalam_order_payload(): void {
		$normalizer = new OrderNormalizer();

		$raw = array(
			'id'              => 'basalam_ord_8841',
			'status'          => '200', // Processing/paid
			'total_amount'    => 320000,
			'shipping_amount' => 30000,
			'discount_amount' => 10000,
			'currency'        => 'IRT',
			'customer'        => array(
				'id'    => 'user_550',
				'name'  => 'علی احمدی',
				'phone' => '09351234567',
			),
			'shipping_address' => array(
				'first_name' => 'علی',
				'last_name'  => 'احمدی',
				'address_1'  => 'بلوار کشاورز، پلاک ۸',
				'city'       => 'شیراز',
				'state'      => 'فارس',
				'postcode'   => '7134567890',
			),
			'items' => array(
				array(
					'id'          => 'it_1',
					'title'       => 'تیشرت نخی مردانه',
					'sku'         => 'TSHIRT-BLK-L',
					'quantity'    => 2,
					'unit_price'  => 145000,
					'total_price' => 290000,
				),
			),
		);

		$canonical = $normalizer->normalize( $raw, 'basalam' );

		self::assertSame( 'basalam_ord_8841', $canonical->external_order_id );
		self::assertSame( 'basalam', $canonical->marketplace );
		self::assertSame( CanonicalOrder::STATUS_PROCESSING, $canonical->status );
		self::assertSame( 320000.0, $canonical->total_amount );
		self::assertSame( 'علی احمدی', $canonical->customer->name );
		self::assertSame( '09351234567', $canonical->customer->phone );
		self::assertSame( 'شیراز', $canonical->shipping_address->city );
		self::assertCount( 1, $canonical->items );
		self::assertSame( 'TSHIRT-BLK-L', $canonical->items[0]->sku );
		self::assertSame( 2.0, (float) $canonical->items[0]->quantity );
	}

	public function test_order_normalizer_status_mapping(): void {
		$normalizer = new OrderNormalizer();

		self::assertSame( CanonicalOrder::STATUS_PENDING, $normalizer->normalize_status( '100', 'basalam' ) );
		self::assertSame( CanonicalOrder::STATUS_PROCESSING, $normalizer->normalize_status( '200', 'basalam' ) );
		self::assertSame( CanonicalOrder::STATUS_SHIPPED, $normalizer->normalize_status( '300', 'basalam' ) );
		self::assertSame( CanonicalOrder::STATUS_DELIVERED, $normalizer->normalize_status( '400', 'basalam' ) );
		self::assertSame( CanonicalOrder::STATUS_CANCELLED, $normalizer->normalize_status( '500', 'basalam' ) );
		self::assertSame( CanonicalOrder::STATUS_RETURNED, $normalizer->normalize_status( '600', 'basalam' ) );
	}
}
