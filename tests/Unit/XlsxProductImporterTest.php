<?php

declare(strict_types=1);

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stockino\Import\XlsxProductImporter;

final class XlsxProductImporterTest extends TestCase {
	public function test_xlsx_importer_throws_on_invalid_file(): void {
		$importer = new XlsxProductImporter();
		$this->expectException( \InvalidArgumentException::class );
		$importer->parse_file( '/path/to/non_existent_file.xlsx' );
	}
}
