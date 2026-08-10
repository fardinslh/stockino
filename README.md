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
docker compose run --rm wpcli wp stockino fixtures --count=2000
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/inventory.php
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/performance.php
```

Fixture generation is permitted only when `wp_get_environment_type()` is exactly `local` or `development`; staging, production, and unknown/default environments are rejected. `--start=<index>` supports extending an existing development catalog without reusing fixture SKUs.

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

Simple products, variable parents, and variations remain distinct rows. A variation that inherits parent stock is visible but cannot be adjusted independently. Low stock follows WooCommerce's full definition: a variation's own threshold, then its parent's threshold, then the global threshold, with quantity strictly above `woocommerce_notify_no_stock_amount`. The DTO, list filter, and summary statistics share those bounds. Inventory mutation always uses `wc_update_product_stock()`; product stock is never written through raw SQL.

Delta updates use WooCommerce's atomic `increase`/`decrease` operation. The ledger derives `before = returned_after - effective_delta`, so a concurrent change between the initial validation read and WooCommerce's atomic update cannot corrupt movement arithmetic. Zero deltas are rejected. Set updates carry `expected_current` and return HTTP 409 when the dialog is stale; no-op sets are rejected. Negative results are accepted only when WooCommerce permits backorders. Bulk delta adjustment is capped at 100 IDs and returns separate `updated` and `failed` arrays.

External changes made through supported WooCommerce stock APIs are captured from the product/variation before/after stock hooks. Stockino uses an in-request, per-product suppression counter around its own updates, preventing duplicate manual and external ledger rows. Direct database/meta writes by other code cannot be tracked reliably and are deliberately not guessed.

Inventory listing runs one read-only SQL query that returns only the current page's IDs with `LIMIT`/`OFFSET`, plus a separate `COUNT(DISTINCT ...)` query for pagination. Search, parent-aware category, product type, stock status, manage-stock, and low-stock constraints are combined before pagination. Only those bounded IDs are hydrated through WooCommerce CRUD objects; categories and latest movements are loaded in batches. CSV calculates the matching count on its first page and reuses it for later pages. No stock mutation uses SQL. Exact SKU and product-ID search are supported; partial SKU scanning is intentionally not performed.

### Catalog performance check

On 2026-08-10, the local Docker check used 2,000 parent products plus 570 variation rows. `page=1&per_page=20&manage_stock=true&low_stock=true` returned 20 of 443 matches in 139.37 ms, with a measured PHP peak-memory delta of 0.00 MiB. The response hydrated 20 products; the remaining matching IDs stayed in the database. This is a development measurement, not a production latency guarantee.

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
