<?php

declare(strict_types=1);

namespace Stockino\Import;

final class CsvProductImporter implements ProductImportInterface {
	public function parse_file( string $file_path ): array {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new \InvalidArgumentException( sprintf( 'فایل CSV یافت نشد یا غیرقابل خواندن است: %s', $file_path ) );
		}

		$content = (string) file_get_contents( $file_path );
		return $this->parse_content( $content );
	}

	public function parse_content( string $content ): array {
		// Strip UTF-8 BOM if present
		if ( str_starts_with( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		}

		$lines = preg_split( '/\r\n|\r|\n/', trim( $content ) );
		if ( empty( $lines ) ) {
			return array();
		}

		$header_line = array_shift( $lines );
		if ( null === $header_line || '' === trim( $header_line ) ) {
			return array();
		}

		// Detect delimiter (, or ;)
		$delimiter = str_contains( $header_line, ';' ) && ! str_contains( $header_line, ',' ) ? ';' : ',';
		$headers   = str_getcsv( $header_line, $delimiter );
		$headers   = array_map( static fn( string $h ) => strtolower( trim( $h ) ), $headers );

		$rows = array();
		foreach ( $lines as $line_num => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			$data = str_getcsv( $line, $delimiter );
			if ( count( $data ) !== count( $headers ) ) {
				// Pad or slice to headers count
				$data = array_pad( $data, count( $headers ), '' );
			}

			$row = array();
			foreach ( $headers as $index => $header ) {
				$row[ $header ] = trim( (string) ( $data[ $index ] ?? '' ) );
			}
			$rows[] = $row;
		}

		return $rows;
	}
}
