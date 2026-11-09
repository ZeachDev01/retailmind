# RetailMind Workspace & Component Map

Quick navigation reference for roles, frontend views, modals, backing client scripts, and backend services.

---

## 1. Role Routing & Entry Points

Defined in [`RoleWorkspaceRouter.php`](file:///c:/xampp/htdocs/retailmind/src/backend/app/Authorization/RoleWorkspaceRouter.php) and [`sidebar.php`](file:///c:/xampp/htdocs/retailmind/src/frontend/components/sidebar.php).

| Role | Landing View | Command Product Target | Key Sections |
|---|---|---|---|
| `super_admin` | `components/super_administrator/dashboard.php` | `components/inventory_management/inventory_overview.php` | Platform Governance, Forecasting, Security Audit |
| `admin` | `components/administrator/dashboard.php` | `components/inventory_management/products.php` | Store Operations, Registers, Calendar, Forecasting, Reports |
| `inventory_manager` | `components/inventory_management/inventory_overview.php` | `components/inventory_management/products.php` | Stock Overview, Products, Replenishment, Receiving, Counts |
| `cashier` | `components/cashier/pos.php` | `components/cashier/pos.php` | Sales (POS, GCash, Shifts, Refunds, Dashboard), Documents |

---

## 2. Workspace View Catalog

### Cashier (`cashier`)
- **Point of Sale**: `components/cashier/pos.php`
  - Partials & Modals: `src/backend/app/Views/sale_receipt_dialog.php`, `cash_drawer_counter.php`, `table_pagination.php`
  - Backing JS: `cart-workspace.js`, `checkout-attempt.js`, `checkout-quote.js`, `cash-drawer-counter.js`, `sale-receipt.js`, `sale-receipt-dialog.js`, `page-navigation.js`
  - Backend Services: `SalesWorkflowService`, `CashierShiftService`, `HeldSaleService`
- **GCash Portal**: `components/cashier/gcash.php` (API: `gcash_api.php`, Receipt: `gcash_receipt.php`)
  - Partials: `src/backend/app/Views/gcash_receipt_dialog.php`, `gcash_receipt.php`
  - Backing JS: `gcash.js`, `gcash-reference.js`
  - Backend Services: `App\Services\GCashTransactionService`
- **Shift Management**: `components/cashier/shifts.php`
  - Backend Services: `App\Services\CashierShiftService`, `ShiftReconciliationCalculator`, `CashDenominationCounter`
- **Refunds & Corrections**: `components/cashier/refunds.php`, `refund_corrections.php`
  - Partials: `src/backend/app/Views/refund_receipt.php`
  - Backend Services: `App\Services\CashRefundService`, `RefundCorrectionReviewService`, `SaleReversalService`
- **Dashboard & History**: `components/cashier/dashboard.php`, `history.php`, `findProduct.php`
  - Backing JS: `cashier-overview.js`
  - Backend Services: `App\Services\CashierOperationalHistoryService`, `ProductCodeLookupService`
- **Stock Issue Reporting**: `components/cashier/stock_issues.php`
  - Backend Services: `App\Services\StockIssueService`

### Inventory Manager (`inventory_manager`)
- **Stock Overview**: `components/inventory_management/inventory_overview.php`
  - Modals: `components/modals/product_overview/` (`expiredStock.php`, `expringSoon.php`, `lowOfStack.php`, `outOfStack.php`, `viewBatches.php`)
  - Backing JS: `inventory-drafts.js`
  - Backend Services: `App\Services\InventoryService`, `BatchInventoryLedger`
- **Products**: `components/inventory_management/products.php` (CSV import: `csv_import.php`, Barcodes: `print_barcodes.php`)
  - Modals: `components/modals/product_overview/product.php`
  - Backing JS: `inventory-drafts.js`
  - Backend Services: `App\Services\ProductService`
- **Replenishment Queue**: `components/inventory_management/replenishment_queue.php`
  - Backing JS: `replenishment.js`, `replenishment-pdf.js`, `jspdf.umd.min.js`
  - Backend Services: `App\Forecasting\ReplenishmentQueueService`
- **Stock Receiving & History**: `components/report/stock_receiving.php`, `receiving_history.php`
  - Backend Services: `App\Services\ReceivingService`
- **Inventory Control**:
  - Physical Counts: `components/inventory_management/inventory_counts.php` (`App\Services\InventoryCountService`)
  - Stock Issue Reviews: `components/inventory_management/stock_issues.php` (`App\Services\StockIssueService`)
  - Supplier Pullouts: `components/inventory_management/supplier_pullouts.php` (`App\Services\SupplierPulloutService`)
- **Investigation & Predictions**: `components/inventory_management/product_investigation.php`, `inventory_insights.php`, `components/report/predictions.php`
  - Backing JS: `forecast-notices.js`
  - Backend Services: `App\Forecasting\DemandForecastService`

### Administrator (`admin`)
- **Dashboard**: `components/administrator/dashboard.php`
  - Backend Services: `App\Dashboard\StoreOperationsDashboardWorkspace`, `App\Services\DashboardService`
- **GCash Admin Overview**: `components/administrator/gcash_overview.php` (API: `gcash_overview_api.php`, fragments: `gcash_overview_fragments.php`)
  - Backing JS: `gcash-admin-overview.js`
  - Backend Services: `App\Services\GCashTransactionService`, `App\GCash\AdminOverviewReport`
- **Store Management**:
  - Register Counters: `components/administrator/registers.php` (`App\Services\RegisterService`)
  - Store Calendar: `components/administrator/store_calendar.php` (`App\Store\StoreCalendar`, JS: `store-staff-availability.js`)
  - Store Configuration: `components/administrator/store_settings.php` (`App\Store\StoreSetupConfirmations`)
  - Fiscal Periods: `components/system_administrator/fiscal_periods.php` (Modal: `components/modals/fiscal_periods.php`, `App\Services\FiscalPeriodGuardService`)
  - Operational Audit: `components/system_administrator/audit_logs.php`
- **Forecasting & Approvals**: `components/administrator/model_review.php`, `components/report/predictions.php`, `forecast_exceptions.php`
  - Backing JS: `forecast-notices.js`
  - Backend Services: `App\Forecasting\StoreModelGovernanceService`, `CandidateEvaluationService`
- **Reports**: `components/administrator/shift_report.php`, `receiving_history.php`, `sales.php`, `report_generation.php`
  - Backend Services: `App\Services\StoreShiftReportService`, `ReportAccessService`, `GrossProfitService`
- **Stock Issue Oversight**: `components/administrator/stock_issues.php`
- **Promotions**: `components/inventory_management/promotions.php`

### Super Administrator (`super_admin`)
- **Governance Dashboard**: `components/super_administrator/dashboard.php`
  - Backend Services: `App\Dashboard\DashboardWorkspace`, `PlatformHealthSource`
- **Users & Access**: `components/user_manager/user_manager.php`
  - Modals: `components/user_manager/modals/add_user_modal.php`, `manage_user_modal.php`
  - Backing JS: `profile-picture-crop.js`, `password-feedback.js`, `store-staff-availability.js`
  - Backend Services: `App\Services\UserLifecycleService`, `UserAccountNotifier`, `App\Authorization\DormancyPolicyService`
  - Contract Tests: `src/backend/tests/dormancy_status_tab_contract.php`, `password_policy_contract.php`, `recovery_exclusion_reset_audit_contract.php`, `store_staff_username_notice_contract.php`, `store_staff_email_notice_contract.php`, `smoke_checks.php`
- **Protected Audit Records**: `components/system_administrator/audit_logs.php`
  - Modals: `components/modals/audit_log.php`
  - Backing JS: `audit-log-drawer.js`
  - Backend Services: `App\Audit\ProtectedAuditRecordService`
- **System Health & Attention**: `components/system_administrator/system_health.php`
  - Modals: `components/modals/system_health.php`
  - Backend Services: `App\Services\SystemHealthService`, `App\Attention\AttentionCenter`, `AttentionRuleService`
- **Backup & Restore**: `components/system_administrator/backup_restore.php`, `components/administrator/database_backup.php`
  - Modals: `components/backup/restore_form.php`
  - Backing JS: `backup_restore.js`, `backup_recovery_tabs.js`, `backup_status.js`, `backup_review.js`
  - Backend Services: `App\Backup\DatabaseBackupService`, `DatabaseRestoreService`, `RecoveryStore`, `SafetyCopyService`
- **Platform Settings & Reset**: `components/system_administrator/system_settings.php`, `components/administrator/reset_data.php`
  - Backend Services: `App\Services\OperationalDataResetService`, `App\Services\TunnelSetupBlockBuilder`
  - Contract Tests: `tunnel_setup_block_contract.php`
- **Forecasting Pipeline**:
  - Import Sales: `components/system_administrator/forecast_import.php` (`App\Services\ForecastHistoryImportService`)
  - Data Readiness: `components/report/data_readiness.php` (`App\Forecasting\DemandHistoryService`)
  - Model Training & Settings: `components/system_administrator/ml_settings.php` (`App\Services\MlJobService`)
  - Analytics & Exceptions: `components/report/forecast_analytics.php`, `forecast_exceptions.php` (`App\Forecasting\ForecastAggregateReader`)

---

## 3. Shared Shell, Auth, Layout & Assets

- **Public Landing & Auth Views**:
  - Landing & Login Modal: `src/frontend/index.php` (CSS: `landing.css`, Hero Asset: `assets/img/retailmind-landing-hero.jpg`)
  - Change Password (Mandatory/Voluntary Dual-Mode): `src/frontend/components/auth/change_password.php` (Contracts: `src/backend/tests/password_change_modes_contract.php`, `password_policy_contract.php`)
  - Profile & Account Info: `src/frontend/components/auth/user_info.php` (CSS: `user-info.css`)
- **Navigation & Layout**:
  - Shell view: `src/frontend/components/sidebar.php` (sidebar navigation, breadcrumbs, session context)
  - Layout JS: `src/frontend/assets/js/sidebar-loader.js` (DOM shell loader), `ui.js` (smart tables `initSmartTables()`, modals, alerts)
  - Common JS: `session-activity.js`, `theme.js`, `info-tooltip.js`, `sw-register.js`
- **Compiled Stylesheet Bundle (`style.css`)**:
  - **Build Script**: `php src/backend/scripts/build_styles.php` (`npm run build:styles`, verify via `npm run check:styles`). Never edit `style.css` directly.
  - **9 Bundle Sources** (`src/frontend/assets/css/`): `global.css`, `forms.css`, `utilities.css`, `sidebar.css`, `dashboard.css`, `tables.css`, `modals.css`, `products.css`, `responsive.css`
- **Standalone Domain CSS Modules**:
  - Landing & Auth: `landing.css`, `user-info.css`, `workspace-guidance.css`, `remote-access-pill.css`
  - Cashier: `cashier.css`, `cashier-pages.css`, `refunds.css`, `refund-corrections.css`, `sale-receipt.css`
  - Inventory: `inventory.css`, `replenishment.css`, `stock-issues.css`, `supplier_pullouts.css`
  - Admin: `admin.css`, `gcash-admin.css`, `registers.css`, `store-settings.css`, `fiscal-periods.css`, `reset-data.css`
  - System: `audit-logs.css`, `backup.css`, `backup_restore.css`, `system-health.css`, `ml-settings.css`
