<?php

declare(strict_types=1);

namespace Stockino\Support\Observability;

use Stockino\Database\MarketplaceRepository;

final class StructuredLogger {
	private ?MarketplaceRepository $repository;

	public function __construct( ?MarketplaceRepository $repository = null ) {
		$this->repository = $repository;
	}

	/**
	 * @param array{
	 *   sync_id?: string,
	 *   request_id?: string,
	 *   marketplace: string,
	 *   operation: string,
	 *   status: 'success' | 'failure' | 'warning' | 'retrying',
	 *   product_id?: int|null,
	 *   order_id?: string|null,
	 *   attempt?: int,
	 *   error_code?: string|null,
	 *   reason?: string|null,
	 *   connection_id?: int|null,
	 *   message: string,
	 *   context?: array<string, mixed>
	 * } $data
	 */
	public function log( array $data ): void {
		$connection_id = $data['connection_id'] ?? null;
		$product_id    = $data['product_id'] ?? null;
		$action        = $data['operation'];
		$status        = match ( $data['status'] ) {
			'success' => 'success',
			'warning' => 'warning',
			default => 'failure',
		};

		$message = $data['message'];
		if ( ! empty( $data['error_code'] ) ) {
			$message = sprintf( '[%s] %s', $data['error_code'], $message );
		}

		$context = $this->redact_sensitive_data( $data['context'] ?? array() );

		$payload = array(
			'sync_id'    => $data['sync_id'] ?? ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) ) ),
			'request_id' => $data['request_id'] ?? null,
			'attempt'    => $data['attempt'] ?? 1,
			'order_id'   => $data['order_id'] ?? null,
			'context'    => $context,
		);

		$encoded_payload = wp_json_encode( $payload );

		if ( $this->repository ) {
			$this->repository->add_log(
				$connection_id,
				$product_id,
				$action,
				$status,
				$message,
				$encoded_payload ? $encoded_payload : null
			);
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( '[Stockino][%s][%s] %s %s', $data['marketplace'], $action, $message, $encoded_payload ) );
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function redact_sensitive_data( array $data ): array {
		$sensitive_keys = array( 'token', 'access_token', 'pat', 'secret', 'password', 'api_key', 'authorization', 'credentials' );
		$sanitized      = array();

		foreach ( $data as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $sensitive_keys, true ) ) {
				$sanitized[ $key ] = '***REDACTED***';
			} elseif ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->redact_sensitive_data( $value );
			} else {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}
}
