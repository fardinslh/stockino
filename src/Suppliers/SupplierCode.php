<?php

namespace Stockino\Suppliers;

final class SupplierCode {
	public static function normalize( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$normalized = strtoupper( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', $value ) );
		return trim( $normalized, '-' );
	}
}
