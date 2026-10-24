# Login, POS, and inventory load performance

Status: Implementation authorized after final scope confirmation on 2026-10-25; verification results below.

## Confirmed scope

- Compare the local installation and the [Hosted Demo](https://antiquewhite-cattle-865506.hostingersite.com), which the user identified as the remote target. The deployment remains a Hosted Demo in domain terminology.
- Priority: login, Cashier POS/product catalog, Inventory Manager Stock Overview, then verification of the caching paths supporting these pages.
- Measure first loads and repeat visits separately.
- Correctness takes precedence over cache lifetime: after a sale, Stock Receiving, or price edit, the next relevant view must reflect the committed change. Logout and account switching must not reuse another user's restricted data.

## Existing policy and verification gaps

[ADR-0012](../adr/0012-storewide-caching-and-offline-tolerant-operations.md) and the [existing caching spec](storewide-caching-and-offline-tolerant-operations.md) describe the caching architecture.

- The POS catalog uses IndexedDB, a five-minute background refresh interval, and conditional HTTP requests. The confirmed freshness requirement cannot be demonstrated by the timer alone.
- The service worker caches static assets but bypasses navigation/session HTML. This differs from the cached-layout language in ADR-0012; resolve the documentation discrepancy against the verified safe behavior.
- Existing query-count and extracted-script tests are useful regression checks but do not establish real authenticated page load performance.

The timestamp-filter defect was reproduced by `php src/backend/tests/pos_catalog_delta_sync_test.php`: an existing product price edit was omitted when neither creation nor inventory timestamps changed. A regression also covers equal-total price swaps, which the old aggregate ETag could treat as unchanged.

## Proposed measurement and verification

- Record document response time, time until each page's primary controls work, request count, transferred bytes, and relevant query counts where available.
- Compare first load with a repeat visit under the same dataset, browser, and network conditions. Keep local and Hosted Demo results separate.
- Verify cache reuse and unchanged-catalog conditional responses; verify changed stock/prices, stale saved browser data, and logout/account boundaries.
- Exercise sale, receiving, and price-change scenarios using isolated local test data. Keep checkout's server validation and duplicate-submission protection intact.
- Report observed before/after values and any access or measurement limits. Do not infer cache correctness from speed alone.

## Confirmed measurement conditions

- Desktop Chromium and mobile viewport 390 x 844 with 150 ms latency, 200,000 bytes/second download, 93,750 bytes/second upload, and fourfold CPU slowdown.
- Compare first loads and repeat visits. Establish a baseline before promising a numeric budget; no numeric service-level guarantee was agreed.
- Next relevant view means page opening or returning to an already-open tab. Offline POS identifies stale data and retains online checkout validation.
- Data-changing tests use disposable local databases. Hosted Demo checks remain public and read-only; no deployment was requested.

## Changes

- Login initializes its controls without waiting for the external alert script, versions `ui.js`, and delays the obscured landing hero image until the login modal closes.
- POS shares one request-local catalog read across the initial grid, browser preload, and ETag. The content hash covers individual product and pack prices, stock, and product metadata.
- Conditional sync keeps empty-body 304 responses for unchanged catalogs. Changed catalogs return complete snapshots, including removals and an empty active catalog. This trades changed-response size for correctness without a new revision-tracking subsystem.
- Browser storage and memory update after the IndexedDB transaction commits. Failed local writes do not advance the ETag. New page preloads replace saved catalogs even when empty.
- POS refreshes on tab return/reconnection, coalesces overlapping checks, retains a persistent offline/failure notice, and revalidates server access when a sync redirects or is denied.
- Shared navigation uses native lazy loading for its decorative icons, avoiding downloads for hidden menus.
- Stock Overview defers its chart library and reloads current data on tab return. POS and Stock Overview skip speculative HTML prefetches; static asset caches remain active.
- Corrected stale regression fixtures: inventory warning field and fixed fixture dates; current piece-cart keys and the shared toast stub in scanner tests.

## Verification

Commands run from the repository root:

```powershell
php src/backend/tests/pos_catalog_delta_sync_test.php
php src/backend/tests/inventory_load_contract.php
php src/backend/tests/administrator_dashboard_cache_test.php
node src/backend/tests/pos_offline_scan_browser_test.js
node src/backend/tests/pos_keyboard_scan_browser_test.js
node src/backend/tests/checkout_attempt_browser_test.js
node src/backend/tests/service_worker_browser_test.js
node src/backend/tests/page_navigation_browser_test.js
node src/backend/tests/inventory_overview_clarity_browser_test.js
node src/backend/tests/inventory_overview_alert_browser_test.js
node src/backend/tests/inventory_forecast_chart_test.js
$env:RUN_DB_TESTS='1'
$env:SESSION_SECURE_COOKIE='false'
node src/backend/tests/sidebar_theme_browser_test.js
$env:LOGIN_TEST_URL='http://retailmind.test/src/frontend/'
node src/backend/tests/login_csrf_recovery_browser_test.js
node src/backend/tests/login_redirect_test.js
```

These checks pass. The lookup timing assertion failed once at 3.6 ms during concurrent work and passed on isolated rerun; single-call millisecond thresholds are sensitive to host load. The real browser harness asserts working controls, no browser/server errors, and session HTML cache policy without imposing unstable timing thresholds.

Reproduce local measurements with the existing guarded disposable fixture:

```powershell
$env:PERFORMANCE_PUBLIC_URL='https://antiquewhite-cattle-865506.hostingersite.com/?login=1'
$env:PERFORMANCE_OUTPUT=Join-Path $env:TEMP 'retailmind-page-load.json'
node src/backend/tests/page_load_performance_browser_test.js
```

The harness cleans its temporary database, sessions, recovery files, browser, and PHP process. Its public target receives no authentication or operational writes.

## Measured results - 2026-10-25

Local disposable fixture, one sample per cell, with mobile conditions above. Original page templates were read from `HEAD` into a temporary router; the root working tree was never reverted. Shared services/assets and the disposable fixture remained current in both runs. The final run also includes native lazy loading of hidden shared-navigation icons.

| Page | Original first load | Updated first load | Updated repeat load | Updated first-load resource transfer |
| --- | ---: | ---: | ---: | ---: |
| Login | 3.50 s | 2.45 s | 0.14 s | 339,785 B |
| Cashier POS | 6.14 s | 5.38 s | 0.62 s | 783,323 B |
| Stock Overview | 5.29 s | 5.06 s | 0.87 s | 756,633 B |

Login first-load transfer fell from 575,695 B to 339,785 B by avoiding the 235,910 B obscured hero request. The other pages keep their existing CSS/fonts/scripts; hidden navigation icons now load when needed instead of blocking the initial load. POS also eliminates the separate initial catalog aggregate/grid/preload reads in favor of one shared catalog read.

Repeat mobile resource transfer was 0 B for login, 1,530 B for POS, and 998 B for Stock Overview. Service-worker responses and the controlled service-worker regression confirm static reuse; session HTML continues to come from the server. This does not imply that all application caches or all production paths have been verified.

The read-only Hosted Demo login sample before deployment was 2.63 s first load / 0.84 s repeat under the same mobile conditions. No changes were deployed, so that measurement is a remote baseline, not an optimization result.

Timing varied across sequential runs under host load; the numbers are observations rather than statistical performance guarantees. The two throttled operational pages still take about five seconds cold. Their static transfer dominates the remaining cost. The local Apache stylesheet probe returned 153,912 B without `Content-Encoding` despite existing conditional compression rules; the disposable PHP server also does not apply `.htaccess`. Enabling a server compression module or changing hosting configuration was not part of this code change.

## Limits

The timing fixture contains one product; it is not a large-catalog capacity test. Local existing inventory reads over 63 products/57 batches totaled about 10 ms in the initial service probe, so no database index or new backend cache was added. Authenticated Hosted Demo POS/inventory timing and live deployment validation require a remote test session and remain unverified.

No new domain term or irreversible architecture choice was introduced; no glossary entry or new ADR was needed. ADR-0012 and the existing caching spec were clarified to match the verified session-page policy and chosen freshness behavior.
