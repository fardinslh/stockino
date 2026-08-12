# Stockino

Stockino is an independent commercial WooCommerce operations plugin for purchasing and inventory. Version `0.1.0` contains the Phase 0 foundation, Phase 1 inventory dashboard and stock ledger, Phase 2 supplier management, Phase 3 purchase orders and receiving, Phase 4 moving-average inventory costing and valuation, and Phase 5 deterministic reorder recommendations.

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
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/purchasing.php
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/costing.php
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/valuation-performance.php
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/reorder.php
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/reorder-performance.php
```

The concurrency check uses four separate WP-CLI processes so MySQL advisory locks are exercised across real database connections:

```bash
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/reorder-concurrency.php setup
docker compose run --rm -d wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/reorder-concurrency.php worker-a
docker compose run --rm -d wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/reorder-concurrency.php worker-b
docker compose run --rm wpcli wp eval-file wp-content/plugins/stockino/tests/Smoke/reorder-concurrency.php verify
```

Fixture generation is permitted only when `wp_get_environment_type()` is exactly `local` or `development`; staging, production, and unknown/default environments are rejected. `--start=<index>` supports extending an existing development catalog without reusing fixture SKUs.

## Architecture

- `stockino.php`: guarded bootstrap and centralized plugin/database versions.
- `src/Admin`: WordPress menu and page-scoped Vite asset loading.
- `src/Database`: versioned, activation/upgrade-only migrations using `stockino_db_version`, plus the movement repository.
- `src/Inventory`: WooCommerce queries, DTOs, stock mutation, stock math, and external-change tracking.
- `src/Costing`: fixed-decimal weighted-average policy, owner locks, audited cost changes, and valuation services.
- `src/Reorder`: deterministic replenishment calculation, supplier resolution, stale-request validation, and owner-level generation locks.
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

Phase 3 stores purchase orders, immutable supplier/product snapshots, receipt headers, and receipt lines in four dedicated Stockino tables. Drafts are structurally editable; marking ordered locks supplier, line identity, ordered quantities, and optional ordered/default unit cost. The explicit state machine is `draft → ordered → partially_received → received`, with cancellation allowed from draft, ordered, or partially received. Cancellation never reverses inventory already received. Phase 4 adds actual receipt unit cost but intentionally excludes tax, shipping, discounts, accounting totals, COGS, profit, and sales-order costing.

Ordered and received quantities use fixed six-decimal string arithmetic compatible with `DECIMAL(20,6)`; PHP floats are not authoritative for ordered/received/remaining calculations. Before receiving, Stockino verifies that WooCommerce's effective stock amount exactly represents the requested business quantity. A variation remains the PO/receipt source identity, while `get_stock_managed_by_id()` selects the actual WooCommerce stock owner. Parent-managed variation receipts therefore update the parent but retain both IDs in receipt and movement metadata.

Each receive operation has a database-unique client idempotency key and acquires a short, per-PO MySQL advisory lock. Cancellation, draft metadata and line mutations, and mark-ordered use that same operation lock and re-read state after acquisition, so receiving cannot race cancellation and stale draft writes cannot cross the ordered boundary. Current receipt quantities are also re-read and the whole request is prevalidated under the lock. Completed-token retries return the existing receipt; processing, `requires_attention`, and cross-PO token reuse conflict without replaying inventory mutation.

Inventory increments use WooCommerce's relative `wc_update_product_stock(..., 'increase')` path through the same internal mutation service as Phase 1 manual adjustments. Mutation results are classified as `unchanged`, `changed`, or `uncertain`. A normal return followed by ledger failure is known `changed`; a thrown WooCommerce save/hook path is `uncertain` because persistence cannot be disproved. Both changed failures and uncertain outcomes become `requires_attention`; uncertain stock is never automatically reversed or reported as confirmed, and its accounted quantity blocks blind retries with any token. The external tracker is suppressed for normal Stockino writes, producing exactly one `purchase_receipt` movement per confirmed successful line.

Receipt history exposes `confirmed_units`, `attention_units`, and `failed_units`. The backward-compatible `received_units` label contains confirmed completed lines only; failed or halted lines are never summed as received. Safety accounting separately includes completed and `requires_attention` quantities because an attention quantity may already have reached WooCommerce. Administrators must compare the receipt line's before/observed-after/error details with WooCommerce and the physical delivery before manual reconciliation. Successful earlier lines in a multi-line request remain recorded, and Stockino never attempts a potentially unsafe automatic stock reversal.

Purchase-order lists use a prepared count plus one aggregate, bounded list query. Receipt history is paginated. Development fixtures are restricted to `local` and `development` and create receipt examples through the real receiving service.

## Inventory costing and valuation

Database version `4.0.0` adds `{prefix}stockino_inventory_costs`, with exactly one current row per WooCommerce stock owner, and `{prefix}stockino_inventory_cost_movements`, an immutable audit trail. PO lines may hold nullable `ordered_unit_cost`; receipt lines persist the independently supplied `actual_unit_cost`, currency snapshot, cost-movement link, and costing status. Unknown legacy costs remain null rather than being migrated to zero. Product IDs, names, SKU, source variation, PO, receipt, actor, quantities, averages, inventory values, reason, currency, and time are snapshotted in movements so history remains readable after product deletion.

Stockino uses moving weighted average cost. When positive pre-receipt stock has an established average, `new average = ((pre quantity × previous average) + (received quantity × actual unit cost)) ÷ post quantity`. The inputs are scaled six-decimal strings and the integer products retain their full intermediate precision; the final average and inventory value use round-half-up to six decimals. PHP floats are never authoritative for unit cost, the weighted calculation, or monetary value. A first receipt, uncosted owner, zero pre-stock, or negative pre-stock resets the current average to that receipt's actual cost. Stock at or below zero has current value zero while retaining the latest sensible average for future receipts. Sales and manual decreases do not change average cost. Non-purchase increases retain an existing average; positive stock without an average is explicitly `uncosted` and is excluded—not counted as zero—from known-value aggregates.

Cost identity follows `WC_Product::get_stock_managed_by_id()`. A self-managed variation owns an independent cost row; a parent-managed variation updates the single parent cost row while its source variation remains in history. The valuation query selects actual managed-stock owners, so shared parent inventory is never duplicated. Listing, search, stock/cost filters, sorting, and history use prepared, bounded server pagination. On 2026-08-11, the local page-size-20 check returned 20 of 2,390 owners in 12.49 ms using two catalog/cost SQL queries. This is a local observation, not a production latency guarantee.

Receiving first holds the existing PO operation lock and then acquires all affected owner-cost MySQL advisory locks in sorted owner-ID order. Releases run in `finally`; different POs receiving the same owner therefore serialize without weakening the PO boundary. The current cost is re-read while that owner lock is held. Cost movement plus current-state update use one InnoDB transaction and a database `UNIQUE(receipt_item_id)` constraint. This transaction does not claim to roll back WooCommerce stock. If stock is unchanged, cost is not applied. If stock is uncertain, costing also becomes `requires_attention`. If stock changed but cost persistence fails, Stockino preserves stock/cost diagnostics, marks the line and receipt `requires_attention`, blocks blind replay, and never attempts an automatic stock reversal.

Existing stock is never assigned an invented cost. `Set initial average cost` requires an explicit administrator value and reason, holds the owner lock, records an `initial_cost` movement, and does not touch stock. It cannot overwrite an established average. Later manual changes use the distinct `cost_correction` workflow, preserving old/new averages, stock and value snapshots, actor, reason, and immutable history. Phase 4 supports only the WooCommerce store currency and performs no currency conversion. Historical rows retain their snapshots; a receipt is blocked before stock mutation if its currency would be mixed with an established cost in another currency.

On 2026-08-10, the local fixture check returned 20 of 20 suppliers in 1.03 ms and 20 of 30 relationships for `SUP-001` in 8.66 ms. These are local development observations, not production latency guarantees.

## Deterministic reorder recommendations

Database version `5.0.0` adds nullable/indexed reorder-owner provenance to purchase-order items and the dedicated `{prefix}stockino_reorder_settings` table with a unique stock-owner row, nullable custom point/target and preferred supplier/source fields, actor IDs, UTC timestamps, and targeted indexes.

Stockino Phase 5 provides deterministic replenishment recommendations, not demand forecasting. Recommendations are derived live and are never persisted as transient forecasts. Exactly one recommendation exists for each active WooCommerce stock owner. A self-managed variation remains independent; parent-managed child variations share one parent recommendation, while their variation-specific supplier relationships remain available as possible purchase sources.

The effective reorder point is an explicit Stockino owner override when present; otherwise it is WooCommerce's product/variation low-stock amount, including a self-managed variation's parent fallback, then WooCommerce's global low-stock amount. Unknown remains `threshold_unknown`, never zero. Stockino does not overwrite WooCommerce threshold metadata. The optional `stockino_reorder_settings` row stores fixed-decimal custom point/target values and a preferred supplier/source pair with creator/updater and UTC timestamps. Target must be at least the effective point.

Confirmed incoming is the exact `ordered_quantity - safely accounted received quantity` remaining on `ordered` and `partially_received` Stockino POs. Safely accounted received is the greater of the PO line's confirmed received amount and completed/attention receipt accounting, preventing an uncertain unit from also being presented as confirmed incoming. Draft, received, and cancelled orders contribute zero. `inventory_position = current WooCommerce stock + confirmed incoming`. Receipt or costing rows in `requires_attention` are shown separately as ambiguous units and force `attention_required`; Stockino does not emit an automatic quantity until that operational ambiguity is reconciled.

When `inventory_position <= reorder point`, the default target is `max(reorder point × 2, reorder point + 1)` and raw reorder is `max(0, target - inventory position)`. A custom target replaces only that default. Stockino then raises the result to MOQ when needed and rounds up to the supplier order multiple. Every operation uses six-decimal string/integer arithmetic; float modulo and rounding are not authoritative. Urgency is categorical: critical when current stock is non-positive, high when position is below the point, and normal when position equals the point. Confirmed incoming that moves position above the point produces `covered_by_incoming`, not an urgent action.

Supplier selection is explicit preference first, then the active linked supplier with the lowest known effective lead time (relationship override before supplier default), with lower supplier ID as the tie-breaker. If all lead times are unknown, the lowest supplier ID is a clearly labeled deterministic fallback. An inactive or unlinked preference is never used. Stockino may fall back when exactly one source product identity remains; multiple competing child sources sharing a parent owner remain `supplier_selection_required` until the user selects a supplier/source pair. It never invents allocation among variations.

Selected recommendations are capped at 50 and grouped into one editable draft PO per supplier through the existing purchase-order service. Cost is prefilled only from the latest confirmed actual receipt for the same supplier/source, then the latest non-cancelled confirmed PO default; otherwise it remains null. Retail price, sale price, and moving-average inventory cost are never used as supplier prices. Expected date uses the group's longest known effective lead time and remains editable. The response lists every created PO and every skipped/stale/unresolved owner, and generated lines retain their reorder-owner provenance.

Creation acquires sorted MySQL advisory locks for all selected owners, re-reads current stock, incoming POs, relationships, and settings, recalculates every recommendation, and checks generated active drafts in one bounded batch query before inserting. This owner-lock namespace is shared with Phase 4 receiving/costing, so a receipt and reorder generation for the same owner serialize. Concurrent generation requests for the same shortage therefore create at most one active replenishment draft. Manual and third-party stock adjustment can still change operational facts immediately before or after this point-in-time decision; Stockino does not claim global serializability. PO status changes remain protected by the Phase 3 per-PO lock, and every later recommendation refresh derives from current WooCommerce and PO data.

On 2026-08-13, the local WordPress/WooCommerce check used 2,404 active stock owners. The urgency-sorted `reorder_needed` page found 10 recommendations and returned all 10 within the page-size-20 bound in 465.13 ms using four SQL queries. Supplier candidates were loaded in one batch, with no per-owner supplier, PO, or WooCommerce hydration query. This is a local development observation, not a production latency guarantee.

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
- `GET /stockino/v1/valuation`
- `GET /stockino/v1/valuation/stats`
- `GET /stockino/v1/valuation/{stock_owner_id}`
- `GET /stockino/v1/valuation/{stock_owner_id}/history`
- `POST /stockino/v1/valuation/{stock_owner_id}/initial-cost`
- `POST /stockino/v1/valuation/{stock_owner_id}/corrections`
- `GET /stockino/v1/reorder`
- `GET /stockino/v1/reorder/stats`
- `GET /stockino/v1/reorder/filters`
- `GET /stockino/v1/reorder/{stock_owner_id}`
- `GET /stockino/v1/reorder/{stock_owner_id}/incoming`
- `GET|PATCH /stockino/v1/reorder/{stock_owner_id}/settings`
- `POST /stockino/v1/reorder/create-purchase-orders`

List/history endpoints are server-paginated. CSV export includes only product/variation identity and inventory fields and prefixes formula-like text values to prevent spreadsheet injection.

## Commands

```bash
npm run typecheck
npm run build
npm run qa:browser
npm run qa:suppliers
npm run qa:purchasing
npm run qa:valuation
npm run qa:coexistence
npm run qa:reorder
docker compose run --rm --entrypoint php composer vendor/bin/phpunit
docker compose run --rm --entrypoint php composer vendor/bin/phpcs --standard=phpcs.xml.dist
```

Browser QA uses local Chrome by default. Set `STOCKINO_BROWSER_PATH` and `STOCKINO_BASE_URL` when those paths differ.

## Known limitations

- Hook-based external tracking covers supported WooCommerce CRUD/stock operations, not plugins that bypass WooCommerce and write product meta directly.
- Set mode uses an optimistic stale-value check; it does not claim a distributed lock. Delta mode is safer under concurrency because WooCommerce applies it atomically.
- Total-unit stats intentionally include signed stock quantities when backorders allow negative inventory.
- Category options are capped at 200 in Phase 1; catalog search supports names, exact SKU, and practical numeric IDs.
- An `uncertain` receiving outcome deliberately requires manual reconciliation; Stockino cannot prove whether a third-party WooCommerce save/hook exception happened before or after persistence.
- Costing supports only the current WooCommerce store currency; there is no conversion or multi-currency weighted average.
- Phase 4 is operational inventory valuation, not accounting: it intentionally provides no FIFO/LIFO layers, tax/shipping/discount allocation, COGS, profit reporting, or sales-order costing.
- Phase 5 uses threshold/target rules only. It has no demand or sales forecast, seasonality, safety-stock model, approval flow, automatic ordering, notification, marketplace integration, or external service.
- Reorder generation is owner-serialized and request-time revalidated, but it is not globally serializable with every third-party WooCommerce stock mutation.
- A shared parent stock owner with competing child sources requires an explicit supplier/source choice; Stockino does not split demand across variations.

## Manual QA

Validate activation with and without WooCommerce; admin asset scoping; inventory pagination and filters; stock adjustments and exactly-one movement behavior; supplier create/edit/archive/reactivate; supplier pagination/search; product and variation relationships; purchase-order default cost; partial and complete receiving with actual cost; idempotent retry; weighted-average history; parent- and self-managed variations; initial-cost and correction workflows; uncosted aggregate warning; reorder filters/explanations/MOQ/multiples/incoming POs/settings/grouped draft creation/stale skips/no-supplier/attention states; inventory non-interference; desktop/390px RTL layout; clean browser console; and activation alongside Orderino. The automated smoke and browser scripts cover these paths.

## Roadmap status

- Phase 0: foundation — complete
- Phase 1: inventory dashboard and stock ledger — complete and hardened
- Phase 2: supplier management — complete
- Phase 3: purchase orders and receiving — complete
- Phase 4: costs and inventory valuation — complete
- Phase 5: deterministic low-stock and reorder recommendations — complete
- Phase 6: commercial release — not implemented

## Data retention

Stock and cost movements are operational audit records and are retained on uninstall by default. Stockino never removes WooCommerce products, orders, or stock data.
