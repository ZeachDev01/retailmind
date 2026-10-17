# Store-wide caching and offline-tolerant operations for low-bandwidth networks

RetailMind operates across slow and intermittent cellular data connections where network roundtrips freeze cashier scanning and inflate operational latency. The system establishes a multi-tiered caching architecture prioritizing zero-latency point-of-sale operations, minimal cellular bandwidth consumption, and absolute inventory consistency.

Instead of full offline-first checkout (which causes inventory drift, stock overselling, and receipt number collisions), RetailMind decouples product discovery and scanning from sale finalization. Item lookups resolve locally on device, while sale commitments and stock deductions remain strictly online and idempotent.

## Consequences

- **P1: Cashier POS Catalog Preload & Idempotent Checkout**:
  - The active sellable catalog preloads into browser `IndexedDB` when a Cashier Shift opens. Barcode lookups resolve locally in <2ms with zero cellular data consumption.
  - Final checkout requires an active internet connection. Requests carry a client-generated UUID idempotency key; connection drops during payment can be retried without duplicate sales or duplicate Sale Receipts.
  - When checkout detects insufficient live stock, the cart remains intact and displays the live remaining stock in a modal for the Cashier to manually adjust or remove items.
  - Catalog freshness checks run every 5 minutes in the background using HTTP `ETag` / `If-None-Match` conditional headers (returning `304 Not Modified` at ~100 bytes), supplemented by a manual "Refresh" button on the POS screen.

- **P2: Unified Root Service Worker & App Shell Caching**:
  - A root Service Worker caches application stylesheets, JavaScript bundles, icons, and page layouts using a Stale-While-Revalidate strategy.
  - Asset updates install in the background and surface a non-blocking notification banner ("Update available — Tap to reload") rather than interrupting an active Cashier Shift.

- **P3: Administrator Dashboard Transient Metrics Caching**:
  - Heavy 30-day and 90-day aggregate metrics in `StoreOperationsDashboardWorkspace` cache on the server for 300 seconds (`REFRESH_SECONDS = 300`), reducing dashboard queries from 15+ database calls to a single RAM/file read.
  - State-mutating operational actions (Cashier Shift close, Cash Refund completion, and Stock Issue decisions) immediately invalidate this cache.

- **P4: Immutable Receipt History Browser Caching**:
  - Completed Sale Receipts and thermal print views in Receipt History emit `Cache-Control: private, max-age=86400`. Completed receipts are immutable audit records and require zero data for customer reprints.

- **P5: Replenishment Recommendations Snapshot Caching**:
  - AI demand forecasts and replenishment suggestions cache in device session storage for the duration of the Inventory Manager's active review, refreshing only when Stock Receiving is recorded or when the user triggers a manual recalculation.

