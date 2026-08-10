# Stockino

Stockino is an independent commercial WooCommerce operations plugin for purchasing and inventory. Version `0.1.0` contains the Phase 0 foundation, Phase 1 inventory dashboard and stock ledger, Phase 2 supplier management, and Phase 3 purchase orders and receiving. Valuation and reorder suggestions remain intentionally out of scope.

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
docker compose run --rm wpcli wp stockino supplier-fixtures
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/inventory.php
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/performance.php
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/suppliers.php
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/supplier-performance.php
```

Fixture generation is permitted only when `wp_get_environment_type()` is exactly `local` or `development`; staging, production, and unknown/default environments are rejected. `--start=<index>` supports extending an existing development catalog without reusing fixture SKUs.

## Architecture

- `stockino.php`: guarded bootstrap and centralized plugin/database versions.
- `src/Admin`: WordPress menu and page-scoped Vite asset loading.
- `src/Database`: versioned, activation/upgrade-only migrations using `stockino_db_version`, plus the movement repository.
- `src/Inventory`: WooCommerce queries, DTOs, stock mutation, stock math, and external-change tracking.
- `src/Suppliers`: supplier and catalog-relationship validation and business services.
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

On 2026-08-10, the local Docker check used 2,000 parent products plus 570 variation rows. `page=1&per_page=20&manage_stock=true&low_stock=true` returned 20 of 443 matches in 117.84 ms, with a measured PHP peak-memory delta of 0.00 MiB. The response hydrated 20 products; the remaining matching IDs stayed in the database. This is a development measurement, not a production latency guarantee.

## Supplier Management

Suppliers are stored in dedicated `stockino_suppliers` rows rather than WordPress posts. Profiles include an optional case-normalized unique code, active/inactive status, contact details, website, address, default lead time, notes, creator, and UTC timestamps. Archiving is the normal lifecycle action; Phase 2 intentionally exposes no hard-delete endpoint so future purchasing history can retain stable supplier identity.

The `stockino_supplier_products` table models many-to-many catalog relationships for simple products, variable parents, and exact variation IDs. It stores supplier SKU, optional lead-time override, decimal minimum order quantity, order multiple, notes, and UTC timestamps. Purchasing quantities are normalized to `DECIMAL(20,6)` and must remain positive after normalization. Variation IDs are never collapsed to their parent. Effective lead time is the relationship override, then the supplier default, then unknown. This metadata never changes WooCommerce inventory and never creates stock movements.

Supplier lists and linked-product lists use separate prepared `COUNT` and bounded `LIMIT/OFFSET` queries. Linked-product counts are aggregated in the supplier list query rather than queried per row, and all public counts and totals exclude orphan or non-product relationships. A bounded page of relation product IDs is hydrated with WooCommerce CRUD objects. Permanent product deletion removes exact supplier relationships; deleting a variable parent also removes its variation relationships, while trash and ordinary catalog status changes preserve them. The remote product picker reuses the Phase 1 bounded inventory search and returns at most the requested page.

## Purchase Orders and Receiving

Phase 3 stores purchase orders, immutable supplier/product snapshots, receipt headers, and receipt lines in four dedicated Stockino tables. Drafts are structurally editable; marking ordered locks supplier, line identity, and ordered quantities. The explicit state machine is `draft → ordered → partially_received → received`, with cancellation allowed from draft, ordered, or partially received. Cancellation never reverses inventory already received. Phase 3 intentionally contains no purchase prices, costing, tax, totals, or inventory valuation.

Ordered and received quantities use fixed six-decimal string arithmetic compatible with `DECIMAL(20,6)`; PHP floats are not authoritative for ordered/received/remaining calculations. Before receiving, Stockino verifies that WooCommerce's effective stock amount exactly represents the requested business quantity. A variation remains the PO/receipt source identity, while `get_stock_managed_by_id()` selects the actual WooCommerce stock owner. Parent-managed variation receipts therefore update the parent but retain both IDs in receipt and movement metadata.

Each receive operation has a database-unique client idempotency key and acquires a short, per-PO MySQL advisory lock. Current line quantities are re-read and the whole request is prevalidated after lock acquisition. Inventory increments use WooCommerce's relative `wc_update_product_stock(..., 'increase')` path through the same internal mutation service as Phase 1 manual adjustments. The external tracker is suppressed for the actual stock owner, producing exactly one `purchase_receipt` movement per successful line. Completed-token retries return the existing receipt; processing and `requires_attention` tokens never replay inventory mutation.

If stock changes but later audit persistence fails, the receipt and affected line become `requires_attention`, the known applied quantity is accounted for when possible, and automatic replay is blocked. Successful earlier lines in a multi-line request remain recorded; Stockino does not attempt a potentially unsafe automatic stock reversal. An administrator must reconcile any attention state against WooCommerce and the physical receipt.

Purchase-order lists use a prepared count plus one aggregate, bounded list query. Receipt history is paginated. Development fixtures are restricted to `local` and `development` and create receipt examples through the real receiving service.

On 2026-08-10, the local fixture check returned 20 of 20 suppliers in 1.03 ms and 20 of 30 relationships for `SUP-001` in 8.66 ms. These are local development observations, not production latency guarantees.

## REST API

All routes require an authenticated user with `manage_woocommerce` and a WordPress REST nonce in browser requests.

- `GET /stockino/v1/inventory`
- `GET /stockino/v1/inventory/stats`
- `GET /stockino/v1/inventory/filters`
- `GET /stockino/v1/products/{id}/movements`
- `POST /stockino/v1/products/{id}/adjust-stock`
- `POST /stockino/v1/inventory/bulk-adjust`
- `GET /stockino/v1/inventory/export`
- `GET|POST /stockino/v1/suppliers`
- `GET|PUT|PATCH /stockino/v1/suppliers/{id}`
- `POST /stockino/v1/suppliers/{id}/archive`
- `POST /stockino/v1/suppliers/{id}/reactivate`
- `GET|POST /stockino/v1/suppliers/{id}/products`
- `PUT|PATCH|DELETE /stockino/v1/suppliers/{id}/products/{product_id}`
- `GET /stockino/v1/products/{id}/suppliers`
- `GET /stockino/v1/products/search`
- `GET|POST /stockino/v1/purchase-orders`
- `GET|PUT|PATCH /stockino/v1/purchase-orders/{id}`
- `POST /stockino/v1/purchase-orders/{id}/mark-ordered`
- `POST /stockino/v1/purchase-orders/{id}/cancel`
- `GET|POST /stockino/v1/purchase-orders/{id}/items`
- `PUT|PATCH|DELETE /stockino/v1/purchase-orders/{id}/items/{item_id}`
- `GET|POST /stockino/v1/purchase-orders/{id}/receipts`
- `GET /stockino/v1/purchase-receipts/{id}`

List/history endpoints are server-paginated. CSV export includes only product/variation identity and inventory fields and prefixes formula-like text values to prevent spreadsheet injection.

## Commands

```bash
npm run typecheck
npm run build
npm run qa:browser
npm run qa:suppliers
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

Validate activation with and without WooCommerce; admin asset scoping; inventory pagination and filters; stock adjustments and exactly-one movement behavior; supplier create/edit/archive/reactivate; supplier pagination/search; product and variation relationships; purchase-order draft/order/cancel transitions; partial and complete receiving; idempotent retry; parent-managed variation receiving; attention-state visibility; inventory non-interference; desktop/390px RTL layout; clean browser console; and activation alongside Orderino. The automated smoke scripts cover these server-side paths.

## Roadmap status

- Phase 0: foundation — complete
- Phase 1: inventory dashboard and stock ledger — complete and hardened
- Phase 2: supplier management — complete
- Phase 3: purchase orders and receiving — complete
- Phase 4: costs and inventory valuation — not started
- Phase 4–6: costing/valuation, reorder intelligence, and commercial release — not implemented

## Data retention

Stock movements are operational audit records and are retained on uninstall by default. Stockino never removes WooCommerce products, orders, or stock data.
