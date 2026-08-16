# Changelog

All notable changes to Stockino are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-08-17

### Added
- **Core Inventory & Ledger (Phase 1)**:
  - Real-time stock movement ledger with atomic delta adjustments and stale-value concurrency guards.
  - Comprehensive filter system (search, categories, low-stock, manage-stock, product types).
  - Secure CSV export with spreadsheet formula injection protection.
- **Supplier Management (Phase 2)**:
  - Full supplier lifecycle management (create, edit, archive, reactivate) without destructive hard deletes.
  - Many-to-many supplier product relationships with custom supplier SKUs, MOQ, order multiples, and lead-time overrides.
- **Purchase Orders & Receiving (Phase 3)**:
  - Complete PO lifecycle (`draft → ordered → partially_received → received → cancelled`).
  - Idempotent purchase receiving with MySQL advisory locking and safety accounting (`confirmed_units`, `attention_units`, `failed_units`).
- **Inventory Costing & Valuation (Phase 4)**:
  - Continuous moving weighted-average cost computation with round-half-up six-decimal fixed-point math.
  - Immutable cost movement audit trail, uncosted stock safety warnings, and initial-cost correction workflows.
- **Reorder Intelligence (Phase 5)**:
  - Deterministic low-stock reorder recommendations based on lead times, MOQ, order multiples, and incoming PO quantities.
  - One-click grouped purchase order draft generation per supplier.
- **Marketplaces & Publishing Engine (Phase 6–10)**:
  - Database schema v5.0.0 for marketplace connections, product links, and structured logs.
  - Multi-marketplace adapter architecture: `BasalamAdapter` (OpenAPI REST + PAT), `DigikalaAdapter` (Seller API), `TorobAdapter`, and `MockMarketplaceAdapter`.
  - Generic product publishing engine with selective field synchronization (Price, Inventory, Images, Description, Dry-Run preview).
  - Robust inventory synchronization with recursion and feedback-loop guards.
  - Order synchronization and normalization into WooCommerce `WC_Order` instances.
  - Two-way Orderino fulfillment bridge dispatching postal tracking numbers back to marketplaces upon shipment.
  - Inbound webhook receiver at `POST /stockino/v1/webhooks/{marketplace}` with signature validation.
  - CSV and dependency-free XLSX product importing with row limits and formula injection sanitization.
  - High-Performance Order Storage (HPOS) compatibility declaration.
- **Admin UI & Typography**:
  - React 18 SPA built with Vite, TypeScript, TanStack Query, and Tailwind CSS.
  - Native Persian RTL support bundled with local Vazirmatn fonts.
- **WP-CLI Suite**:
  - `wp stockino import`, `wp stockino publish`, `wp stockino sync-inventory`, `wp stockino sync-orders`, `wp stockino sync-all`.
- **CI/CD Pipeline**:
  - GitHub Actions workflow covering PHP lint, PHPCS, PHPUnit, TypeScript typecheck, Vite build, npm audit, and repository hygiene.
