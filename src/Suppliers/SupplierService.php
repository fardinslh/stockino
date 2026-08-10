<?php

namespace Stockino\Suppliers;

use Stockino\Database\SupplierRepository;
use WP_Error;

final class SupplierService {
	public function __construct(
		private readonly SupplierRepository $repository,
		private readonly SupplierValidator $validator
	) {}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function create( array $input ) {
		$data = $this->validator->supplier( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( null !== $data['code'] && $this->repository->code_exists( $data['code'] ) ) {
			return $this->duplicate_code_error();
		}
		try {
			$id = $this->repository->create( $data );
		} catch ( \RuntimeException $exception ) {
			return 'duplicate_code' === $exception->getMessage() ? $this->duplicate_code_error() : $this->storage_error();
		}
		return $this->repository->find( $id );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function update( int $id, array $input ) {
		if ( ! $this->repository->find( $id ) ) {
			return $this->not_found_error();
		}
		$data = $this->validator->supplier( $input, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( array_key_exists( 'code', $data ) && null !== $data['code'] && $this->repository->code_exists( $data['code'], $id ) ) {
			return $this->duplicate_code_error();
		}
		if ( array() !== $data ) {
			try {
				$this->repository->update( $id, $data );
			} catch ( \RuntimeException $exception ) {
				return 'duplicate_code' === $exception->getMessage() ? $this->duplicate_code_error() : $this->storage_error();
			}
		}
		return $this->repository->find( $id );
	}

	/** @return array<string,mixed>|WP_Error */
	public function set_status( int $id, string $status ) {
		return $this->update( $id, array( 'status' => $status ) );
	}

	/** @return array<string,mixed>|WP_Error */
	public function get( int $id ) {
		return $this->repository->find( $id ) ?? $this->not_found_error();
	}

	/** @return array<string,mixed> */
	public function list( int $page, int $per_page, string $search, string $status, ?bool $has_products ): array {
		return $this->repository->paginate( $page, $per_page, sanitize_text_field( $search ), sanitize_key( $status ), $has_products );
	}

	/** @return array<string,int> */
	public function stats(): array {
		return $this->repository->stats();
	}

	private function duplicate_code_error(): WP_Error {
		return new WP_Error( 'stockino_duplicate_supplier_code', __( 'This supplier code is already in use.', 'stockino' ), array( 'status' => 409 ) );
	}

	private function not_found_error(): WP_Error {
		return new WP_Error( 'stockino_supplier_not_found', __( 'The requested supplier does not exist.', 'stockino' ), array( 'status' => 404 ) );
	}

	private function storage_error(): WP_Error {
		return new WP_Error( 'stockino_supplier_storage_failed', __( 'The supplier could not be saved.', 'stockino' ), array( 'status' => 500 ) );
	}
}
