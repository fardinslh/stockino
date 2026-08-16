<?php

namespace Stockino\Support;

use Stockino\Database\MarketplaceRepository;
use Stockino\Marketplaces\MarketplaceConnectionService;
use Stockino\Marketplaces\PublicationService;

final class MarketplaceFixtureCommand {
	private MarketplaceRepository $repository;
	private MarketplaceConnectionService $connections;
	private PublicationService $publication;

	public function __construct(
		MarketplaceRepository $repository,
		MarketplaceConnectionService $connections,
		PublicationService $publication
	) {
		$this->repository  = $repository;
		$this->connections = $connections;
		$this->publication = $publication;
	}

	public function register(): void {
		\WP_CLI::add_command( 'stockino marketplace-fixtures', array( $this, 'generate' ) );
	}

	public function generate( array $args, array $assoc_args ): void {
		$environment = wp_get_environment_type();
		if ( ! FixtureEnvironment::is_allowed( $environment ) ) {
			\WP_CLI::error( sprintf( 'Fixtures are disabled in the %s environment. Use local or development only.', $environment ) );
		}

		// Ensure mock connection is active
		$conn_id = $this->repository->save_connection(
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

		// Also create basalam shell connection
		$this->repository->save_connection(
			array(
				'marketplace'      => 'basalam',
				'name'             => 'بازارگاه باسلام',
				'status'           => 'disconnected',
				'preparation_days' => 1,
			)
		);

		// Get up to 10 products and publish them to mock marketplace
		global $wpdb;
		$product_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation') AND post_status = 'publish' LIMIT 10" );

		$published = 0;
		if ( is_array( $product_ids ) ) {
			foreach ( $product_ids as $id ) {
				try {
					$this->publication->publish_product( (int) $id, 'mock', array( 'category_external_id' => 'mock-101' ) );
					++$published;
				} catch ( \Exception $e ) {
					// Ignore single failure in fixture seeding
				}
			}
		}

		\WP_CLI::success( sprintf( 'Created Marketplace fixtures (Mock connection active, %d products published).', $published ) );
	}
}
