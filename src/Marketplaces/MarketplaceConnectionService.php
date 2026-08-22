<?php

namespace Stockino\Marketplaces;

use Stockino\Database\MarketplaceRepository;
use Stockino\Marketplaces\Basalam\BasalamAdapter;
use Stockino\Marketplaces\Mock\MockMarketplaceAdapter;

final class MarketplaceConnectionService {
	private MarketplaceRepository $repository;

	public function __construct( MarketplaceRepository $repository ) {
		$this->repository = $repository;
	}

	/** @return array<int, array<string, mixed>> */
	public function get_connections(): array {
		$connections = $this->repository->get_connections();

		// Default mock & basalam entries if empty
		if ( empty( $connections ) ) {
			$this->repository->save_connection(
				array(
					'marketplace'       => 'mock',
					'name'              => 'بازارگاه آزمایشی (Mock)',
					'status'            => 'active',
					'vendor_id'         => 'mock-vendor-101',
					'vendor_name'       => 'غرفه آزمایشی استوکینو',
					'vendor_identifier' => 'stockino-demo-shop',
					'preparation_days'  => 1,
				)
			);
			$this->repository->save_connection(
				array(
					'marketplace'      => 'basalam',
					'name'             => 'بازارگاه باسلام',
					'status'           => 'disconnected',
					'preparation_days' => 1,
				)
			);
			$connections = $this->repository->get_connections();
		}

		return array_map(
			static function ( array $conn ): array {
				// Mask credentials
				if ( ! empty( $conn['credentials'] ) ) {
					$creds = json_decode( (string) $conn['credentials'], true );
					if ( is_array( $creds ) && ! empty( $creds['access_token'] ) ) {
						$token                        = (string) $creds['access_token'];
						$creds['access_token_masked'] = strlen( $token ) > 8
							? substr( $token, 0, 4 ) . '...' . substr( $token, -4 )
							: '***';
					}
					$conn['credentials_meta'] = $creds;
					unset( $conn['credentials'] );
				}
				return $conn;
			},
			$connections
		);
	}

	/**
	 * @param string $marketplace
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function save_connection( string $marketplace, array $data ): array {
		$allowed = array( 'basalam', 'digikala', 'torob', 'mock' );
		if ( ! in_array( $marketplace, $allowed, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'بازارگاه نامعتبر است: %s', $marketplace ) );
		}

		$existing = $this->repository->get_connection_by_marketplace( $marketplace );
		$name     = ! empty( $data['name'] ) ? (string) $data['name'] : ( 'basalam' === $marketplace ? 'بازارگاه باسلام' : 'بازارگاه آزمایشی (Mock)' );

		$credentials_json = null;
		if ( isset( $data['credentials'] ) && is_array( $data['credentials'] ) ) {
			$credentials_json = (string) wp_json_encode( $data['credentials'] );
		}

		$payload = array(
			'marketplace'       => $marketplace,
			'name'              => $name,
			'status'            => $data['status'] ?? ( $existing['status'] ?? 'disconnected' ),
			'vendor_id'         => $data['vendor_id'] ?? ( $existing['vendor_id'] ?? null ),
			'vendor_name'       => $data['vendor_name'] ?? ( $existing['vendor_name'] ?? null ),
			'vendor_identifier' => $data['vendor_identifier'] ?? ( $existing['vendor_identifier'] ?? null ),
			'preparation_days'  => (int) ( $data['preparation_days'] ?? ( $existing['preparation_days'] ?? 1 ) ),
		);

		if ( null !== $credentials_json ) {
			$payload['credentials'] = $credentials_json;
		}

		$connection_id = $this->repository->save_connection( $payload );
		$connection    = $this->repository->get_connection( $connection_id );

		$this->repository->add_log(
			$connection_id,
			null,
			'save_connection',
			'success',
			sprintf( 'تنظیمات بازارگاه %s ذخیره شد.', $marketplace )
		);

		if ( is_array( $connection ) ) {
			if ( ! empty( $connection['credentials'] ) ) {
				$creds = json_decode( (string) $connection['credentials'], true );
				if ( is_array( $creds ) && ! empty( $creds['access_token'] ) ) {
					$token                        = (string) $creds['access_token'];
					$creds['access_token_masked'] = strlen( $token ) > 8
						? substr( $token, 0, 4 ) . '...' . substr( $token, -4 )
						: '***';
				}
				$connection['credentials_meta'] = $creds;
				unset( $connection['credentials'] );
			}
		}

		return $connection ?? array();
	}

	/**
	 * @param string $marketplace
	 * @return array{status: string, account_id: string, account_name: string, message: string}
	 */
	public function test_connection( string $marketplace ): array {
		$adapter = $this->create_adapter( $marketplace );
		$conn    = $this->repository->get_connection_by_marketplace( $marketplace );
		$conn_id = $conn ? (int) $conn['id'] : 0;

		try {
			$validation = $adapter->validate_connection();
			if ( $conn_id > 0 ) {
				$this->repository->update_connection_status(
					$conn_id,
					'active',
					$validation['account_id'],
					$validation['account_name'],
					$validation['identifier'] ?? null
				);
				$this->repository->add_log(
					$conn_id,
					null,
					'test_connection',
					'success',
					sprintf( 'اتصال موفق به %s (شناسه غرفه: %s)', $validation['account_name'], $validation['account_id'] )
				);
			}

			return array(
				'status'       => 'active',
				'account_id'   => $validation['account_id'],
				'account_name' => $validation['account_name'],
				'message'      => sprintf( 'اتصال با موفقیت برقرار شد. متصل به غرفه «%s»', $validation['account_name'] ),
			);
		} catch ( \Exception $e ) {
			if ( $conn_id > 0 ) {
				$this->repository->update_connection_status( $conn_id, 'invalid' );
				$this->repository->add_log(
					$conn_id,
					null,
					'test_connection',
					'failure',
					'خطا در تست اتصال: ' . $e->getMessage()
				);
			}

			throw new \RuntimeException( 'خطا در برقراری اتصال به بازارگاه: ' . $e->getMessage() );
		}
	}

	/** @return array<int, array{id: string, label: string, parentId: string|null}> */
	public function get_categories( string $marketplace = 'basalam' ): array {
		$adapter = $this->create_adapter( $marketplace );
		return $adapter->get_categories();
	}

	public function create_adapter( string $marketplace ): MarketplaceAdapterInterface {
		$conn = $this->repository->get_connection_by_marketplace( $marketplace );

		if ( 'mock' === $marketplace ) {
			$behavior = 'success';
			if ( $conn && ! empty( $conn['credentials'] ) ) {
				$creds = json_decode( (string) $conn['credentials'], true );
				if ( is_array( $creds ) && ! empty( $creds['behavior'] ) ) {
					$behavior = (string) $creds['behavior'];
				}
			}
			return new MockMarketplaceAdapter( $behavior );
		}

		if ( 'basalam' === $marketplace ) {
			$access_token     = '';
			$vendor_id        = $conn['vendor_id'] ?? null;
			$preparation_days = (int) ( $conn['preparation_days'] ?? 1 );

			if ( $conn && ! empty( $conn['credentials'] ) ) {
				$creds = json_decode( (string) $conn['credentials'], true );
				if ( is_array( $creds ) && ! empty( $creds['access_token'] ) ) {
					$access_token = (string) $creds['access_token'];
				}
			}

			// If no token stored, fallback to mock if requested or throw
			if ( empty( $access_token ) ) {
				// Check constant or environment
				if ( defined( 'STOCKINO_BASALAM_TOKEN' ) && STOCKINO_BASALAM_TOKEN ) {
					$access_token = STOCKINO_BASALAM_TOKEN;
				}
			}

			if ( empty( $access_token ) ) {
				throw new \RuntimeException( 'اعتبارنامه بازارگاه باسلام تنظیم نشده است. لطفاً توکن دسترسی را وارد کنید.' );
			}

			return new BasalamAdapter( $access_token, $vendor_id, $preparation_days );
		}

		if ( 'digikala' === $marketplace ) {
			$api_key   = '';
			$seller_id = (string) ( $conn['vendor_id'] ?? '' );
			if ( $conn && ! empty( $conn['credentials'] ) ) {
				$creds = json_decode( (string) $conn['credentials'], true );
				if ( is_array( $creds ) && ! empty( $creds['access_token'] ) ) {
					$api_key = (string) $creds['access_token'];
				}
			}
			return new \Stockino\Marketplaces\Digikala\DigikalaAdapter( $api_key, $seller_id );
		}

		if ( 'torob' === $marketplace ) {
			$api_token = '';
			$shop_id   = (string) ( $conn['vendor_id'] ?? '' );
			if ( $conn && ! empty( $conn['credentials'] ) ) {
				$creds = json_decode( (string) $conn['credentials'], true );
				if ( is_array( $creds ) && ! empty( $creds['access_token'] ) ) {
					$api_token = (string) $creds['access_token'];
				}
			}
			return new \Stockino\Marketplaces\Torob\TorobAdapter( $api_token, $shop_id );
		}

		throw new \InvalidArgumentException( sprintf( 'نوع بازارگاه ناشناخته است: %s', $marketplace ) );
	}
}
