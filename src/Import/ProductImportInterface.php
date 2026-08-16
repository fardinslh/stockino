<?php

declare(strict_types=1);

namespace Stockino\Import;

interface ProductImportInterface {
	/**
	 * @param string $file_path
	 * @return array<int, array<string, mixed>>
	 * @throws \Exception
	 */
	public function parse_file( string $file_path ): array;

	/**
	 * @param string $content
	 * @return array<int, array<string, mixed>>
	 * @throws \Exception
	 */
	public function parse_content( string $content ): array;
}
