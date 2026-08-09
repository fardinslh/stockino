# Stockino

Stockino is an independent commercial WooCommerce operations plugin for purchasing and inventory. Version `0.1.0` contains the Phase 0 foundation and the Phase 1 inventory dashboard and stock ledger. Suppliers, purchase orders, receiving, valuation, and reorder suggestions are intentionally out of scope.

## Requirements

- WordPress 6.5+
- WooCommerce 8.5+
- PHP 8.2+
- Node.js 20+ for frontend builds
- Composer 2 for PHP development

Stockino uses its own `Stockino\\` PHP namespace, `stockino` text domain, `stockino/v1` REST namespace, `stockino_*` options/tables, and `stockino-` asset handles/classes so it can coexist with Orderino.

## Development

```bash
npm install
npm run build
docker compose run --rm composer install
docker compose up -d db wordpress
docker compose run --rm wpcli wp core install --url=http://localhost:8088 --title=Stockino --admin_user=admin --admin_password=admin --admin_email=dev@example.test --skip-email
docker compose run --rm wpcli wp plugin install woocommerce --activate
docker compose run --rm wpcli wp plugin activate stockino
```

Open `http://localhost:8088/wp-admin/admin.php?page=stockino`. Docker is development tooling only; Stockino has no production Docker dependency.

Generate a representative catalog and run the repeatable API smoke suite:

```bash
docker compose run --rm wpcli wp stockino fixtures --count=100
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/inventory.php
```

## Architecture

- `stockino.php`: guarded bootstrap and centralized plugin/database versions.
- `src/Admin`: WordPress menu and page-scoped Vite asset loading.
- `src/Database`: versioned, activation/upgrade-only migrations using `stockino_db_version`, plus the movement repository.
- `src/Inventory`: WooCommerce queries, DTOs, stock mutation, stock math, and external-change tracking.
- `src/REST`: authenticated `stockino/v1` management endpoints.
- `admin/src`: scoped React, strict TypeScript, TanStack Query, Tailwind, and responsive RTL UI.
- `tests`: focused PHPUnit tests.

## Inventory model and ledger

The table `{prefix}stockino_stock_movements` stores `product_id`, optional `variation_id`, movement/reason identifiers, before/delta/after decimal quantities, optional reference, actor, note/metadata, and UTC creation time. It has a primary key plus targeted product/date, variation/date, movement-type, and date indexes.

Simple products, variable parents, and variations remain distinct rows. A variation that inherits parent stock is visible but cannot be adjusted independently. Low stock uses `wc_get_low_stock_amount()`, which resolves product-level and global WooCommerce thresholds. Inventory mutation always uses `wc_update_product_stock()`; product stock is never written through raw SQL.

Delta updates use WooCommerce's atomic `increase`/`decrease` operation. Set updates carry `expected_current` and return HTTP 409 when the dialog is stale. Negative results are accepted only when WooCommerce permits backorders. Bulk delta adjustment is capped at 100 IDs and returns separate `updated` and `failed` arrays.

External changes made through supported WooCommerce stock APIs are captured from the product/variation before/after stock hooks. Stockino uses an in-request, per-product suppression counter around its own updates, preventing duplicate manual and external ledger rows. Direct database/meta writes by other code cannot be tracked reliably and are deliberately not guessed.

Inventory listing uses WooCommerce product queries and batched category/latest-movement loading. The low-stock filter and summary totals use bounded read-only aggregation over WooCommerce's product lookup table because the public product query cannot express global-or-product threshold comparisons. No stock mutation uses SQL. Exact SKU and product-ID search are supported; partial SKU scanning is intentionally not performed.

## REST API

All routes require an authenticated user with `manage_woocommerce` and a WordPress REST nonce in browser requests.

- `GET /stockino/v1/inventory`
- `GET /stockino/v1/inventory/stats`
- `GET /stockino/v1/inventory/filters`
- `GET /stockino/v1/products/{id}/movements`
- `POST /stockino/v1/products/{id}/adjust-stock`
- `POST /stockino/v1/inventory/bulk-adjust`
- `GET /stockino/v1/inventory/export`

List/history endpoints are server-paginated. CSV export includes only product/variation identity and inventory fields and prefixes formula-like text values to prevent spreadsheet injection.

## Commands

```bash
npm run typecheck
npm run build
npm run qa:browser
docker compose run --rm --entrypoint php composer vendor/bin/phpunit
docker compose run --rm --entrypoint php composer vendor/bin/phpcs --standard=phpcs.xml.dist
```

Browser QA uses local Chrome by default. Set `STOCKINO_BROWSER_PATH` and `STOCKINO_BASE_URL` when those paths differ.

## Known limitations

- Hook-based external tracking covers supported WooCommerce CRUD/stock operations, not plugins that bypass WooCommerce and write product meta directly.
- Set mode uses an optimistic stale-value check; it does not claim a distributed lock. Delta mode is safer under concurrency because WooCommerce applies it atomically.
- Total-unit stats intentionally include signed stock quantities when backorders allow negative inventory.
- Category options are capped at 200 in Phase 1; catalog search supports names, exact SKU, and practical numeric IDs.

## Manual QA

Validate activation with and without WooCommerce; admin asset scoping; 20/50/100 pagination; name/SKU search; type/status/category/manage-stock/low-stock filters; simple, parent, and variation rows; +5, -3, set, stale-set and negative/backorder adjustments; exactly-one movement rows; actor/reason/note history; partial bulk failure and the 100-ID cap; CSV output; desktop/390px RTL layout; clean browser console; and activation alongside Orderino. The automated smoke script covers the server-side mutation, permission, pagination, search, variation, ledger, bulk, and CSV paths.

## Data retention

Stock movements are operational audit records and are retained on uninstall by default. Stockino never removes WooCommerce products, orders, or stock data.
