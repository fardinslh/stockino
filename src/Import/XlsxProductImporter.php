<?php

declare(strict_types=1);

namespace Stockino\Import;

use ZipArchive;

final class XlsxProductImporter implements ProductImportInterface {
	public const MAX_ROWS = 5000;

	public function parse_file( string $file_path ): array {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new \InvalidArgumentException( sprintf( 'فایل اکسل یافت نشد: %s', $file_path ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \RuntimeException( 'اکستنشن ZipArchive در PHP فعال نیست.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			throw new \RuntimeException( 'امکان باز کردن فایل فشرده XLSX وجود ندارد.' );
		}

		// 1. Read shared strings
		$shared_strings = array();
		$strings_xml    = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $strings_xml ) {
			$xml = simplexml_load_string( $strings_xml );
			if ( $xml && isset( $xml->si ) ) {
				foreach ( $xml->si as $si ) {
					if ( isset( $si->t ) ) {
						$shared_strings[] = (string) $si->t;
					} elseif ( isset( $si->r ) ) {
						$txt = '';
						foreach ( $si->r as $r ) {
							$txt .= (string) $r->t;
						}
						$shared_strings[] = $txt;
					} else {
						$shared_strings[] = '';
					}
				}
			}
		}

		// 2. Read sheet1.xml
		$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();

		if ( false === $sheet_xml ) {
			throw new \RuntimeException( 'شیت اطلاعاتی در فایل XLSX یافت نشد.' );
		}

		$xml = simplexml_load_string( $sheet_xml );
		if ( ! $xml || ! isset( $xml->sheetData->row ) ) {
			return array();
		}

		$rows_data = array();
		$row_count = 0;

		foreach ( $xml->sheetData->row as $row ) {
			$row_count++;
			if ( $row_count > self::MAX_ROWS + 1 ) {
				break;
			}

			$row_cells = array();
			foreach ( $row->c as $cell ) {
				$val  = (string) $cell->v;
				$type = (string) $cell['t'];
				if ( 's' === $type && isset( $shared_strings[ (int) $val ] ) ) {
					$val = $shared_strings[ (int) $val ];
				}
				$row_cells[] = trim( $val );
			}
			if ( ! empty( array_filter( $row_cells ) ) ) {
				$rows_data[] = $row_cells;
			}
		}

		if ( empty( $rows_data ) ) {
			return array();
		}

		$headers = array_shift( $rows_data );
		$headers = array_map( static fn( string $h ) => strtolower( trim( $h ) ), $headers );

		$results = array();
		foreach ( $rows_data as $data ) {
			$data = array_pad( $data, count( $headers ), '' );
			$row  = array();
			foreach ( $headers as $idx => $h ) {
				$val = $data[ $idx ] ?? '';
				// Formula injection protection
				if ( in_array( substr( (string) $val, 0, 1 ), array( '=', '+', '-', '@' ), true ) && ! is_numeric( $val ) ) {
					$val = "'" . $val;
				}
				$row[ $h ] = $val;
			}
			$results[] = $row;
		}

		return $results;
	}

	public function parse_content( string $content ): array {
		$tmp = tempnam( sys_get_temp_dir(), 'stk_xlsx_' );
		if ( false === $tmp ) {
			throw new \RuntimeException( 'امکان ایجاد فایل موقت وجود ندارد.' );
		}
		file_put_contents( $tmp, $content );
		try {
			$res = $this->parse_file( $tmp );
			@unlink( $tmp );
			return $res;
		} catch ( \Throwable $e ) {
			@unlink( $tmp );
			throw $e;
		}
	}
}
