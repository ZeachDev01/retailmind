# Codebase Architecture Deepening

## Problem Statement

As RetailMind has expanded across cashier shifts, customer sales, gcash services, stock inventory, and automated demand forecasting, several core modules have become shallow or fragmented. Developers and maintainers face friction because understanding and changing key business capabilities requires bouncing across multiple micro-classes, pass-through request wrappers, and procedural global scripts. Furthermore, critical business rules—such as cash drawer expected balance formulas, thermal paper width resolution, platform setting mutations, and first-expired-first-out batch deductions—are duplicated across separate entry points, leading to risk of calculation drift, leaked internal seams, and high testing overhead.

## Solution

Consolidate shallow modules, pass-through adapters, and duplicated queries into deep, highly cohesive modules with minimal external interfaces and encapsulated implementations across five key operational areas:

1. A unified Receipt Generation module that absorbs paper width resolution, snapshot retrieval, caching headers, and presentation formatting behind a single seam.
2. A single Store Model Governance module that unifies forecasting model readiness verification, dual technical/operational reviews, activation, rollback, and recommendation suspension, centralizing platform settings persistence.
3. An authoritative Shift Reconciliation Calculator that consolidates the four core financial aggregation queries and drawer balance formulas across single-shift closing and multi-shift reporting, while absorbing the cashier operational history request pass-through.
4. An encapsulated Attention Center that hides raw signal SQL queries and rule threshold dictionaries as private implementation details.
5. A deep Batch Inventory Ledger module that encapsulates atomic first-expired-first-out batch deductions, stock-on-hand updates, and movement logging behind an invariant-enforcing seam.

## User Stories

1. As a Cashier completing checkout, I want the sale receipt generated through an authoritative receipt module, so that printed receipts consistently reflect transaction facts and the current register paper width without layout errors.
2. As a Cashier requesting a customer reprint, I want the receipt module to resolve whether my register uses 58mm or 80mm paper dynamically, so that receipts format properly regardless of which terminal I am operating.
3. As a Cashier issuing a Cash Refund, I want the refund receipt formatted and emitted through the same document engine as sales, so that currency formatting and header styles remain identical across transactions.
4. As a Cashier performing a GCash Service exchange, I want the GCash Service Receipt rendered via a consistent document interface, so that transaction details and service fees match store presentation standards.
5. As an Administrator reviewing Receipt History, I want receipt lookups to load immutable transaction snapshots, so that subsequent store configuration edits never rewrite historical customer receipts.
6. As a customer inspecting a thermal receipt, I want verified store identification, product itemization, discounts, and cashier provenance clearly printed, so that I have a reliable proof of purchase.
7. As a mobile or remote operator loading receipt dialogs, I want HTTP conditional caching headers handled automatically by the receipt module, so that re-opening recent receipts produces fast 304 responses without redundant database queries.
8. As a developer maintaining receipt views, I want formatting lambdas and paper width queries removed from template files, so that templates remain clean presentation markup without embedded database queries.
9. As an Administrator managing demand forecasts, I want candidate model qualification governed by an authoritative service, so that models cannot be activated without passing automated quality and observation gates.
10. As an Administrator, I want to record operational model reviews directly within the governance interface, so that store approval authority is exercised without relying on technical platform administrators.
11. As a Super Administrator, I want technical model verification recorded through the same governance module, so that model architecture checks and operational approvals are tracked together in one audit record.
12. As a Store operator, I want demo and Kaggle synthetic models strictly barred from operational store activation, so that production replenishment recommendations rely exclusively on genuine store trading history.
13. As an Administrator, I want model activation to atomically update approved model settings, exit observation mode, resume replenishment recommendations, and log activity, so that no intermediate inconsistent state is persisted.
14. As an Administrator, I want a single-action model rollback that safely reverts to the previously approved model version and clears the rollback target, so that circular rollback loops are impossible.
15. As an Administrator, I want to suspend and resume automated replenishment recommendations with recorded justification, so that irregular store conditions can temporarily pause forecasting advice without corrupting model state.
16. As a developer inspecting machine learning configuration, I want all reads and writes to forecasting platform settings centralized in one module, so that divergent setting mutations cannot occur across separate controllers.
17. As a Cashier closing my shift, I want expected cash calculated using the exact authoritative formula for opening cash, cash sales, drawer movements, cash refunds, and approved reversals, so that drawer shortages or overages are accurately computed.
18. As an Administrator reviewing Store Reports across multiple shifts, I want the multi-shift financial summary to use the exact same aggregation queries and arithmetic as the individual cashier drawer closing, so that report figures always match drawer history.
19. As a Cashier navigating operational history, I want my paginated sales, refunds, movements, and shifts loaded directly through an authenticated history module, so that query parsing and permission checks do not rely on a redundant pass-through adapter.
20. As a Cashier with an active open shift, I want my operational history shift list to reflect live calculated expected cash, so that I can monitor drawer reconciliation in real time before formal shift closure.
21. As a developer maintaining financial logic, I want the four core shift financial aggregation queries housed in a single calculator module, so that any future payment method or movement adjustment is updated in one location.
22. As an automated test suite runner, I want multi-shift reporting queries to execute in a constant number of database roundtrips regardless of shift count, so that test execution and production reporting remain performant.
23. As a Store operator viewing the workspace dashboard, I want attention notifications delivered through a single interface, so that dashboard components do not query underlying database signals or threshold tables directly.
24. As an Administrator, I want attention items generated from internal signal rules without exposing loose dictionary schemas across public boundaries, so that attention evaluation remains robust against query structure changes.
25. As an Inventory Manager approving a Stock Issue report, I want damaged or expired stock deducted from batch inventory using strict first-expired-first-out ordering, so that oldest batches are exhausted first.
26. As an Inventory Manager recording physical stock counts, I want batch reconciliation handled by an authoritative inventory ledger, so that discrepancies adjust remaining batch quantities and total stock on hand atomically.
27. As an Inventory Manager executing supplier pullouts for expired bread, I want batch quantities deducted through the shared inventory ledger, so that batch records and stock movements stay synchronized.
28. As an Inventory Manager receiving new stock, I want newly arrived packages and batches credited through the inventory ledger, so that receiving unit multipliers and base units are consistently converted.
29. As a Store operator submitting stock adjustments, I want all batch and inventory mutations to verify that the active fiscal period is open, so that locked accounting windows cannot be mutated by any inventory workflow.
30. As a developer testing inventory workflows, I want a single inventory ledger seam to assert against, so that tests do not need to duplicate database verification for four separate inventory services.

## Implementation Decisions

### Decision 1: Receipt Generation & Presentation Engine
- Collapse the micro-presentation classes for sales, refunds, and GCash into an authoritative Receipt Engine module.
- Fold paper width calculation directly into the Receipt Engine: determine register paper width dynamically at print time based on active cashier shift status, while preserving transaction-fact snapshots in storage without baked-in paper widths.
- Absorb conditional HTTP caching logic, ETag computation, and customer dispute verification hashing into the Receipt Engine.
- Maintain a non-breaking facade shim in existing view support scripts to ensure backwards compatibility with procedural templates and existing regex-based contract tests.
- Eliminate duplicated formatting closures in templates: templates receive structured view data and focus purely on semantic HTML presentation.

### Decision 2: Store Model Governance & Lifecycle
- Consolidate model promotion, operational activation, technical reviews, rollbacks, and recommendation suspension into a single Store Model Governance module.
- Remove duplicate platform setting write methods across forecasting services: make the Store Model Governance module the sole authority for writing model version pointers, observation mode flags, and suspension toggles.
- Enforce the 28-day observation completion requirement and synthetic demo model exclusion inside the governance module before permitting operational activation.
- Separate technical review recording (exercised by the Super Administrator workspace) from operational store approval (exercised by the Administrator workspace per existing governance boundaries).
- Prevent circular rollbacks: rollback atomically restores the previous version and clears the previous version pointer in a single database transaction.

### Decision 3: Cashier Shift Financial Reconciliation & History
- Extract an authoritative Shift Reconciliation Calculator module that encapsulates the four core financial queries: sales breakdown by payment method, cash drawer movements, append-only cash refunds, and legacy approved reversals.
- Both individual cashier shift closure/preview and multi-shift reporting delegate their arithmetic and SQL queries to this single calculator.
- Preserve the constant query count performance invariant (4 aggregate queries across batched shifts) to satisfy reporting contracts.
- Fold the shallow cashier operational history request class directly into the cashier operational history service: request parameter validation, authorization scoping, and pagination query generation live in one cohesive module.
- Enrich open shift rows in cashier operational history with live calculated expected balances from the calculator module.

### Decision 4: Attention Signal & Rule Pipeline Encapsulation
- Treat the Attention Center as the sole external interface for retrieving attention items and synchronizing in-app notifications.
- Make database signal retrieval and rule threshold evaluation private internal implementation details within the attention module.
- Remove external direct calls to the database signal source from workspace dashboards and notification services; all callers interact with typed attention items rather than raw signal dictionaries.
- Remove legacy constructor overloads that accepted internal rule services, enforcing standard dependency injection via the database connection.

### Decision 5: Batch Inventory Ledger Consolidation
- Extract a deep Batch Inventory Ledger module that serves as the single transactional seam for all inventory balance mutations.
- The ledger encapsulates: row-level locking of inventory and batch records, first-expired-first-out allocation algorithms, dynamic handling of sealed sales packs versus loose base units, stock movement audit trail logging, and fiscal period open checks.
- Stock issue approvals, physical inventory count reconciliations, supplier pullouts, and stock receiving delegate their balance adjustments to this ledger.
- The ledger enforces the core business invariant: total product stock on hand must strictly equal the sum of remaining batch quantities.

## Testing Decisions

### Good Test Criteria
- Tests must verify observable behavior and domain invariants through external module interfaces rather than asserting private implementation state or database column order.
- Financial tests must verify penny-exact balance reconciliation across complex permutations of cash, card, e-wallet, safe drops, drawer pay-outs, cash refunds, and legacy reversals.
- Receipt tests must verify thermal width adaptation, snapshot immutability, and header caching without depending on live browser rendering.

### Modules to Test
- **Receipt Engine**: Exercise sale, refund, and GCash snapshot generation, 58mm vs 80mm layout switching, HTTP ETag generation, and legacy receipt fallback notices.
  - *Prior art*: `src/backend/tests/receipt_attribution_contract.php`, `src/backend/tests/receipt_caching_http_test.php`, `src/backend/tests/cash_refund_contract.php`.
- **Store Model Governance**: Test dual review requirements, demo artifact rejection, 28-day observation verification, platform setting persistence, and safe non-circular rollbacks.
  - *Prior art*: `src/backend/tests/store_activation_contract.php`, `src/backend/tests/model_promotion_contract.php`, `src/backend/tests/store_model_observation_contract.php`.
- **Shift Reconciliation Calculator**: Test expected cash math against identical fixtures across single-shift closing and batched store reports; assert constant 7-query ceiling in store shift reports.
  - *Prior art*: `src/backend/tests/store_shift_report_contract.php`, `src/backend/tests/cashier_shift_reconciliation_contract.php`, `src/backend/tests/cashier_operational_history_contract.php`.
- **Attention Center**: Verify role-based filtering, item deduplication, threshold ordering, and privacy of underlying signals.
  - *Prior art*: `src/backend/tests/attention_rules_contract.php`, `src/backend/tests/store_overview_attention_contract.php`.
- **Batch Inventory Ledger**: Verify atomic batch deduction, FEFO order enforcement, sales pack piece expansion, fiscal period blocking, and movement ledger generation.
  - *Prior art*: `src/backend/tests/cross_workflow_batch_verification_test.php`, `src/backend/tests/stock_issue_batch_deduction_test.php`, `src/backend/tests/inventory_count_reconciliation_test.php`.

## Out of Scope

- Introducing new external PHP frameworks, ORMs, or third-party dependency injection containers.
- Redesigning frontend user interface visual styles or replacing Tailwind/Bootstrap layouts.
- Altering existing database schema tables, column names, or foreign key constraints.
- Modifying machine learning training algorithms or random forest hyperparameters.
- Creating multi-store or multi-tenant architectural abstractions.

## Further Notes

- All changes maintain strict adherence to ADR-0001 (Administrator vs Super Administrator separation), ADR-0005 (Single Random Forest Pipeline), ADR-0006 (Preserve Customer Receipt Details), ADR-0013 (Batch Expiration & Replacements), ADR-0018 (Dynamic Register Paper Widths), and ADR-0022 (Local Administrator Model Approval).
- Existing test suites in `src/backend/tests/run_all.sh` will serve as the regression boundary throughout refactoring.

