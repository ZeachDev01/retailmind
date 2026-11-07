# RetailMind Workspace & Component Map

Quick navigation reference for roles, frontend views, controllers, styles, and scripts.

---

## 1. Role Routing & Entry Points

Defined in [`RoleWorkspaceRouter.php`](file:///c:/xampp/htdocs/retailmind/src/backend/app/Authorization/RoleWorkspaceRouter.php) and [`sidebar.php`](file:///c:/xampp/htdocs/retailmind/src/frontend/components/sidebar.php).

| Role | Landing View | Command Product Target | Key Sections |
|---|---|---|---|
| `super_admin` | `components/super_administrator/dashboard.php` | `components/inventory_management/inventory_overview.php` | Platform Governance, Forecasting |
| `admin` | `components/administrator/dashboard.php` | `components/inventory_management/products.php` | Store Operations, Store Management, Forecasting, Store Reports |
| `inventory_manager` | `components/inventory_management/inventory_overview.php` | `components/inventory_management/products.php` | Stock Overview, Products, Replenishment, Receiving, Inventory Control, Reports |
| `cashier` | `components/cashier/pos.php` | `components/cashier/pos.php` | Sales (POS, GCash, Shifts, Refunds, Dashboard), Documents |

---

## 2. Workspace View Catalog

### Super Administrator (`super_admin`)
- **Dashboard**: `components/super_administrator/dashboard.php`
- **Users & Access**: `components/user_manager/user_manager.php`
- **Protected Audit Records**: `components/system_administrator/audit_logs.php`
- **System Health**: `components/system_administrator/system_health.php`
- **Reset Data**: `components/administrator/reset_data.php`
- **Backup & Restore**: `components/system_administrator/backup_restore.php`
- **Platform Settings**: `components/system_administrator/system_settings.php`
- **Forecasting**:
  - Import Sales: `components/system_administrator/forecast_import.php`
  - Data Readiness: `components/report/data_readiness.php`
  - Model Training: `components/system_administrator/ml_settings.php`
  - Forecast Analytics: `components/report/forecast_analytics.php`
  - Demand Forecast: `components/report/predictions.php`
  - Forecast Exceptions: `components/report/forecast_exceptions.php`

### Administrator (`admin`)
- **Dashboard**: `components/administrator/dashboard.php`
- **GCash Overview**: `components/administrator/gcash_overview.php` (API: `gcash_overview_api.php`)
- **Store Management**:
  - Add Counter: `components/administrator/registers.php`
  - Store Configuration: `components/administrator/store_settings.php`
  - Store Calendar: `components/administrator/store_calendar.php`
  - Fiscal Periods: `components/system_administrator/fiscal_periods.php`
  - Database Backup: `components/administrator/database_backup.php`
  - Operational Audit: `components/system_administrator/audit_logs.php`
- **Promotions**: `components/inventory_management/promotions.php`
- **Stock Issue Oversight**: `components/administrator/stock_issues.php`
- **Forecasting & Approvals**: `components/administrator/model_review.php`, `predictions.php`, `forecast_exceptions.php`
- **Reports**: `components/administrator/shift_report.php`, `receiving_history.php`, `sales.php`, `report_generation.php`
- **Refund Corrections**: `components/cashier/refund_corrections.php`

### Inventory Manager (`inventory_manager`)
- **Stock Overview**: `components/inventory_management/inventory_overview.php`
- **Products**: `components/inventory_management/products.php` (CSV import: `csv_import.php`, Barcodes: `print_barcodes.php`)
- **Replenishment**: `components/inventory_management/replenishment_queue.php`
- **Stock Receiving**: `components/report/stock_receiving.php`, `receiving_history.php`
- **Inventory Control**:
  - Physical Counts: `components/inventory_management/inventory_counts.php`
  - Stock Issue Reviews: `components/inventory_management/stock_issues.php`
  - Supplier Pullouts: `components/inventory_management/supplier_pullouts.php`
- **Investigation & Reports**: `components/inventory_management/product_investigation.php`, `inventory_insights.php`, `predictions.php`

### Cashier (`cashier`)
- **Point of Sale**: `components/cashier/pos.php`
- **GCash Portal**: `components/cashier/gcash.php` (API: `gcash_api.php`, Receipt: `gcash_receipt.php`)
- **Shift Management**: `components/cashier/shifts.php`
- **Refunds**: `components/cashier/refunds.php`
- **Refund Corrections**: `components/cashier/refund_corrections.php`
- **Dashboard Overview**: `components/cashier/dashboard.php`
- **Transaction History**: `components/cashier/history.php`
- **Product Lookup**: `components/cashier/findProduct.php`
- **Report Stock Issue**: `components/cashier/stock_issues.php`

---

## 3. Shared Shell & Assets

- **Navigation & Layout**:
  - `src/frontend/components/sidebar.php`: Navigation bar, breadcrumb generation, flash messages, user session shell.
  - `src/frontend/assets/js/sidebar-loader.js` & `ui.js`: Client shell interactions, modal triggers, data tables (`initSmartTables()`).
- **Global CSS**:
  - `global.css`, `style.css`, `responsive.css`, `sidebar.css`, `forms.css`, `tables.css`, `modals.css`, `utilities.css`.
- **Domain CSS Modules**:
  - Cashier: `cashier.css`, `cashier-pages.css`, `refunds.css`, `refund-corrections.css`, `sale-receipt.css`.
  - Inventory: `inventory.css`, `products.css`, `replenishment.css`, `stock-issues.css`, `supplier_pullouts.css`.
  - Admin: `admin.css`, `gcash-admin.css`, `registers.css`, `store-settings.css`, `fiscal-periods.css`.
  - System: `audit-logs.css`, `backup_restore.css`, `system-health.css`, `ml-settings.css`.
