# Check stock and record bought goods

Use the **Inventory Manager** workspace for the daily path:

1. Open **Inventory Overview** to check stock levels. Select **Review recommendations**.
2. Review the suggested buying quantities and their basis. **Stock rules** remain usable without supplier configuration or forecasts. **Forecast unavailable** means demand is unknown, not zero. Available Demand Forecasts retain their readiness and governance labels.
3. The owner buys goods outside RetailMind. Suggestions are non-binding: different products, quantities, or shopping stores are allowed.
4. Select **Record bought stock**. Follow **Select products → Enter quantities → Review**. Choose active products by name/SKU search or unit/case barcode; add each product once. Scanning selects a product and quantity mode but leaves bought quantities blank. Enter actual quantities as base units or packs/cases using the product's saved pack size. Back, edit, and remove actions preserve the remaining draft. Only the final reviewed save changes stock.
5. Supplier/store, receipt reference, and purchase cost are optional. Cost is per base unit. Leaving cost blank preserves the existing product cost and records an unknown receipt cost; entering a shopping store does not change the product's preferred supplier. Record batch/expiry details when applicable. Damaged bought units are excluded from usable stock.
6. Save once. The summary and receiving history identify products, accepted quantities, recording staff, and time. Select **Review updated recommendations** to load guidance from committed stock and existing forecast outputs. For example, 8 units plus 2 cases of 24 becomes 56 units.

A failed save retains the entry and leaves every line's inventory, batches, costs, receiving history, and guidance unchanged. Correct the indicated line and retry. Viewing guidance never places an order or changes stock. Receiving starts neither Forecast Generation nor Model Retraining.

Stock Receiving is direct-only: it neither selects nor updates requests or purchase orders. Existing linked history remains readable; physical procurement schema retirement is separate work under #181. Counts still correct existing stock; Cashier Stock Issues retain their reporting/review workflow. Administrators inspect stock, recommendations, receiving history, and Stock Issue Oversight read-only; inventory execution requires the active Inventory Manager workspace. Forecast governance remains separate.

## Verification

Use a local test database with the current schema, operational roles, and Store initialized. The existing workflow creates token-scoped disposable products/staff and removes them in `finally`; it temporarily seeds a successful forecast job for its products and removes that job afterward. Do not run it against a Store in use or alongside a forecasting worker. Observation Mode and recommendation suspension must be disabled for the forecast-backed assertions.

Run from the repository root with PHP/MySQL and Playwright Chromium available:

```powershell
$env:RUN_DB_TESTS = '1'
$env:BACKUP_STORAGE_PATH = Join-Path $env:TEMP 'retailmind-test-recovery'
node src/backend/tests/stock_receiving_scan_browser_test.js
node src/backend/tests/inventory_counts_scan_browser_test.js
php src/backend/tests/stock_issue_contract.php
php src/backend/tests/stock_issue_oversight_contract.php
php src/backend/tests/database_integration.php
$env:RUN_CHECKOUT_DB_TESTS = '1'
php src/backend/tests/checkout_attempt_integration.php
```

The receiving browser-to-HTTP test covers the guided steps, retained drafts, keyboard use, desktop/mobile overflow, unplanned purchases, mixed units/cases (8 + 48 = 56), stock-rule and forecast guidance before/after saving, atomic validation/database failures, batches, optional costs, fully damaged and expired purchases, staff attribution, readable linked history, workspace restrictions, duplicate saves, and concurrent additions in reversed line order. It sells received goods through Cashier checkout, rejects stale/overselling attempts, and overlaps checkout with receiving. Database snapshots verify receiving and guidance do not change saved forecasts or enqueue jobs. Count, Stock Issue, oversight, and sales regressions cover the connected stock paths. The full repository suite is `bash src/backend/tests/run_all.sh`.
