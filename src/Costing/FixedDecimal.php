<?php

namespace Stockino\Costing;

final class FixedDecimal {
	public const SCALE = 6;

	private const MAX_INTEGER_DIGITS = 14;

	public static function normalize_cost( mixed $value ): ?string {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}
		$raw = trim( (string) $value );
		if ( ! preg_match( '/^\+?(\d+)(?:\.(\d{0,6}))?$/', $raw, $matches ) ) {
			return null;
		}
		$integer = ltrim( $matches[1], '0' );
		$integer = '' === $integer ? '0' : $integer;
		if ( strlen( $integer ) > self::MAX_INTEGER_DIGITS ) {
			return null;
		}
		return $integer . '.' . str_pad( $matches[2] ?? '', self::SCALE, '0' );
	}

	public static function normalize_quantity( mixed $value ): ?string {
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				return null;
			}
			$value = rtrim( rtrim( sprintf( '%.6F', $value ), '0' ), '.' );
		}
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}
		$raw = trim( (string) $value );
		if ( ! preg_match( '/^([+-]?)(\d+)(?:\.(\d{0,6}))?$/', $raw, $matches ) ) {
			return null;
		}
		$integer = ltrim( $matches[2], '0' );
		$integer = '' === $integer ? '0' : $integer;
		if ( strlen( $integer ) > self::MAX_INTEGER_DIGITS ) {
			return null;
		}
		$number = $integer . '.' . str_pad( $matches[3] ?? '', self::SCALE, '0' );
		return '-' === $matches[1] && '0.000000' !== $number ? '-' . $number : $number;
	}

	public static function compare( string $left, string $right ): int {
		list( $left_negative, $left_digits )   = self::signed_digits( $left );
		list( $right_negative, $right_digits ) = self::signed_digits( $right );
		if ( $left_negative !== $right_negative ) {
			return $left_negative ? -1 : 1;
		}
		$comparison = self::compare_integer( $left_digits, $right_digits );
		return $left_negative ? -$comparison : $comparison;
	}

	public static function add( string $left, string $right ): string {
		list( $left_negative, $left_digits )   = self::signed_digits( $left );
		list( $right_negative, $right_digits ) = self::signed_digits( $right );
		if ( $left_negative === $right_negative ) {
			$result = self::from_scaled_digits( self::add_integer( $left_digits, $right_digits ) );
			return $left_negative && '0.000000' !== $result ? '-' . $result : $result;
		}
		$comparison = self::compare_integer( $left_digits, $right_digits );
		if ( 0 === $comparison ) {
			return '0.000000';
		}
		$left_larger = $comparison > 0;
		$result      = self::from_scaled_digits(
			$left_larger ? self::subtract_integer( $left_digits, $right_digits ) : self::subtract_integer( $right_digits, $left_digits )
		);
		$negative    = $left_larger ? $left_negative : $right_negative;
		return $negative ? '-' . $result : $result;
	}

	public static function subtract( string $left, string $right ): string {
		$normalized = self::normalize_quantity( $right );
		if ( null === $normalized ) {
			throw new \InvalidArgumentException( 'Invalid fixed decimal.' );
		}
		$negative = str_starts_with( $normalized, '-' );
		$inverse  = $negative ? ltrim( $normalized, '-' ) : ( '0.000000' === $normalized ? $normalized : '-' . $normalized );
		return self::add( $left, $inverse );
	}

	public static function multiply_by_integer( string $value, int $factor ): string {
		$normalized = self::normalize_cost( $value );
		if ( null === $normalized || $factor < 0 ) {
			throw new \InvalidArgumentException( 'Invalid non-negative fixed-decimal multiplication.' );
		}
		return self::from_scaled_digits( self::multiply_integer( self::unsigned_scaled_digits( $normalized ), (string) $factor ) );
	}

	public static function ceil_to_multiple( string $value, string $multiple ): string {
			$normalized_value = self::normalize_cost( $value );
		$normalized_multiple  = self::normalize_cost( $multiple );
		if ( null === $normalized_value || null === $normalized_multiple || '0.000000' === $normalized_multiple ) {
			throw new \InvalidArgumentException( 'Invalid fixed-decimal multiple.' );
		}
		$value_digits    = self::unsigned_scaled_digits( $normalized_value );
		$multiple_digits = self::unsigned_scaled_digits( $normalized_multiple );
		$quotient        = self::divide_integer( $value_digits, $multiple_digits );
		if ( '0' !== $quotient['remainder'] ) {
			$quotient['quotient'] = self::add_integer( $quotient['quotient'], '1' );
		}
		return self::from_scaled_digits( self::multiply_integer( $quotient['quotient'], $multiple_digits ) );
	}

	public static function weighted_average( string $quantity_before, string $received_quantity, ?string $average_before, string $unit_cost ): string {
		$before   = self::normalize_quantity( $quantity_before );
		$received = self::normalize_cost( $received_quantity );
		$cost     = self::normalize_cost( $unit_cost );
		$average  = null === $average_before ? null : self::normalize_cost( $average_before );
		if ( null === $before || null === $received || null === $cost || ( null !== $average_before && null === $average ) ) {
			throw new \InvalidArgumentException( 'Invalid fixed-decimal weighted-average input.' );
		}
		if ( self::compare( $before, '0.000000' ) <= 0 || null === $average ) {
			return $cost;
		}
		$before_digits   = self::unsigned_scaled_digits( $before );
		$received_digits = self::unsigned_scaled_digits( $received );
		$post_digits     = self::add_integer( $before_digits, $received_digits );
		$basis           = self::add_integer(
			self::multiply_integer( $before_digits, self::unsigned_scaled_digits( $average ) ),
			self::multiply_integer( $received_digits, self::unsigned_scaled_digits( $cost ) )
		);
		return self::from_scaled_digits( self::divide_round_half_up( $basis, $post_digits ) );
	}

	public static function inventory_value( string $quantity, ?string $average_cost ): string {
		$normalized_quantity = self::normalize_quantity( $quantity );
		$normalized_cost     = null === $average_cost ? null : self::normalize_cost( $average_cost );
		if ( null === $normalized_quantity || ( null !== $average_cost && null === $normalized_cost ) ) {
			throw new \InvalidArgumentException( 'Invalid fixed-decimal inventory-value input.' );
		}
		if ( null === $normalized_cost || self::compare( $normalized_quantity, '0.000000' ) <= 0 ) {
			return '0.000000';
		}
		$product = self::multiply_integer(
			self::unsigned_scaled_digits( $normalized_quantity ),
			self::unsigned_scaled_digits( $normalized_cost )
		);
		return self::from_scaled_digits( self::divide_round_half_up( $product, '1000000' ), 24 );
	}

	/** @return array{bool,string} */
	private static function signed_digits( string $value ): array {
		$normalized = self::normalize_quantity( $value );
		if ( null === $normalized ) {
			throw new \InvalidArgumentException( 'Invalid fixed decimal.' );
		}
		$negative = str_starts_with( $normalized, '-' );
		return array( $negative, self::unsigned_scaled_digits( ltrim( $normalized, '-' ) ) );
	}

	private static function unsigned_scaled_digits( string $value ): string {
		list( $integer, $fraction ) = explode( '.', ltrim( $value, '+' ) );
		return self::trim_integer( $integer . $fraction );
	}

	private static function from_scaled_digits( string $digits, int $maximum_integer_digits = self::MAX_INTEGER_DIGITS ): string {
		$digits = self::trim_integer( $digits );
		if ( strlen( $digits ) <= self::SCALE ) {
			$digits = str_pad( $digits, self::SCALE + 1, '0', STR_PAD_LEFT );
		}
		$integer = substr( $digits, 0, -self::SCALE );
		if ( strlen( $integer ) > $maximum_integer_digits ) {
			throw new \OverflowException( 'Fixed-decimal result exceeds storage precision.' );
		}
		return $integer . '.' . substr( $digits, -self::SCALE );
	}

	private static function add_integer( string $left, string $right ): string {
		$length = max( strlen( $left ), strlen( $right ) );
		$left   = str_pad( $left, $length, '0', STR_PAD_LEFT );
		$right  = str_pad( $right, $length, '0', STR_PAD_LEFT );
		$result = '';
		$carry  = 0;
		for ( $index = $length - 1; $index >= 0; --$index ) {
			$sum    = (int) $left[ $index ] + (int) $right[ $index ] + $carry;
			$result = (string) ( $sum % 10 ) . $result;
			$carry  = intdiv( $sum, 10 );
		}
		return self::trim_integer( ( $carry ? (string) $carry : '' ) . $result );
	}

	private static function multiply_integer( string $left, string $right ): string {
		$left   = self::trim_integer( $left );
		$right  = self::trim_integer( $right );
		$result = array_fill( 0, strlen( $left ) + strlen( $right ), 0 );
		for ( $i = strlen( $left ) - 1; $i >= 0; --$i ) {
			for ( $j = strlen( $right ) - 1; $j >= 0; --$j ) {
				$position             = $i + $j + 1;
				$result[ $position ] += (int) $left[ $i ] * (int) $right[ $j ];
			}
		}
		for ( $index = count( $result ) - 1; $index > 0; --$index ) {
			$result[ $index - 1 ] += intdiv( $result[ $index ], 10 );
			$result[ $index ]     %= 10;
		}
		return self::trim_integer( implode( '', $result ) );
	}

	private static function divide_round_half_up( string $dividend, string $divisor ): string {
		$division = self::divide_integer( $dividend, $divisor );
		$quotient = $division['quotient'];
		if ( self::compare_integer( self::multiply_integer( $division['remainder'], '2' ), $divisor ) >= 0 ) {
			$quotient = self::add_integer( $quotient, '1' );
		}
		return $quotient;
	}

	/** @return array{quotient:string,remainder:string} */
	private static function divide_integer( string $dividend, string $divisor ): array {
		$dividend = self::trim_integer( $dividend );
		$divisor  = self::trim_integer( $divisor );
		if ( '0' === $divisor ) {
			throw new \DivisionByZeroError();
		}
		$quotient  = '';
		$remainder = '0';
		for ( $index = 0; $index < strlen( $dividend ); ++$index ) {
			$remainder = self::trim_integer( $remainder . $dividend[ $index ] );
			$digit     = 0;
			while ( self::compare_integer( $remainder, $divisor ) >= 0 ) {
				$remainder = self::subtract_integer( $remainder, $divisor );
				++$digit;
			}
			$quotient .= (string) $digit;
		}
		return array(
			'quotient'  => self::trim_integer( $quotient ),
			'remainder' => self::trim_integer( $remainder ),
		);
	}

	private static function subtract_integer( string $left, string $right ): string {
		$length = max( strlen( $left ), strlen( $right ) );
		$left   = str_pad( $left, $length, '0', STR_PAD_LEFT );
		$right  = str_pad( $right, $length, '0', STR_PAD_LEFT );
		$result = '';
		$borrow = 0;
		for ( $index = $length - 1; $index >= 0; --$index ) {
			$digit = (int) $left[ $index ] - $borrow - (int) $right[ $index ];
			if ( $digit < 0 ) {
				$digit += 10;
				$borrow = 1;
			} else {
				$borrow = 0;
			}
			$result = (string) $digit . $result;
		}
		return self::trim_integer( $result );
	}

	private static function compare_integer( string $left, string $right ): int {
		$left              = self::trim_integer( $left );
		$right             = self::trim_integer( $right );
		$length_comparison = strlen( $left ) <=> strlen( $right );
		return 0 !== $length_comparison ? $length_comparison : strcmp( $left, $right );
	}

	private static function trim_integer( string $digits ): string {
		$trimmed = ltrim( $digits, '0' );
		return '' === $trimmed ? '0' : $trimmed;
	}
}
