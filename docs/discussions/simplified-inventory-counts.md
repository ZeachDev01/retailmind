# Simplified Inventory Counts

Discussion date: 2026-11-26. The user confirmed the combined design and authorized implementation. Implemented with automated verification; see the verification record below.

## Confirmed workflow

- Inventory Counts reconcile recorded stock with verified physical stock. Inventory Managers search for a Product, compare system balances with actual counts, review the difference, and save through one confirmation. Remove separate approval for newly saved counts; save the stock correction and its history together.
- Show existing batches and expiration information. With batches, only actual quantities per batch are editable; derive the Product total and difference automatically. Remove the competing editable Counted quantity field.
- Without batches, piece-only Products need one actual-count input. Products with Sales Packs use sealed-pack and loose-piece inputs, with a derived total. Apply the same distinction within batch rows.
- An empty count means not yet counted; an explicit zero means counted and none remain. Require every applicable existing batch to be counted rather than silently treating omitted rows as zero.
- Retain **Add found batch** for physically discovered stock absent from the batch list. This adds a batch for the selected Product, not a new Product. Do not invent an unknown expiration date.
- Retain a reason for differences, Inventory Manager attribution, stock and batch movement history, Administrator read-only oversight, fiscal-period checks, and protection against stock changing during counting. A stale comparison must be reviewed again before applying a correction.

## History and page layout

- Use two dedicated tabs, **New Count** and **Recent Counts**, following the existing Cashier GCash tab appearance and interaction. Keep count entry separate from history.
- Recent Counts needs pagination and sorting so staff can locate earlier work. Reuse the application's shared table controls. Default to newest first; preserve the selected tab and history search/sort state when paging.
- Support finding previous counts by Product name or SKU, with the recording staff member and date visible. Search and sort must cover the matching history, not only the currently displayed page.
- Administrators retain read-only history access; the New Count entry is for Inventory Managers.

## Existing pending counts

- Confirmed: preserve old pending-count history and require a fresh review/count before applying a correction. Never automatically apply old pending counts or retain approval as the ordinary new-count workflow.

## Existing evidence

- `src/frontend/components/inventory_management/inventory_counts.php` currently shows an editable Product total alongside editable batch quantities.
- `src/backend/app/Services/InventoryCountService.php` already derives the total from submitted batch counts or sealed/loose quantities. Recording currently creates a pending count; approval applies its correction.
- `src/backend/app/Services/BatchInventoryLedger.php` rejects stock or batch balances that changed since the count snapshot.
- `GLOSSARY.md` defines Stock Count Correction and now names Inventory Count explicitly.

## Implementation and verification

- The active page and legacy route share the same direct-save workflow. New Count and Recent Counts use scoped, keyboard-accessible tabs with Cashier GCash styling.
- Saving checks the displayed stock snapshot, requires every active batch explicitly, and writes count, reconciliation, movement history, retry identity, and any replaced pending-count decision in one transaction. Existing database status values remain compatible; the UI displays Saved, Needs recount, and Closed.
- Existing pending counts link to a fresh count without copying their old actual quantities. A successful replacement closes that specific pending record, preserving its quantities and recording the replacement count in the audit trail.
- History uses server-side Product/SKU search, whitelisted sorting, stable record ordering, and shared pagination across all matching records. Default: newest first and 15 rows per page.
- `inventory_count_reconciliation_test.php` covers direct application, retry identity, rollback, changed batch allocation, omitted batches, pending replacement, and history pagination/sorting. `inventory_counts_workflow_browser_test.js` uses a disposable database for actual browser workflows and light/dark layout checks at 390, 768, and 1366 pixels. The barcode browser suite follows direct saving and read-only Administrator history.
- TestSprite passed run `327f0490-a83f-4612-8115-b09dd8b62d06` in the user-selected `retailmind-ui-role-devices-20261116` project: [workflow evidence](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/test-cases/7cc85e1b-0079-478b-a8a8-330d2524d772). The run used a disposable Store through a local tunnel. Supplemental local browser tests cover viewport geometry, stock and batch database effects, stale rejection, and Administrator read-only access.
- `npm run check` passed (one existing duplicate-ID warning in Cashier Shifts). Broader old suites are not claimed as passed: Stock Issue oversight lacks a `product_batches` fixture table; Prepared Oil Pouches inserts obsolete `users.full_name`; the combined stock-form/issue suite also contains retired Cashier form and approval-message expectations.

## Both-tab UI redesign — 2026-11-26

The user requested redesign of both tabs after reviewing the Recent Counts screenshot. New Count now reveals a divided editor and summary after Product selection; the summary moves below entry on narrow screens. Recent Counts uses a styled search toolbar, product-name-first identity, a compact batch-details link, aligned quantities, explicit short/extra/matched text, two-line Philippine date/time, and a conditional recount Action column. Shared table ghost rows are disabled locally, so one record no longer reserves six rows. Existing search, sorting, pagination, stock writes, and permissions remain intact.

Local workflow, barcode, contract/reconciliation tests and `npm run check` passed; light/dark captures cover 390, 768, and 1366 pixels. The redesign TestSprite run was blocked by its tunnel/login-page connection and is not claimed as passed. See [the surface verification](../../.impeccable/surfaces/inventory-counts.md) for run details and evidence paths.
