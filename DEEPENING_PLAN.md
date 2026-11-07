# Deepening Opportunities — RetailMind committed code

Vocabulary (codebase-design): **module**, **interface**, **implementation**, **depth**, **deep**, **shallow**, **seam**, **adapter**, **leverage**, **locality**.
Rule: *one adapter means a hypothetical seam, two adapters means a real one.* Single-adapter pass-throughs are indirection to delete.
Scope: committed code at `HEAD` (c23091a). Uncommitted working-tree WIP excluded from the scan.

Legend: **Status** — `IMPL` = implemented in the current deepening change set; `open` = not yet done.

Count: 34 opportunities.

---

## A. Shallow pass-throughs and dead code — delete, don't keep

### 1. Collapse three shallow receipt facades into `ReceiptEngine` — Strong — IMPL
- **Files:** `src/backend/app/Receipts/ReceiptDetailsService.php` (50), `GCashReceiptService.php` (23), `RefundReceiptService.php` (23); deep target `ReceiptEngine.php` (605).
- **Problem:** Each facade forwards its methods 1:1 to `ReceiptEngine`; `GCashReceiptService`/`RefundReceiptService` even rebuild an engine per call, so interface width equals implementation.
- **Solution:** Delete all three; every caller instantiates `ReceiptEngine` directly (`fetchSale`, `preserveSale`, `storeInfo`, `fetchItems`, `verificationCode`, `renderMetadata`, `preserveGCash`, `fetchGCash`, `preserveRefund`, `fetchRefundForCashier`).
- **Dependency:** in-process. **Test surface:** `receipt_engine_contract.php` already tests `ReceiptEngine`.
- **Wins:** *interface shrinks; implementation absorbs the facades · locality: receipt rules in one module · delete 96 lines of indirection.*

### 2. Delete `Core\Model` (zero subclasses) — Strong — IMPL
- **Files:** `src/backend/app/Core/Model.php` (15).
- **Problem:** Abstract base holding only a `PDO`; `git grep "extends Model"` finds no subclass.
- **Solution:** Delete. **Dependency:** in-process. **Wins:** *dead abstract removed · no phantom seam.*

### 3. Delete `Core\Controller` (zero subclasses) — Strong
- **Files:** `src/backend/app/Core/Controller.php` (22).
- **Problem:** Provides `redirect/json/view` that no class extends (`AbortController` hits are browser JS).
- **Solution:** Delete. **Dependency:** in-process. **Wins:** *dead abstract removed.*

### 4. Delete `Core\Request` (zero callers; duplicates `ErrorFallback::expectsJson`) — Strong
- **Files:** `src/backend/app/Core/Request.php` (43).
- **Problem:** Superglobal wrapper never instantiated; `expectsJson()` duplicates `Support\ErrorFallback::expectsJson()`.
- **Solution:** Delete; keep the one live copy in `ErrorFallback`. **Dependency:** in-process. **Wins:** *one owner for JSON expectation · no unused seam.*

### 5. Delete `StoreScope` singleton stub; use a `STORE_ID` literal — Strong — IMPL
- **Files:** `src/backend/app/Store/StoreScope.php` (37).
- **Problem:** `id()` returns `1`, `productScope()` returns `['', []]`, `preflight()` returns a literal — four-method interface carrying no scope logic.
- **Solution:** Delete; callers use `1`/a shared constant; drop `tests/store_scope_contract.php`.
- **Dependency:** in-process. **Wins:** *interface deleted; 8 service call sites lose indirection.*

### 6. Collapse governance subclasses `StoreActivationService` + `ModelPromotionService` — Worth exploring
- **Files:** `src/backend/app/Forecasting/StoreActivationService.php` (39), `ModelPromotionService.php` (22).
- **Problem:** Pass-through subclasses of `StoreModelGovernanceService`; `StoreActivationService` merely flips one readiness flag, `ModelPromotionService` adds only a constructor.
- **Solution:** Delete; callers use the parent with an explicit flag/argument. **Dependency:** in-process. **Wins:** *delete shallow subclass seam.*

### 7. Fold `SalesService` (2 one-query methods) into `DashboardService` — Worth exploring
- **Files:** `src/backend/app/Services/SalesService.php` (39).
- **Problem:** `getSalesSummary`/`getRecentTransactions` duplicate `DashboardService::getAdminMetrics` / `getCashierRecentTransactions`; single caller `legacy/routes/admin/dashboard.php`.
- **Solution:** Delete; repoint caller. **Dependency:** in-process. **Wins:** *one dashboard read module.*

### 8. Delete dead pass-throughs in `SaleReversalService` — Worth exploring
- **Files:** `src/backend/app/Services/SaleReversalService.php` (140).
- **Problem:** `requestReversal` always throws; `approveReversal`/`rejectReversal` forward to `LegacyReversalTransitionService::decide`; `reviewBalances` builds `CashierShiftService` internally.
- **Solution:** Route the caller to `LegacyReversalTransitionService`; drop the wrapper. **Dependency:** in-process. **Wins:** *delete 3 pass-through methods.*

## B. Authorization

### 9. Inline the `AuthorizationContext` boolean — Worth exploring — IMPL
- **Files:** `src/backend/app/Authorization/AuthorizationContext.php` (28), `includes/auth.php`, `RoleCapabilityPolicy.php:99`, 5 test files.
- **Problem:** Carries one `bool` behind `standard()/remote()/isRemote()`; consumed at a single decision point.
- **Solution:** Pass `?bool` through `RoleCapabilityPolicy::allows` and `has_capability`. **Dependency:** in-process. **Wins:** *delete a one-boolean module · callers stop wrapping.*

## C. God modules — inject collaborators, then split

### 10. `GCashTransactionService` (2994 lines, 42 public methods) — Strong
- **Files:** `src/backend/app/Services/GCashTransactionService.php`.
- **Problem:** Mixes PDO-free pure calc (`calculateFee`, `calculatePreview`, `validatePrincipal`, `feeForPrincipal`, `reference`) with persistence, and constructs `CashierShiftService` 10× and `FiscalPeriodGuardService` internally.
- **Solution:** Extract a PDO-free deep *fee/principal* module; inject the shift + fiscal collaborators via constructor (production adapter real, test adapter in-memory).
- **Dependency:** in-process. **Wins:** *pure rules testable without PDO · leverage: 8 call sites, one interface.*

### 11. `ProductService` (1504 lines, coarse `array $data` interface) — Strong
- **Files:** `src/backend/app/Services/ProductService.php`.
- **Problem:** Catalog CRUD + barcode generation + purchasing + physical reconcile in one class; constructs `NotificationService` + `FiscalPeriodGuardService` internally; `findProductBySearchTerm` duplicates `ProductCodeLookupService` semantics.
- **Solution:** Split deep modules (Catalog, Barcodes, Purchasing, Reconcile); inject collaborators. **Dependency:** in-process. **Wins:** *interface narrows; logic concentrates.*

### 12. `StockIssueService` (1376 lines, 25 public methods) — Strong
- **Files:** `src/backend/app/Services/StockIssueService.php`.
- **Problem:** Constructs `NotificationService`, `FiscalPeriodGuardService`, `BatchInventoryLedger` internally; `getOpenShift` duplicates `CashierShiftService::getOpenShift`; batch allocation duplicates FEFO logic.
- **Solution:** Inject collaborators; reuse the shift and ledger deep modules instead of copying them. **Dependency:** in-process. **Wins:** *delete duplicated FEFO/shift logic.*

### 13. Extract the static schema probe `SalesWorkflowService::getTableColumns` — Strong
- **Files:** `src/backend/app/Services/SalesWorkflowService.php:1273`; called by ≥8 modules (`ReceiptEngine`, `CashRefundService`, `HeldSaleService`, `InventoryCountService`, `ProductService`, `StockIssueService`, `SupplierPulloutService`, `LegacyReversalTransitionService`, `csv_import.php`).
- **Problem:** A shallow static utility whose schema knowledge is copied across the whole service layer; no single schema seam.
- **Solution:** Move to `App\Database\Schema` (deep module, already the schema authority) and inject. **Dependency:** in-process. **Wins:** *locality: schema knowledge in one module · 9 call sites lose the copy.*

### 14. Split `StoreModelGovernanceService` (1151 lines, 30 public methods) — Strong
- **Files:** `src/backend/app/Forecasting/StoreModelGovernanceService.php`.
- **Problem:** Settings, threshold gates, technical/operational review, activation, rollback, suspension, artifact validation, coverage, runtime checks — one god module; four settings transactions re-implemented; constructs `CandidateEvaluationService`, `ForecastingSchedulerService`, `MlJobService`, `StoreTrainingEligibilityService` internally.
- **Solution:** Split facets (Settings, Thresholds, Review, Activation/Rollback) behind small interfaces; inject collaborators. **Dependency:** in-process. **Wins:** *interface shrinks · locality per facet.*

### 15. Inject `DemandForecastService` collaborators — Strong — IMPL
- **Files:** `src/backend/app/Forecasting/DemandForecastService.php`.
- **Problem:** Constructed `CandidateEvaluationService` (6×), `ReplenishmentQueueService` (2×) and called `ForecastingSchedulerService::canAutomaticallyRetry` statically (2×).
- **Solution:** Constructor-inject evaluator + queue service; inject the retry decision as a callable/port (done). Further: split persistence/coverage/job-resolver/reader. **Dependency:** in-process. **Wins:** *substitutable deps · tests stop touching internals.*

### 16. Break the Governance ↔ Evaluation circular seam — Strong
- **Files:** `src/backend/app/Forecasting/CandidateEvaluationService.php` (708), `StoreModelGovernanceService.php` (1151).
- **Problem:** Each constructs the other (`governance()` ↔ `new CandidateEvaluationService`), and MAE/RMSE/WAPE are computed in two methods.
- **Solution:** One owns the port; the other receives it. Extract the metric computation into one deep module. **Dependency:** in-process. **Wins:** *one metric owner · seam becomes one-directional.*

### 17. Inject `ReplenishmentQueueService` collaborators; dedupe eligibility — Strong
- **Files:** `src/backend/app/Forecasting/ReplenishmentQueueService.php` (513).
- **Problem:** Builds `CandidateEvaluationService`/`DemandForecastService` inline; duplicates the "usable forecast" predicate in two methods; returns bare arrays.
- **Solution:** Constructor-inject collaborators; hoist the predicate into one deep rule; return a typed result. **Dependency:** in-process. **Wins:** *one eligibility rule · interface narrows.*

### 18. De-duplicate `ForecastingSchedulerService` handlers — Worth exploring
- **Files:** `src/backend/app/Forecasting/ForecastingSchedulerService.php` (723).
- **Problem:** `handleForecastGeneration` vs `handleCandidateObservation` repeat due-check, downtime catch-up, lease guard, retry; settings upserts repeated.
- **Solution:** One deep scheduling routine parameterised by handler; inject `MlJobService`/`DemandHistoryService`. **Dependency:** in-process. **Wins:** *one scheduling rule.*

### 19. `CashRefundService` duplicate refund-balance SQL — Worth exploring
- **Files:** `src/backend/app/Services/CashRefundService.php` (913).
- **Problem:** The refund-sum query is copied 3× (`~L300, ~L389, ~L687`); `RefundExceptionService` constructed internally.
- **Solution:** One deep "refundable balance" module; inject the exception service. **Dependency:** in-process. **Wins:** *one balance rule · fewer drift bugs.*

### 20. `HeldSaleService` depends on concrete `CashierShiftService` — Worth exploring
- **Files:** `src/backend/app/Services/HeldSaleService.php` (823); constructed as `new HeldSaleService($pdo, $this)` from `CashierShiftService:808`.
- **Problem:** Constructor demands the concrete class, not a port; bodies overlap with `CashierShiftService`.
- **Solution:** Define a shift port; production adapter = real service, test adapter = in-memory. **Dependency:** ports & adapters. **Wins:** *substitutable seam · test without a shift.*

### 21. `DashboardService` shallow aggregation facade — Worth exploring
- **Files:** `src/backend/app/Services/DashboardService.php` (328).
- **Problem:** 13 thin methods, each one raw query; re-implements inventory counts it hands to `InventoryService`; `new InventoryService($this->pdo)` internally.
- **Solution:** Collapse overlapping reads; inject the inventory reader. **Dependency:** in-process. **Wins:** *interface shrinks; one read owner.*

### 22. `InventoryService` overlapping read module, internally constructed — Worth exploring
- **Files:** `src/backend/app/Services/InventoryService.php` (374).
- **Problem:** Low-stock/expiry reads overlap `DashboardService`, which builds it internally, defeating the seam.
- **Solution:** One inventory-read module; inject where needed. **Dependency:** in-process. **Wins:** *delete duplicated SQL.*

### 23. `CashierOperationalHistoryService` 13-key untyped return — Worth exploring
- **Files:** `src/backend/app/Services/CashierOperationalHistoryService.php` (177).
- **Problem:** `resolve()` returns a 13-key mixed bag; `withLiveBalances` builds `ShiftReconciliationCalculator` internally.
- **Solution:** Return a typed result; inject the calculator. **Dependency:** in-process. **Wins:** *typed interface · substitutable calculator.*

## D. Duplicated logic needing one deep owner

### 24. Dashboard workspaces duplicate attention/exception/cache logic — Worth exploring
- **Files:** `src/backend/app/Dashboard/StoreOperationsDashboardWorkspace.php` (892), `DashboardWorkspace.php` (238).
- **Problem:** Both re-implement freshness, permission filtering, cache helpers and attention/exception fan-out already owned by the Attention module; cash-variance SQL duplicated.
- **Solution:** One deep Attention-consumption module the workspaces share. **Dependency:** in-process. **Wins:** *one rule source · cache locality.*

### 25. `DatabaseAttentionSignalSource` reaches into Forecasting — Worth exploring
- **Files:** `src/backend/app/Attention/DatabaseAttentionSignalSource.php` (309).
- **Problem:** Constructs `CandidateEvaluationService` and reads `ml_jobs`/`model_training_runs`/`demand_forecasts` directly, coupling two modules with no adapter.
- **Solution:** Consume a Forecasting port; keep table knowledge inside Forecasting. **Dependency:** ports & adapters. **Wins:** *seam holds · Forecasting free to refactor.*

### 26. Duplicate day-classification taxonomy (`DemandHistoryService` vs `StoreTrainingEligibilityService`) — Worth exploring
- **Files:** `src/backend/app/Forecasting/DemandHistoryService.php` (710), `StoreTrainingEligibilityService.php` (285).
- **Problem:** Both classify `valid_demand / genuine_zero / closed / stockout_censored`; each constructs the other (bidirectional seam).
- **Solution:** One deep classification module; one-directional dependency. **Dependency:** in-process. **Wins:** *one taxonomy · readiness rules stop drifting.*

### 27. Duplicate receipt header/footer markup across receipt Views — Worth exploring
- **Files:** `src/backend/app/Views/sale_receipt.php`, `refund_receipt.php`, `gcash_receipt.php`.
- **Problem:** Identical `receipt-brand`/`receipt-header`/footer blocks copy-pasted per template; a store-field change needs three edits.
- **Solution:** One receipt-partial module consumed by all three renderers. **Dependency:** in-process. **Wins:** *one header/footer owner.*

### 28. `OperatorAlert` vs `ErrorFallback` duplicate error rendering — Worth exploring
- **Files:** `src/backend/app/Support/OperatorAlert.php` (75), `ErrorFallback.php` (82).
- **Problem:** Two static helpers both own operator-facing errors; two different `techDetail()` implementations.
- **Solution:** One deep operator-error module; one `techDetail`. **Dependency:** in-process. **Wins:** *one rendering rule.*

### 29. `BackupCoordinator` rebuilds its PDO, bypassing `Core\Database` — Worth exploring
- **Files:** `src/backend/app/Backup/BackupCoordinator.php` (253).
- **Problem:** `coordination()` re-reads `config/database.php` and builds a divergent PDO/DSN instead of taking the connection factory.
- **Solution:** Accept the `Core\Database` factory. **Dependency:** ports & adapters. **Wins:** *one connection module · no divergent DSN.*

### 30. `DatabaseBackupService::capture` hardwires its collaborators — Worth exploring
- **Files:** `src/backend/app/Backup/DatabaseBackupService.php` (368).
- **Problem:** ~120-line capture `new`s the snapshot writer and statically calls `SqlBackupFormat`/`StorePackageFormat`; not exercisable without a real DB + filesystem.
- **Solution:** Inject the writer + format ports; in-memory adapters for tests. **Dependency:** ports & adapters. **Wins:** *capture testable through its interface.*

### 31. `StoreSetupConfirmations` static-only module — Speculative
- **Files:** `src/backend/app/Store/StoreSetupConfirmations.php` (82).
- **Problem:** Every method takes `PDO` as an argument; `all()` swallows all `Throwable` and returns `[]`, hiding failures from any double.
- **Solution:** Instance with injected PDO; return typed results. **Dependency:** in-process. **Wins:** *substitutable · failures surface.*

### 32. `WorkspaceWalkthroughService` static-only grab-bag — Speculative
- **Files:** `src/backend/app/Guidance/WorkspaceWalkthroughService.php` (485, 10 static methods).
- **Problem:** Fuses a hard-coded content catalog with DB persistence; every method takes `PDO`; no adapter/Clock seam.
- **Solution:** Split content (pure, deep) from persistence (injected). **Dependency:** in-process. **Wins:** *pure catalog testable without DB.*

## E. Frontend transport / presentation duplication

### 33. `report_generation.php` is the whole report module — Strong
- **Files:** `src/frontend/components/report/report_generation.php` (~1349, 35 SQL statements).
- **Problem:** Filters, a `switch` catalog, CSV serialization and globally-scoped helpers live in the page; the CSV pattern repeats in 4 more components.
- **Solution:** Extract a deep Report module (query + CSV + filters); the view renders its result. **Dependency:** in-process. **Wins:** *interface: one report call · CSV owner centralized.*

### 34. Component auth/guard preamble duplicated ~86× — Strong
- **Files:** 86 components `require_once includes/auth.php`; 37 also call `require_role([...])` (e.g. `cashier/gcash.php:2-5`, `administrator/gcash_overview.php:2-9`).
- **Problem:** The include + role guard + `$pdo`/session preamble is a copy-paste convention, not a module.
- **Solution:** One `ComponentBootstrap` module the views call. **Dependency:** in-process. **Wins:** *one guard seam · 37 call sites shrink.*

### 35. Component shell (head + sidebar) duplicated ~57× — Strong
- **Files:** 57 components emit `<!DOCTYPE html>`/`retailmind_theme_head()`; 54 include `sidebar.php` (e.g. `report/receiving_history.php:34-60`).
- **Problem:** Layout header + asset links + sidebar reassembled per page.
- **Solution:** One shell module taking a title + content callback. **Dependency:** in-process. **Wins:** *one layout owner.*

### 36. `UiFormat`: escape + money formatting duplicated — Strong
- **Files:** `assets/js/ui.js`, `cashier/pos.php`, `inventory_management/products.php`, `cashier/findProduct.php`, `inventory_management/inventory_counts.php`, `assets/js/gcash.js`, `gcash-admin-overview.js`.
- **Problem:** `escapeHtml` re-implemented in 5 places, money formatting in 7.
- **Solution:** One `UiFormat` helper exposed globally and reused. **Dependency:** local-substitutable. **Wins:** *one formatting owner.*

### 37. JSON request/response envelope duplicated — Strong
- **Files:** 8 JS modules do ad-hoc `fetch`; 2 PHP endpoints duplicate the CSRF + `php://input` cap + `json_decode` block (`cashier/gcash_api.php:45-51`, `administrator/gcash_overview_api.php:34-44`).
- **Problem:** Transport concerns leak into every caller.
- **Solution:** One request adapter (JS) and one JSON-endpoint module (PHP). **Dependency:** ports & adapters. **Wins:** *seam holds · callers shrink.*

### 38. `PlatformSettingsReader`: inline settings SQL ignores the existing helper — Worth exploring
- **Files:** 6 components issue inline `platform_settings` reads (`report/forecast_replay.php:10`, `forecast_analytics.php:49`, `predictions.php:100`, `ml_settings.php:44`, …) while `get_key_value_settings()` exists.
- **Problem:** Governance reads duplicated, no locality.
- **Solution:** Reuse the helper (or a deep reader). **Dependency:** in-process. **Wins:** *one settings read.*

### 39. `StaffDirectory` role lookup duplicated ×5 — Worth exploring
- **Files:** `administrator/stock_issues.php:79`, `cashier/refund_corrections.php:58`, `cashier/shifts.php:25`, `user_manager/user_manager.php:204`, `administrator/shift_report.php:117`.
- **Problem:** "active users in a role" hand-written as a JOIN per view.
- **Solution:** One staff-lookup module. **Dependency:** in-process. **Wins:** *one query owner.*

### 40. GCash money/variance rendering duplicated — Worth exploring
- **Files:** `assets/js/gcash.js` (1289), `assets/js/gcash-admin-overview.js` (378).
- **Problem:** Both re-implement money formatting, transaction rows, tab switching, variance rendering.
- **Solution:** Shared deep GCash-presentation module. **Dependency:** local-substitutable. **Wins:** *one presentation rule.*

### 41. Chart bootstrap duplicated across two stacks — Worth exploring
- **Files:** `administrator/dashboard.php`, `cashier/dashboard.php`, `inventory_management/inventory_overview.php` (Chart.js) + `report/forecast_analytics.php` (raw canvas).
- **Problem:** Four views, two incompatible techniques.
- **Solution:** One charting module. **Dependency:** local-substitutable. **Wins:** *one chart seam.*

### 42. Legacy route adapters duplicate component implementations — Worth exploring
- **Files:** 19 files under `src/backend/legacy/routes/`; 4 thin redirect shims, 5 large duplicate pages (`invoice/receipt.php` ~801, `manager/products.php` ~549).
- **Problem:** Routes either shim or bypass the component interface with a second implementation.
- **Solution:** Convert duplicates to redirect shims. **Dependency:** in-process. **Wins:** *one page implementation.*

## F. Config, schema and test surfaces

### 43. Forecasting schedule config duplicated JSON/PowerShell/PHP/DB — Worth exploring
- **Files:** `config/forecasting_runner.json` (committed), `scripts/install_forecasting_scheduler.ps1` (~190), `ForecastingSchedulerService.php:694-716`, `config/machine_learning.php`.
- **Problem:** The same effective schedule is written/asserted in four places with no single config module.
- **Solution:** One config module that resolves and persists the schedule. **Dependency:** local-substitutable. **Wins:** *one schedule owner.*

### 44. `operational_schema.php` runtime DDL duplicates schema.sql + migrations — Speculative
- **Files:** `src/backend/includes/operational_schema.php` (~367); `database/sql/schema.sql`; `database/migrations/*`.
- **Problem:** 13 `CREATE TABLE IF NOT EXISTS` blocks re-declare tables already owned by `App\Database\Schema`/`MigrationRunner`.
- **Solution:** Route through the schema/migration module. **Dependency:** in-process. **Wins:** *one schema authority.*

### 45. Tests bypass the `Core\Database` connection seam — Strong
- **Files:** 126 test files open `new PDO('sqlite::memory:'`; 39 re-require `config/database.php`.
- **Problem:** The intended connection module is bypassed; bootstrapping is copy-pasted and drifts.
- **Solution:** Route tests through one connection factory (prod + in-memory adapters). **Dependency:** ports & adapters. **Wins:** *one connection module · tests stop diverging.*

### 46. Schema-parity contracts assert regex over migration text — Strong
- **Files:** `tests/cash_refund_schema_parity_contract.php:32-44`, `sale_shift_schema_parity_contract.php`, `held_sale_schema_parity_contract.php`, `register_schema_parity_contract.php`.
- **Problem:** Tests match tokens in source text rather than exercising a schema loader, so a refactor breaks tests without a real regression.
- **Solution:** Exercise `App\Database\Schema` through its interface. **Dependency:** in-process. **Wins:** *tests describe behaviour, not text.*

### 47. CLI fixtures duplicate their preamble; `theme_fixture` is a 30-subcommand grab-bag — Worth exploring
- **Files:** `tests/support/scan_workflow_fixture.php` (~493) vs `demand_forecast_workflow_fixture.php` (~352) share an identical preamble; `theme_fixture.php` branches on ~30 `$argv[1]` commands.
- **Problem:** Duplicated setup, no shared fixture module.
- **Solution:** One fixture-bootstrap module; split `theme_fixture` by concern. **Dependency:** local-substitutable. **Wins:** *one fixture seam.*

### 48. Tests read `forecasting_runner.json` directly instead of the governance module — Speculative
- **Files:** `tests/administrator_model_review_component_test.php:228`, `forecasting_runner_installation_test.php:38`, `store_forecasting_operational_workflow_test.php:390`.
- **Problem:** Tests bypass `StoreModelGovernanceService` (the file's owner) and read it as text.
- **Solution:** Read through the governance interface. **Dependency:** in-process. **Wins:** *interface is the test surface.*

---

## Implementation order (recommended)
1. Opps 1–5, 9 (delete shallow modules) — mechanical, proven seams. *(Implemented in the current change set.)*
2. Opps 13, 26, 33–37 (extract the deep owners that stop duplication) — high leverage.
3. Opps 10–12, 14–17 (inject + split the god modules) — largest, do last with tests at the new interface.

Each change: read all callers + tests, make edits, run the affected tests, report the diff.
