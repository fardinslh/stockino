# Stockino

Stockino is an independent commercial WooCommerce operations plugin for purchasing and inventory. Version `0.1.0` covers the Phase 0 foundation; the Phase 1 inventory dashboard and stock ledger are developed on top of this boundary.

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

## Architecture

- `stockino.php`: guarded bootstrap and centralized plugin/database versions.
- `src/Admin`: WordPress menu and page-scoped Vite asset loading.
- `src/Database`: versioned, activation/upgrade-only migrations using `stockino_db_version`.
- `admin/src`: scoped React, strict TypeScript, TanStack Query, Tailwind, and RTL UI.
- `tests`: focused PHPUnit tests.

The first owned table is `{prefix}stockino_stock_movements`. Inventory mutation code uses WooCommerce CRUD/stock APIs rather than direct product-meta SQL.

## Commands

```bash
npm run typecheck
npm run build
docker compose run --rm --entrypoint php composer vendor/bin/phpunit
docker compose run --rm --entrypoint php composer vendor/bin/phpcs --standard=phpcs.xml.dist
```

## Data retention

Stock movements are operational audit records and are retained on uninstall by default. Stockino never removes WooCommerce products, orders, or stock data.
