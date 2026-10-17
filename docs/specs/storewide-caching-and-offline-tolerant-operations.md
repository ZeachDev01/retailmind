# Store-wide Caching and Offline-Tolerant Operations Spec

## Problem Statement

RetailMind operations frequently run on slow, high-latency, or metered mobile cellular data (3G/4G). Under these network conditions:
1. Cashiers experience severe 500ms to 2000ms latency pauses on every single barcode scanned because each scan sends an HTTP request across the mobile network.
2. Intermittent connection drops during checkout can stall payment finalization or risk duplicate sales upon cashier resubmission.
3. Page navigation re-downloads static stylesheets, JavaScript bundles, and icon fonts, consuming unnecessary mobile data and slowing screen responsiveness.
4. Administrative dashboards execute 15+ heavy aggregate database queries across 90 days of sales on every page load, causing high server response times on modest hosting.
5. In-store inventory audits by Inventory Managers walking the aisles suffer latency when recalculating replenishment suggestions over spotty mobile signals.
6. Reprints of immutable customer receipts in Receipt History redundantly query and render data from the database.

## Solution

A cohesive, prioritized caching architecture that keeps daily store operations fast, reliable, and bandwidth-efficient:
1. **P1 — POS Offline Catalog Preload & Idempotent Checkout**: Sellable items and prices preload into browser client-side storage at Cashier Shift opening. Barcode scanning resolves locally in under 2ms with zero data consumption. Checkout requires an active online connection protected by client-generated idempotency keys to prevent duplicate sales. When checkout detects low stock, the cart is kept intact and displays remaining stock in a decision modal. Catalog freshness is maintained via 5-minute lightweight conditional HTTP checks (`304 Not Modified`) and a manual POS refresh button.
2. **P2 — Root Service Worker App Shell**: A unified Service Worker caches static stylesheets, scripts, fonts, and core layouts with silent background updates and non-blocking update notifications.
3. **P3 — Administrator Dashboard Metrics Cache**: 300-second transient server caching for heavy aggregate metrics, automatically invalidated upon state-changing events (Cashier Shift close, Cash Refund, Stock Issue decision).
4. **P4 — Immutable Receipt History Caching**: 24-hour browser HTTP caching for finalized customer Sale Receipts.
5. **P5 — Replenishment Recommendations Snapshot Caching**: Session caching for stock recommendations during aisle audits, refreshing upon new Stock Receiving or manual trigger.

## User Stories

1. As a Cashier, I want the active sellable product catalog downloaded to my register when I open my Cashier Shift, so that barcode scanning does not require an active network call for every item.
2. As a Cashier, I want barcode scanning and manual SKU typing to resolve in less than 5 milliseconds, so that I can ring up items at full speed without waiting for cellular data.
3. As a Cashier, I want to scan items and build customer carts while the mobile network is temporarily offline or degraded, so that checkout lines keep moving smoothly.
4. As a Cashier, I want local stock counts to decrement in device memory as items are added to the cart, so that I am alerted immediately if scanning exceeds known on-hand stock.
5. As a Cashier, I want my active cart preserved intact if checkout fails due to a live stock shortage, so that I do not have to rescan the customer's entire basket.
6. As a Cashier, I want a clear modal showing remaining stock when a stock conflict occurs at checkout, so that I can consult the customer and adjust or remove the affected line.
7. As a Cashier, I want my payment submission to carry a unique idempotency key, so that a cellular signal drop during checkout can be safely retried without double-charging or duplicating a Sale Receipt.
8. As a Cashier, I want the POS screen to include a manual "Refresh Catalog" button, so that I can immediately pull updated prices or newly received stock without restarting my shift.
9. As a Cashier, I want background catalog freshness checks to use negligible cellular bandwidth (~100 bytes) when nothing has changed, so that our data plan is preserved.
10. As a Cashier, I want page layouts, buttons, and styles to open instantly from local cache, so that switching screens does not stall on slow mobile networks.
11. As a Cashier, I want app updates to install silently in the background and notify me with an unobtrusive banner, so that my active selling session is never abruptly interrupted by a forced reload.
12. As a Store Administrator, I want the store operations dashboard to render in under 50 milliseconds, so that I can monitor daily operations without waiting for 15 heavy database aggregate queries.
13. As a Store Administrator, I want the dashboard cache to automatically bust when a Cashier closes a shift, so that cash variance and sales figures reflect current store reality immediately.
14. As a Store Administrator, I want the dashboard cache to automatically bust when an Inventory Manager decides a Stock Issue, so that inventory risk metrics update without waiting for a cache timer.
15. As an Inventory Manager, I want Replenishment Recommendations cached in session storage on my mobile phone, so that I can audit shelves in poor-signal store aisles without screen freezes.
16. As an Inventory Manager, I want cached Replenishment Recommendations to refresh automatically when Stock Receiving is recorded, so that buying suggestions reflect recently delivered goods.
17. As an Inventory Manager or Cashier, I want completed Sale Receipts in Receipt History to open instantly using browser HTTP caching, so that reprinting customer receipts consumes zero cellular data.
18. As a Store Owner, I want final sales and inventory deductions to remain strictly validated on the server at checkout, so that physical stock counts and revenue records never suffer offline drift.

## Implementation Decisions

### 1. Client-Side Catalog Storage & Local Scan Resolution
- The POS workspace initializes a client-side database (`IndexedDB`) during Cashier Shift bootstrapping.
- Preloaded attributes are strictly bounded to active, sellable items: Product ID, SKU, Barcode, Case Barcode, Product Name, Unit Price, and Quantity on Hand.
- Barcode scanning, SKU entry, and product search in POS resolve directly against the local `IndexedDB` index.
- Product scan lookups run with zero network roundtrips during cart construction.

### 2. Catalog Freshness Protocol (ETag / 304 Delta Sync)
- A background sync loop fires every 5 minutes while the Cashier Shift is open and unlocked.
- The sync request transmits an HTTP `If-None-Match` header holding a catalog state hash (derived from max modification timestamps of products and inventory).
- If the catalog is unchanged, the server returns HTTP `304 Not Modified` with empty body (<100 bytes).
- If changed, the server returns only modified/added product records, updating the local `IndexedDB` store.
- A manual "Refresh Catalog" action on the POS header triggers an immediate conditional check.

### 3. Idempotent Online Checkout Contract
- Checkout requires an active network connection to commit financial and stock changes.
- The checkout payload includes a client-generated UUID idempotency key (`checkout_attempt_id`), cart line items, payment method, and tendered details.
- The server processes the checkout within a database transaction holding row-level locks on inventory records.
- The server stores the idempotency key and sale result. Repeated submissions with the same key return the previously committed sale and receipt without duplicate deductions.
- If live stock is less than requested, the server refuses checkout with HTTP `409 Conflict`, returning the affected product IDs and current available stock.
- The POS client retains the cart and presents a modal displaying the live remaining stock, giving the Cashier explicit controls to adjust line quantity or remove the item.

### 4. Root Service Worker & App Shell Caching
- A unified Service Worker is registered at the application root, covering Cashier, Inventory Management, and Administrative workspaces.
- Versioned static assets (CSS, JS, WebFonts, SVGs) are cached using a Cache-First strategy.
- Application HTML shells are served via Stale-While-Revalidate.
- When new service worker assets are activated, a floating banner announces "Update available — Tap to reload" without forcing a reload during active transactions.

### 5. Administrator Dashboard Server Transient Cache
- The dashboard workspace service introduces a 300-second transient cache layer wrapping the aggregate metrics queries (sales comparisons, exception counts, cash variances).
- The cache key is namespaced by role and selected day range (7, 30, 90 days).
- Event hooks on Cashier Shift closing, Cash Refund recording, and Stock Issue decisioning immediately purge the cached dashboard key.

### 6. Receipt History HTTP Caching
- Completed Sale Receipt view endpoints emit `Cache-Control: private, max-age=86400, immutable`.
- Thermal reprint layouts are served from the browser cache, avoiding repeat database queries for historical receipts.

### 7. Replenishment Recommendations Snapshot Caching
- Recommendations generated by the forecasting service are held in browser `sessionStorage` during the Inventory Manager's active session.
- Submitting a Stock Receiving record purges the local session cache, prompting recalculation on the next view.

## Testing Decisions

### What Makes a Good Test
- Tests must verify external observable behavior (offline scanning speed, idempotent retry, cart preservation on stock conflict, instant dashboard render) rather than internal cache storage structures.
- Tests must exercise network degradation (offline mode, slow network, simulated connection drops) to validate operational resilience.

### Testing Seams (Highest Seams Preferred)

1. **POS Offline Catalog Scan Seam** (Highest Seam — Playwright Browser Test):
   - Opens POS with an authenticated Cashier Shift.
   - Emulates complete network disconnection (`page.setOffline(true)`).
   - Scans a barcode or types a SKU.
   - Asserts product is added to the cart with correct price and local stock in <50ms without failing or showing network error dialogs.

2. **POS Idempotent Checkout & Stock Conflict Seam** (Playwright Browser Test):
   - Builds a cart and initiates checkout.
   - Simulates network drop after submission; retries submission with identical attempt token.
   - Asserts exactly one sale record is committed and inventory is decremented once.
   - Simulates a concurrent stock depletion before checkout; asserts HTTP 409 response, verifies cart is preserved intact, and verifies modal displays live remaining stock.

3. **Administrator Dashboard Caching & Invalidation Seam** (Backend Integration Test):
   - Invokes `StoreOperationsDashboardWorkspace::load()`. Asserts database query count and latency.
   - Invokes `load()` immediately a second time. Asserts zero database aggregate queries and sub-10ms response time.
   - Simulates a Cashier Shift closure. Invokes `load()` a third time and asserts the cache was invalidated and re-queried fresh data.

4. **HTTP Caching Headers Seam** (HTTP Verification Test):
   - Requests a finalized Sale Receipt view. Asserts `Cache-Control` header contains `private, max-age=86400`.
   - Requests catalog delta with current ETag. Asserts HTTP `304 Not Modified` status code.

### Prior Art
- Browser tests: `src/backend/tests/checkout_attempt_browser_test.js`, `src/backend/tests/pos_keyboard_scan_browser_test.js`, `src/backend/tests/cashier_checkout_receipt_browser_test.js`.
- Workspace tests: `src/backend/tests/administrator_dashboard_workspace_test.php`, `src/backend/tests/session_policy_test.php`.

## Out of Scope
- Blind offline-first sales finalization without network connection (prohibited by ADR 0012 to prevent inventory drift and receipt number collisions).
- Direct peer-to-peer register synchronization over local area networks without the central RetailMind server.
- Offline caching for administrative mutation forms (User Manager account edits, Fiscal Period state updates).

## Further Notes
- Fully conforms to [ADR 0012: Store-wide caching and offline-tolerant operations](file:///c:/laragon/www/retailmind/docs/adr/0012-storewide-caching-and-offline-tolerant-operations.md).
- Adheres to single-store operational boundaries and canonical language defined in [GLOSSARY.md](file:///c:/laragon/www/retailmind/GLOSSARY.md).

