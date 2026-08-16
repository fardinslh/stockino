# Stockino 1.0.0 Commercial Release Notes

Stockino version **1.0.0** is the first commercial release of the independent WooCommerce operations plugin for purchasing, inventory management, valuation, reorder intelligence, and multi-marketplace synchronization.

---

## 1. System Requirements & Compatibility

| Component | Minimum Version | Tested Up To |
| :--- | :--- | :--- |
| **PHP** | 8.2 | 8.3 |
| **WordPress** | 6.5 | 6.7 |
| **WooCommerce** | 8.5 | 9.4 |
| **HPOS (Custom Order Tables)** | Supported | Active / Compatible |
| **Orderino Integration** | Coexistent | Optional / Decoupled |

---

## 2. Key Modules & Capabilities

### Core Inventory & Stock Ledger
- Atomic increment and decrement operations through WooCommerce core APIs.
- Stale-data concurrency checking in absolute set adjustments.
- Complete movement audit trail with actor, reference type, quantity before/delta/after, and reason.

### Supplier & Purchasing Workflows
- Soft-archive supplier lifecycle preventing broken historical links.
- Multi-supplier product catalog with relationship-specific minimum order quantities and lead times.
- Atomic purchase receiving protected by MySQL advisory locks.

### Moving-Average Valuation & Reorder Intelligence
- Weighted-average costing preserving exact accounting precision with fixed-point arithmetic.
- Reorder recommendations accounting for safety thresholds, supplier lead times, order multiples, and pending incoming purchase orders.

### Marketplace Publishing, Synchronization & Orderino Bridge
- Native OpenAPI REST integration for Basalam and Digikala.
- Selective field synchronization (Price, Inventory, Images, Description, Dry-Run preview).
- Automatic WooCommerce-to-Marketplace stock mutation listeners with recursion loop prevention.
- Seamless order ingestion with customer/address sanitization and SKU matching.
- Two-way fulfillment dispatching postal tracking numbers back to marketplaces when orders are completed or shipped in Orderino.

---

## 3. Security & Operational Hardening
- Credential masking in REST API responses (`pat_...xxxx`).
- Automatic sensitive data redaction in structured diagnostic logs.
- Strict authorization requiring `manage_woocommerce` capability on all management routes.
- Formula-injection defense on all CSV and XLSX import/export pipelines.
- Data preservation on default uninstall with zero WooCommerce data loss.
