<?php

namespace Stockino\Suppliers;

final class PurchasingQuantity {
	private const SCALE          = 6;
	private const INTEGER_DIGITS = 14;

	public static function normalize( mixed $value ): ?string {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}
		$raw = trim( (string) $value );
		if ( preg_match( '/[eE]/', $raw ) ) {
			$raw = self::expand_exponent( $raw );
			if ( null === $raw ) {
				return null;
			}
		}
		if ( ! preg_match( '/^\+?(\d+)(?:\.(\d*))?$/', $raw, $matches ) ) {
			return null;
		}

		$integer  = ltrim( $matches[1], '0' );
		$integer  = '' === $integer ? '0' : $integer;
		$fraction = $matches[2] ?? '';
		$scaled   = str_pad( substr( $fraction, 0, self::SCALE ), self::SCALE, '0' );
		if ( isset( $fraction[ self::SCALE ] ) && (int) $fraction[ self::SCALE ] >= 5 ) {
			$combined = self::increment( $integer . $scaled );
			$integer  = substr( $combined, 0, -self::SCALE );
			$scaled   = substr( $combined, -self::SCALE );
		}

		$integer = ltrim( $integer, '0' );
		$integer = '' === $integer ? '0' : $integer;
		if ( strlen( $integer ) > self::INTEGER_DIGITS || ( '0' === $integer && '000000' === $scaled ) ) {
			return null;
		}
		return $integer . '.' . $scaled;
	}

	private static function expand_exponent( string $value ): ?string {
		if ( ! preg_match( '/^\+?(\d+)(?:\.(\d*))?[eE]([+-]?\d+)$/', $value, $matches ) ) {
			return null;
		}
		$exponent = (int) $matches[3];
		if ( abs( $exponent ) > 100 ) {
			return null;
		}
		$digits   = $matches[1] . ( $matches[2] ?? '' );
		$position = strlen( $matches[1] ) + $exponent;
		if ( $position <= 0 ) {
			return '0.' . str_repeat( '0', -$position ) . $digits;
		}
		if ( $position >= strlen( $digits ) ) {
			return $digits . str_repeat( '0', $position - strlen( $digits ) );
		}
		return substr( $digits, 0, $position ) . '.' . substr( $digits, $position );
	}

	private static function increment( string $digits ): string {
		$carry = 1;
		for ( $index = strlen( $digits ) - 1; $index >= 0 && 1 === $carry; --$index ) {
			$next             = (int) $digits[ $index ] + $carry;
			$digits[ $index ] = (string) ( $next % 10 );
			$carry            = $next >= 10 ? 1 : 0;
		}
		return 1 === $carry ? '1' . $digits : $digits;
	}
}
