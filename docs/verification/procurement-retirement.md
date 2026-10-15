# Procurement storage retirement — issue #188

Verified locally on October 15, 2026, with MySQL 8.4.3, PHP 8.3.33, and Playwright Chromium. Issue #181 supersedes #176's procurement-preservation scope; historical ADRs remain unchanged. See [ADR 0011](../adr/0011-retire-development-procurement.md) and the [operator guide](../guides/direct-stock-receiving.md).

## Installation and upgrade

- `procurement_schema_integration.php`: canonical installation plus PHP migrations, representative development links with foreign keys, full retained-column snapshots, schema equivalence, repeated setup/migrations, SQL cleanup, and repeated SQL import passed. It removes only uniquely named disposable databases.
- `consolidated_sql_integration.php`: PHP/SQL schema and data parity, legacy backfills, retained accounts/passwords, inventory, sales, backup evidence, and repeat-import checks passed.
- A standard Database Backup was captured before upgrading the local development database. Its upgraded disposable clone passed the connected receiving workflow. The development database then applied the pending retirement migrations; retained-column snapshots were unchanged, and rerunning left no pending migrations. The snapshot excludes only retired columns and the migration ledger; the preexisting attention migration separately removes retired notices/settings.
- The unused `notify_replenishment` preference, five procurement tables, receiving document links/PO number, and forecast-decision request link are removed. Optional shopping-store text, receipt references, purchase-cost history, planning settings, inventory/batches, customer receipts, sales/refunds, counts, Stock Issues, and Protected Audit Records remain.

## Connected behavior

- `stock_receiving_scan_browser_test.js` passed on both a fresh installation and the upgraded development clone: 217 and 219 assertions respectively. Coverage includes the six destinations, guided receiving, optional costs, grouped history, pack conversion, atomic rollback, gates/authorization, concurrent additions, authorized cashier checkout, stale/oversell rejection, and Stock Issue deductions. Saved forecasts/jobs remain unchanged.
- `forecast_decision_browser_test.js` passed: stock-rule/forecast guidance, governance, responsive/search/print behavior, and retired purchasing GET/POST routes returning 404 without changing persisted state.
- Receiving History, receiving UI, Inventory Counts, and notifications browser checks passed. Checkout attempt/quote, refund/concurrency, count/Stock Issue, product seed, inventory-load, planning MySQL, and observation/activation contracts passed.
- Standards and spec reviews: zero outstanding findings. Changed PHP/JavaScript syntax and Git whitespace checks passed.

## Full-suite limit

`bash src/backend/tests/run_all.sh` passed the initial browser/layout checks, PHP lint, CSS/deployment checks, 107 smoke checks, schema/upgrade integrations, and role/backup-route contracts, then stopped in unchanged `backup_unavailable_navigation_test.php`: its eval fixture calls undefined `theme_url()`. The suite therefore did not finish. Focused inventory/cashier checks above were run separately; no Backup/Restore implementation was changed.
