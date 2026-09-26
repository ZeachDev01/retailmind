# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

RetailMind serves Shalom Store staff. Its primary users are the Administrator and Inventory Manager: the Administrator owns Store performance, staff, approvals, compliance, Fiscal Periods, Store Settings, and operational exceptions, while the Inventory Manager runs stock monitoring, replenishment, receiving, and inventory control.

Cashiers use RetailMind for barcode sales, shifts, and Stock Issue reporting. A single technical Super Administrator governs platform security, recovery, access policy, system health, and demand-forecasting model operation without performing routine Store work.

## Product Purpose

RetailMind gives one Store a connected operating system for inventory, purchasing, barcode sales, cashier shifts, reporting, and demand forecasting. It exists so shelf activity, checkout records, stock movements, forecasts, and replenishment decisions remain part of one operational record. Success means Store staff can act on accurate information without rebuilding it across separate tools.

## Positioning

RetailMind is purpose-built around one Store rather than multi-branch administration. It connects each barcode sale and stock movement to inventory visibility, demand forecasting, and controlled replenishment while preserving explicit authority boundaries among Cashiers, Inventory Managers, the Administrator, and the Super Administrator.

## Operating Context

- Cashiers scan products, process and hold sales, print receipts, manage shifts, and report Stock Issues.
- Inventory Managers monitor stock and expiry risk, perform counts, receive stock, manage suppliers and Supplier Product Terms, review forecasts, and plan replenishment.
- The Administrator oversees Store performance, operational staff, approvals, Fiscal Periods, Store Settings, compliance, and operational exceptions.
- The Super Administrator manages platform security, recovery, system health, technical settings, privileged identities, and demand-forecasting model operation.
- Sales activity updates stock movement and forecast history; reviewed demand recommendations inform purchase orders and receiving.
- The application handles Philippine-peso retail operations at Shalom Store.

## Capabilities and Constraints

- The product is a server-rendered web application built with PHP 8.1+, JavaScript, and SQL.
- RetailMind operates one Store. User-facing multi-branch concepts are excluded, although a singleton compatibility record may remain internally.
- Role-specific workspaces and backend authorization enforce separate operational and technical authority.
- Demand forecasting uses Product + Day inputs for the Store and informs replenishment; forecasts are estimates, not guaranteed demand.
- Staff-facing failures use calm, nontechnical Operator Alerts. Full technical details belong in application logs and, when appropriate, Protected Audit Records; production must keep debug output disabled.
- The Administrator and Super Administrator may create and download complete readable SQL Database Backups. Only the Super Administrator may perform a full Database Restore after verifying their current password.
- Database Restore replaces the active database state and requires Store activity to be blocked during replacement.
- Disabled Accounts retain historical attribution. Security-sensitive and operational actions recorded as Protected Audit Records are not individually editable or deletable in the application.
- RetailMind must not invent customer claims, performance benchmarks, testimonials, or operating evidence that is not present in the repository or supplied by the product owner.

## Brand Commitments

The product name is **RetailMind** and the Store is **Shalom Store**. Product language uses the domain terms defined in `CONTEXT.md`, including Store, Administrator, Super Administrator, Inventory Manager, Cashier, Demand Forecast, and Protected Audit Record. Avoid multi-branch language and synonyms explicitly rejected by the domain glossary.

## Evidence on Hand

- The implemented application contains role-specific workspaces for the Administrator, Super Administrator, Inventory Manager, and Cashier.
- Existing workflows cover barcode sales, shifts, Stock Issues, products, inventory counts, receiving, suppliers, purchase orders, promotions, reports, notifications, backups, restore, audit visibility, system health, and demand forecasting.
- `CONTEXT.md` is the authority for domain language and role responsibilities.
- `docs/adr/` records the confirmed single-Store model, administrator boundaries, staff-safe Operator Alerts, and current Database Backup and Database Restore decisions.
- `product_seed.csv` provides product seed data for the Store.
- No approved testimonials, external customer roster, benchmark results, or marketing performance claims are on hand.

## Product Principles

1. **Keep one operational record.** Sales, stock, forecasting, and replenishment should strengthen the same Store record rather than create parallel sources of truth.
2. **Respect role boundaries.** Routine Store work, operational governance, and technical governance remain distinct even when workflows touch the same data.
3. **Make the shop floor calm.** Staff receive clear, actionable language while technical detail remains available through the proper diagnostic and audit channels.
4. **Treat forecasts as decision support.** Model outputs inform human review and controlled purchasing rather than presenting uncertain demand as fact.
5. **Preserve accountability.** Account lifecycle, approvals, recovery actions, and sensitive operational changes retain historical attribution and auditable records.
