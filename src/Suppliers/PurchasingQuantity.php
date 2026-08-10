<?php

namespace Stockino\Suppliers;

final class PurchasingQuantity {
	private const SCALE          = 6;
	private const INTEGER_DIGITS = 14;

	public static function normalize( mixed $value ): ?string {
		$normalized = self::normalize_nonnegative( $value );
		return null === $normalized || self::is_zero( $normalized ) ? null : $normalized;
	}

	public static function normalize_nonnegative( mixed $value ): ?string {
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
		if ( strlen( $integer ) > self::INTEGER_DIGITS ) {
			return null;
		}
		return $integer . '.' . $scaled;
	}

	public static function compare( string $left, string $right ): int {
		return strcmp( self::digits( $left ), self::digits( $right ) );
	}

	public static function add( string $left, string $right ): string {
		$left_digits  = self::digits( $left );
		$right_digits = self::digits( $right );
		$result       = '';
		$carry        = 0;
		for ( $index = strlen( $left_digits ) - 1; $index >= 0; --$index ) {
			$sum    = (int) $left_digits[ $index ] + (int) $right_digits[ $index ] + $carry;
			$result = (string) ( $sum % 10 ) . $result;
			$carry  = intdiv( $sum, 10 );
		}
		if ( $carry > 0 ) {
			throw new \OverflowException( 'Quantity exceeds DECIMAL(20,6).' );
		}
		return self::from_digits( $result );
	}

	public static function subtract( string $left, string $right ): string {
		if ( self::compare( $left, $right ) < 0 ) {
			throw new \UnderflowException( 'Quantity cannot be negative.' );
		}
		$left_digits  = self::digits( $left );
		$right_digits = self::digits( $right );
		$result       = '';
		$borrow       = 0;
		for ( $index = strlen( $left_digits ) - 1; $index >= 0; --$index ) {
			$digit = (int) $left_digits[ $index ] - $borrow - (int) $right_digits[ $index ];
			if ( $digit < 0 ) {
				$digit += 10;
				$borrow = 1;
			} else {
				$borrow = 0;
			}
			$result = (string) $digit . $result;
		}
		return self::from_digits( $result );
	}

	public static function is_zero( string $value ): bool {
		return '00000000000000000000' === self::digits( $value );
	}

	private static function digits( string $value ): string {
		$normalized = self::normalize_nonnegative( $value );
		if ( null === $normalized ) {
			throw new \InvalidArgumentException( 'Invalid quantity.' );
		}
		list( $integer, $fraction ) = explode( '.', $normalized );
		return str_pad( $integer, self::INTEGER_DIGITS, '0', STR_PAD_LEFT ) . $fraction;
	}

	private static function from_digits( string $digits ): string {
		$integer = ltrim( substr( $digits, 0, self::INTEGER_DIGITS ), '0' );
		return ( '' === $integer ? '0' : $integer ) . '.' . substr( $digits, self::INTEGER_DIGITS );
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
