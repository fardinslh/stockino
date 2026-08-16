<?php

declare(strict_types=1);

namespace Stockino\Import;

final class ProductValidator {
	/**
	 * @param array<string, mixed> $row
	 * @return array{is_valid: bool, errors: array<int, string>}
	 */
	public function validate_raw_row( array $row ): array {
		$errors = array();

		$title = trim( (string) ( $row['title'] ?? $row['name'] ?? $row['product_name'] ?? '' ) );
		if ( '' === $title ) {
			$errors[] = 'عنوان محصول الزامی است.';
		} elseif ( mb_strlen( $title ) > 255 ) {
			$errors[] = 'طول عنوان محصول نباید بیشتر از ۲۵۵ کاراکتر باشد.';
		}

		$price_raw = $row['price'] ?? $row['regular_price'] ?? '';
		if ( '' === (string) $price_raw || ! is_numeric( $price_raw ) || (float) $price_raw < 0 ) {
			$errors[] = 'قیمت محصول باید یک عدد نامنفی معتبر باشد.';
		}

		$sale_price_raw = $row['sale_price'] ?? '';
		if ( '' !== (string) $sale_price_raw ) {
			if ( ! is_numeric( $sale_price_raw ) || (float) $sale_price_raw < 0 ) {
				$errors[] = 'قیمت حراج باید یک عدد نامنفی معتبر باشد.';
			} elseif ( is_numeric( $price_raw ) && (float) $sale_price_raw >= (float) $price_raw ) {
				$errors[] = 'قیمت حراج باید کمتر از قیمت اصلی باشد.';
			}
		}

		$stock_raw = $row['stock'] ?? $row['quantity'] ?? $row['stock_quantity'] ?? '0';
		if ( '' !== (string) $stock_raw && ( ! is_numeric( $stock_raw ) || (float) $stock_raw < 0 ) ) {
			$errors[] = 'موجودی انبار باید یک عدد نامنفی معتبر باشد.';
		}

		$weight_raw = $row['weight'] ?? $row['weight_grams'] ?? '';
		if ( '' !== (string) $weight_raw && ( ! is_numeric( $weight_raw ) || (float) $weight_raw < 0 ) ) {
			$errors[] = 'وزن محصول باید یک عدد نامنفی معتبر باشد.';
		}

		return array(
			'is_valid' => empty( $errors ),
			'errors'   => $errors,
		);
	}
}
