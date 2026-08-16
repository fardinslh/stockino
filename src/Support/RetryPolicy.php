<?php

declare(strict_types=1);

namespace Stockino\Support;

final class RetryPolicy {
	/**
	 * @template T
	 * @param callable(): T $operation
	 * @param int $max_attempts
	 * @param int $initial_delay_ms
	 * @param float $backoff_multiplier
	 * @param callable(\Throwable, int): void|null $on_retry
	 * @return T
	 * @throws \Throwable
	 */
	public static function execute(
		callable $operation,
		int $max_attempts = 3,
		int $initial_delay_ms = 500,
		float $backoff_multiplier = 2.0,
		?callable $on_retry = null
	): mixed {
		$attempt  = 0;
		$delay_ms = $initial_delay_ms;

		while ( true ) {
			++$attempt;
			try {
				return $operation();
			} catch ( \Throwable $e ) {
				if ( $attempt >= $max_attempts ) {
					throw $e;
				}

				// Check if error is retryable (429 rate limit or 5xx server error or network timeout)
				$msg = $e->getMessage();
				$code = $e->getCode();
				$is_retryable = ( 429 === $code || ( $code >= 500 && $code < 600 ) || str_contains( $msg, '429' ) || str_contains( $msg, 'timeout' ) || str_contains( $msg, 'timed out' ) );

				if ( ! $is_retryable && 0 !== $code ) {
					throw $e;
				}

				if ( null !== $on_retry ) {
					$on_retry( $e, $attempt );
				}

				// Exponential backoff with small random jitter
				$jitter = (int) wp_rand( 50, 150 );
				$sleep_ms = (int) round( $delay_ms + $jitter );
				usleep( $sleep_ms * 1000 );

				$delay_ms = (int) round( $delay_ms * $backoff_multiplier );
			}
		}
	}
}
