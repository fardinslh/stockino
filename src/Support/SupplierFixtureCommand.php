<?php

namespace Stockino\Support;

use Stockino\Database\SupplierProductRepository;
use Stockino\Database\SupplierRepository;
use Stockino\Suppliers\SupplierProductService;
use Stockino\Suppliers\SupplierService;
use Stockino\Suppliers\SupplierValidator;

final class SupplierFixtureCommand {
	public function register(): void {
		\WP_CLI::add_command( 'stockino supplier-fixtures', array( $this, 'generate' ) );
	}

	/** Generate deterministic supplier and relationship fixtures. */
	public function generate(): void {
		$environment = wp_get_environment_type();
		if ( ! FixtureEnvironment::is_allowed( $environment ) ) {
			\WP_CLI::error( sprintf( 'Fixtures are disabled in the %s environment. Use local or development only.', $environment ) );
		}

		$validator  = new SupplierValidator();
		$repository = new SupplierRepository();
		$suppliers  = new SupplierService( $repository, $validator );
		$relations  = new SupplierProductService( new SupplierProductRepository(), $repository, $validator );
		$names      = array(
			'تأمین کالای سپاهان',
			'بازرگانی پارس آریا',
			'صنایع تهران قطعه',
			'تجارت گستر خاور',
			'پخش البرز نوین',
			'تأمین شرق ایرانیان',
			'بازرگانی آفتاب',
			'پارس تجهیز ماندگار',
			'کالای جنوب',
			'همکاران صنعت البرز',
			'توسعه بازار زاگرس',
			'پخش نگین فارس',
			'سامان تجارت پویا',
			'پیشگامان تأمین',
			'کالای برتر خزر',
			'تدارکات مرکزی',
			'بازرگانی مهرگان',
			'راهکار صنعت نو',
			'آریا قطعه ایرانیان',
			'گروه تأمین پایدار',
		);

		$created = 0;
		$linked  = 0;
		foreach ( $names as $offset => $name ) {
			$code     = sprintf( 'SUP-%03d', $offset + 1 );
			$supplier = $repository->find_by_code( $code );
			if ( ! $supplier ) {
				$supplier = $suppliers->create(
					array(
						'name'           => $name,
						'code'           => $code,
						'status'         => $offset >= 17 ? 'inactive' : 'active',
						'contact_name'   => sprintf( 'کارشناس فروش %d', $offset + 1 ),
						'phone'          => sprintf( '0218800%04d', $offset + 1 ),
						'email'          => sprintf( 'supplier%02d@example.test', $offset + 1 ),
						'website'        => sprintf( 'https://supplier%02d.example.test', $offset + 1 ),
						'address'        => 'ایران، دفتر فروش و پشتیبانی',
						'lead_time_days' => 2 + ( $offset % 12 ),
						'notes'          => 'داده نمایشی محیط توسعه Stockino',
					)
				);
				if ( ! is_wp_error( $supplier ) ) {
					++$created;
				}
			}
			if ( is_wp_error( $supplier ) ) {
				\WP_CLI::warning( $supplier->get_error_message() );
				continue;
			}
			if ( 'active' !== $supplier['status'] ) {
				continue;
			}

			$skus = array( sprintf( 'STK-%03d', 101 + $offset ), sprintf( 'STK-%03d', 121 + $offset ) );
			if ( 0 === $offset ) {
				$skus = array_merge( $skus, array_map( static fn( int $index ): string => sprintf( 'STK-%03d', $index ), range( 101, 135 ) ) );
			}
			if ( 0 === $offset % 4 ) {
				$skus[] = 'STK-110-S';
			}
			$skus = array_values( array_unique( $skus ) );
			foreach ( $skus as $index => $sku ) {
				$product_id = wc_get_product_id_by_sku( $sku );
				if ( ! $product_id ) {
					continue;
				}
				$result = $relations->create(
					(int) $supplier['id'],
					array(
						'product_id'             => $product_id,
						'supplier_sku'           => $code . '-' . $sku,
						'lead_time_days'         => 0 === $index ? null : 1 + ( $offset % 7 ),
						'minimum_order_quantity' => 0 === $offset % 3 ? '5.5' : '1',
						'order_multiple'         => 0 === $offset % 3 ? '2.5' : '1',
						'notes'                  => 'رابطه نمایشی خرید؛ موجودی ووکامرس را تغییر نمی‌دهد.',
					)
				);
				if ( ! is_wp_error( $result ) ) {
					++$linked;
				}
			}
		}

		\WP_CLI::success( sprintf( 'Created %d suppliers and %d supplier-product links.', $created, $linked ) );
	}
}
