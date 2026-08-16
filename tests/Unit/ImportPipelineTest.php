<?php

declare(strict_types=1);

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stockino\Import\CsvProductImporter;
use Stockino\Import\ImportPipelineService;
use Stockino\Import\ProductNormalizer;
use Stockino\Import\ProductValidator;

final class ImportPipelineTest extends TestCase {
	public function test_csv_importer_parses_comma_and_semicolon_delimited_content(): void {
		$importer = new CsvProductImporter();
		$csv      = "title,sku,price,stock\nمحصول الف,SKU-A,150000,10\nمحصول ب,SKU-B,250000,5";

		$rows = $importer->parse_content( $csv );
		self::assertCount( 2, $rows );
		self::assertSame( 'محصول الف', $rows[0]['title'] );
		self::assertSame( 'SKU-A', $rows[0]['sku'] );
		self::assertSame( '150000', $rows[0]['price'] );
		self::assertSame( '10', $rows[0]['stock'] );
	}

	public function test_product_validator_rejects_missing_title_or_negative_price(): void {
		$validator = new ProductValidator();

		$invalid_row = array( 'title' => '', 'price' => '-1000' );
		$res = $validator->validate_raw_row( $invalid_row );

		self::assertFalse( $res['is_valid'] );
		self::assertNotEmpty( $res['errors'] );

		$valid_row = array( 'title' => 'محصول تستی', 'price' => '50000', 'stock' => '12' );
		$valid_res = $validator->validate_raw_row( $valid_row );
		self::assertTrue( $valid_res['is_valid'] );
		self::assertEmpty( $valid_res['errors'] );
	}

	public function test_product_normalizer_creates_canonical_product(): void {
		$normalizer = new ProductNormalizer();
		$row        = array(
			'title'       => 'کیف چرمی مردانه',
			'sku'         => 'BAG-001',
			'price'       => '120000',
			'sale_price'  => '99000',
			'stock'       => '15',
			'weight'      => '450',
			'images'      => 'https://example.com/img1.jpg, https://example.com/img2.jpg',
			'attr_color'  => 'قهوه‌ای',
		);

		$canonical = $normalizer->normalize_row( $row );

		self::assertSame( 'کیف چرمی مردانه', $canonical->title );
		self::assertSame( 'BAG-001', $canonical->sku );
		self::assertSame( 120000.0, $canonical->price->regular_price );
		self::assertSame( 99000.0, $canonical->price->sale_price );
		self::assertSame( 99000.0, $canonical->price->get_effective_price() );
		self::assertSame( 15.0, $canonical->inventory->quantity );
		self::assertSame( 450, $canonical->weight_grams );
		self::assertCount( 2, $canonical->image_urls );
		self::assertSame( 'قهوه‌ای', $canonical->attributes['color'] );
	}

	public function test_import_pipeline_service_processes_raw_csv(): void {
		$pipeline = new ImportPipelineService();
		$csv      = "title,sku,price,stock\nمحصول یک,SKU-01,10000,5\nمحصول دو,SKU-02,20000,8";

		$res = $pipeline->process_csv( $csv, false );

		self::assertSame( 2, $res['total_rows'] );
		self::assertSame( 2, $res['valid_count'] );
		self::assertSame( 0, $res['failed_count'] );
		self::assertCount( 2, $res['products'] );
	}
}
