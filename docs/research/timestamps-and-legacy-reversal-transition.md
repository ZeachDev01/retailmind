# Timestamp evidence and Legacy Reversal transition (#107)

Investigated 2026-10-01 against commit `1e3d62abcace15aa5ec727a231dc1ee0108c6c74` and the configured local database. This is a prerequisite specification for #114 (ticket 8), not an application change or authorization to alter financial records. Read with [the agreed specification](../specs/cashier-workflow-clarity.md), [ADR-0001](../adr/0001-single-store-administrator-boundaries.md), and [ADR-0006](../adr/0006-preserve-customer-receipt-details.md).

## Read-only evidence and limits

At approximately 9:00 AM Philippine time, direct PDO inspection used the local `DB_*` configuration, `START TRANSACTION READ ONLY`, SELECTs, a session-only timezone change, and ROLLBACK. No migration, sale, refund, stock movement, shift change, or reversal resolution was performed. Normal application bootstrap could not be used in this sandbox: it reported `Recovery coordination is unavailable`. The inspection loaded only `App\Core\Environment` and opened PDO; it did not bypass Store admission for an operational action. No hosted/production database or authenticated browser state was inspected. Rerun these checks on the actual target before transition.

The complete local `sale_reversals` and `sale_reversal_items` sets were **empty**, including pending, approved, and rejected records. Thus there are no observed record-specific transition decisions locally; supported states below come from schema/service inspection, not invented live examples. The local drawer movement set was also empty. Stock Issues are stored in `inventory_adjustments`, not a table named `stock_issues`.

## Timestamp storage and producers

PHP bootstrap (`src/backend/bootstrap/app.php`) sets `APP_TIMEZONE`, default `Asia/Manila`. Local inspection confirmed `Asia/Manila`. `App\Core\Database` does not set the connection timezone. The inspected connection had global/session `SYSTEM`, system `Asia/Singapore`; at observation, `NOW()` was `2026-10-01 09:00:01` and `UTC_TIMESTAMP()` was `2026-10-01 01:00:01`. This proves the local session's +08 offset at inspection; it does not establish every deployment's convention.

| Record | Actual local column type | Write convention / representative read in original session |
| --- | --- | --- |
| Sale | `sales.sale_date TIMESTAMP` | `SalesWorkflowService` normally omits it and uses database `CURRENT_TIMESTAMP`; sale 9748: `2026-10-01 08:39:18` |
| Cash Refund | `cash_refunds.created_at TIMESTAMP` | `CashRefundService` omits it, default `CURRENT_TIMESTAMP`; observed `2026-09-30 16:17:09` |
| Drawer movement | `cash_drawer_movements.created_at TIMESTAMP` | `CashierShiftService` omits it, default `CURRENT_TIMESTAMP`; empty local set |
| Cashier Shift | `opened_at`, `closed_at`, `reviewed_at`, `locked_at` are `TIMESTAMP` | Opening uses default; close/lock use `NOW()`; observed opening `2026-09-30 15:51:36` |
| Stock Issue | `inventory_adjustments.reported_at`, `approved_at` and `inventory_adjustment_revisions.created_at` are `TIMESTAMP` | Submit/revision creation use defaults; review uses database clock; observed report `2026-09-29 22:24:13` |
| Legacy Reversal | `sale_reversals.created_at`, `approved_at TIMESTAMP` | Request default; approval/rejection `CURRENT_TIMESTAMP`; empty local set |

MySQL TIMESTAMP represents an instant and projects it into the session timezone when selected; its returned zone-less string must be interpreted in that session's zone. This differs from a DATETIME or PHP-generated local string, which needs its producer's explicit convention. The inspected transaction columns above are TIMESTAMP, not Manila-local DATETIME values. Fresh schema and Cash Refund/Stock Issue migrations agree. SQL dumps set their own `TIME_ZONE='+00:00'`; their timestamp literals must not be interpreted using the interactive session's zone. `DailySalesTrendSeeder` and its CLI script supply PHP-generated times after aligning the session offset; do not assume those explicit writes match every older import.

`DashboardService::getSalesTrend` changes the shared PDO session to its PHP date offset. A formatter must not guess the source zone from the PHP default if a different connection setting can precede the read. Current `format_display_datetime` uses `strtotime` and a compact numeric format without source-zone information. The safe implementation must align the transaction read connection or attach a known source zone to returned values.

Representative instant proof: sale 9748 had epoch `1790815158` and `2026-10-01 08:39:18` in the initial +08 session. Selecting the same row after session `SET time_zone = '+00:00'` yielded `2026-10-01 00:39:18` with the **same epoch**. Sale 9747 was `2026-10-01 08:35:23`, epoch `1790814923`; sale 9746 was `2026-09-30 15:56:51`, epoch `1790755011`. These readings warrant no eight-hour correction to stored history.

## Formatting, Manila days, and order

Use one documented read convention for TIMESTAMP: either a +08:00 connection and interpret returned strings as Asia/Manila, or a UTC connection and parse explicitly as UTC before converting with `DateTimeZone('Asia/Manila')`. Choose the convention before reading, consistently for history, filters, receipts, and reports. Do not change a shared connection midway through a query pipeline. If a deployment has legacy DATETIME columns, stop and establish producer/import provenance before treating those as instants.

Display the stored instant using PHP format `M j, Y · g:i A` in Asia/Manila. For sale 9748 the result is **Oct 1, 2026 · 8:39 AM**; seconds remain stored. Never use the reprint/render time. Preserve receipt snapshots, seller, product and Register details under ADR-0006; older receipt fallback labels remain intact.

For Manila day 2026-10-01 use inclusive start, exclusive next-day start: `[2026-10-01 00:00:00+08, 2026-10-02 00:00:00+08)`. UTC-session bounds are `[2026-09-30 16:00:00Z, 2026-10-01 16:00:00Z)`. Bind bounds in the query connection's zone and compare `timestamp >= ? AND timestamp < ?`; use the same logic for each record type. Avoid `23:59:59` end bounds and filtering localized display text. Sort the actual timestamp then stable record ID; combined history needs instant, record type, and ID as tie-breakers. Do not sort formatted strings or rewrite history to fix presentation.

## Existing Legacy Reversal accounting

`src/backend/sql/schema.sql` permits types `cancel`, `return`, `refund`, `exchange`; states `pending`, `approved`, `rejected`; settlements `none`, `cash`, `card`, `ewallet`, `exchange`. No cancelled/resolved-conversion state exists. `SaleReversalService` stores positive per-line quantities, original unit prices/subtotals, a free entered nonnegative refund amount, requester and decision actor/time; it lacks disposition and paying-shift columns. Cancellation requests select all remaining quantities; other types select requested lines. Multiple pending requests may overlap. Free entered amount is not currently capped to amount paid or forced to the original payment method.

Pending and rejected states themselves move no inventory or expected cash. Approval restores **every** returned quantity to available inventory, restores allocated product batches where allocations exist, decreases `products.quantity_sold`, and inserts positive `stock_movements` with reason `return`. Missing allocations can leave batch and available-stock history divergent. No damaged-return classification exists in this ledger. Approval does not edit the original sale or create a Cash Refund or Refund Receipt.

An approved `cash` settlement subtracts its stored `refund_amount` from `CashierShiftService::summary` for the **original sale's shift**. It records an accounting deduction, not proof of physical payout. Card/e-wallet/none/exchange do not affect expected drawer cash. There is no legacy field proving an external transfer or identifying a different paying drawer. Approved records consume line quantities in the legacy service; pending requests do not reserve them. New Cash Refund eligibility remains blocked for the whole sale with any pending/approved legacy record, including a partial or zero-value approval.

A closed shift's saved `expected_cash`, `actual_cash`, and `cash_variance` remain unchanged, while recomputed `calculated_expected_cash` includes later approved reversals. `StoreShiftReportService` uses saved expected cash for closed shifts, but represents approved legacy refunds as `Legacy / Unassigned Reversal` rows by approval day/requester without inventing Register or shift attribution. Sale-linked cash summaries and approval-day reports therefore have different grains. Do not change historical saved reconciliation, backdate a decision, insert a second drawer payout, or silently reattribute a reversal to today's shift.

Existing approval fiscal guards require the original sale's sales/reversal periods and today's reversal/stock movement periods open. Today's Cash Refund policy for old closed-period sales does not authorize retroactive legacy approval. Preserve this distinction in transition; unsupported closed-period pending cases use rejection with a reason if no effects occurred, then a separately authorized current-day Cash Refund under #113.

## Supported pending resolutions for #114

Only the Administrator in the authorized operational workspace may decide. Super Administrator operational intervention requires active, reason-bound Emergency Access under ADR-0001. Authenticate and authorize server-side; a user ID or hidden button is insufficient. Keep original requester, reason, items, sale, receipt, timestamps and decision history. Append one Protected Audit Record atomically with each state transition, recording actor, reason, original and resulting state, quantity/value, financial checks, and attribution. Terminal records are immutable. A retry must return/read the committed outcome without another stock movement or payout.

### Reject with a documented disposition

Support `pending -> rejected` with an Administrator reason. Useful dispositions include customer withdrew, duplicate/invalid request, request replaced by the Cash Refund workflow, unsupported exchange/settlement, or closed-period legacy approval refused. Replacement is **not** automatic refund creation: show an explicit instruction for a separate Cash Refund only after all blocking pending requests are resolved. Rejection means no legacy payout or stock restoration occurred. If evidence suggests money/stock already moved outside the recorded ledger, leave pending for investigation instead of asserting this false fact.

Before/after rejection: expected cash `C -> C`; available stock `S -> S`; consumed refund quantity/value unchanged; legacy refundable quantities unchanged. Cash Refund usable balance was unavailable while any pending request existed. Once every pending request is rejected and no approved reversal exists, usable Cash Refund quantity becomes `sold quantity - already issued Cash Refund quantity` and value `paid amount - already issued Cash Refund amount`, subject to #113's line allocation and paid caps. Other approved/pending records continue blocking. Existing rejected records require no mutation.

### Approve only a verified, representable legacy request

Support `pending -> approved` only after an explicit Administrator review proves: positive valid quantities belong to this sale and do not exceed remaining legacy quantities; there are no Cash Refunds; returns are all Restockable and batch restoration can be reconciled; settlement and amount reflect verified actual obligation, original method, and remaining paid value. For money settlements require positive amount no greater than remaining paid amount; require `none` to have zero amount. Partial amount must be defensible from original prices/discounts, not an arbitrary number. Never silently edit an inconsistent request to make it pass.

For a cash settlement, support approval only when the original attributed sale shift is still open and unlocked, is the identified physical paying drawer, and has enough expected cash. Lock this shift with the sale and request. If paid already or paying shift differs/is closed/unassigned, the legacy schema cannot safely represent this as a new payout: leave pending for investigation or reject with a verified no-effects reason and use a separate current-day Cash Refund. Require confirmation of one payout and ensure retries do not request it again. Do not add a cash drawer movement for the same refund.

For card/e-wallet require verified external settlement evidence in the immutable decision audit; legacy fields alone do not prove transfer. `none` is supported only as an explicitly justified zero-money Restockable return whose future Cash Refund ineligibility is acknowledged. `exchange` settlement, damaged returns, missing batch evidence, mixed/non-original methods, overpayment, and closed historical fiscal guards are unsupported approval cases. Exchange type can be approved only if its actual settlement is a verified ordinary cash/card/e-wallet refund with separate replacement sale; no replacement inventory or store credit is synthesized.

Before/after approval for returned quantities `q`, verified amount `a`: available stock `S -> S+q`; legacy remaining quantity `Q -> Q-q`; nominal remaining paid balance `P -> P-a` for money settlement (unchanged for `none`). Products' sold count decreases by `q`, bounded by zero as in existing code, and attributable batch remaining quantity increases by reconciled `q`. Cash settlement changes the original open paying shift `C -> C-a` once; other settlements leave `C` unchanged. Cash Refund usable balance remains **ineligible for the whole sale** because an approved legacy record exists. No synthetic Cash Refund is created. Any disagreement between these before/after values aborts the transaction.

Approved history is retained without repayment, stock re-restoration, new refund eligibility, or reconciliation changes, including partial approvals. Cases needing further corrections use an explicitly designed future process; #114 must show the reason for refusal rather than bypass the guard. Unknown states or broken links remain visible for Administrator investigation; never delete, mass-reject, or silently convert them.

## Mutual exclusion and transition plan

Both ledgers must serialize on the same sale lock inside the Store write gate. Re-read request states, quantities, amounts, Cash Refund existence and shift conditions after locks. Pending rejection must be atomic with audit. Serialize approval and refund with a consistent lock order (paying shift where needed, sale, request/items/inventory) to avoid the current request-before-sale approval order deadlocking with sale-before-request refund checks. Define retry handling for any remaining database deadlock. Two approvals of overlapping pending quantities cannot both consume the same units; simultaneous rejection/refund must observe either the pending block or the committed rejected state. An existing Cash Refund prevents approval; a pending/approved legacy record prevents Cash Refund creation.

1. Rerun read-only inventory on the target; retain counts and anomalies securely. Local empty results do not authorize assuming production is empty. Disable all new legacy creation entry points; preserve review/read access.
2. Present Administrator pending review with before/after balances, settlement/shift/fiscal limitations and explicit Approve or Reject reason. Unsupported records stay pending and report a concrete limitation. No scheduled cleanup or bulk conversion.
3. Implement the narrow approval and atomic rejection rules above, audit and concurrency checks. Preserve existing intentional reporting grains and closed saved reconciliation. Show the original shift accounting effect before approval; never introduce an additional report deduction.
4. Retain approved/rejected history labelled Legacy Reversal, and route new corrections to #113 Cash Refunds. Exchanges require a refund plus a separately paid new sale.
5. Verify the following scenarios on disposable fixtures, including races, before enabling decisions. Operational rollout decisions belong to Administrator; platform recovery remains Super Administrator territory.

## Reproducible verification

On a dedicated read-only local PDO connection (do not call services that mutate or runtime schema helpers), execute:

```sql
START TRANSACTION READ ONLY;
SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone,
       NOW(), UTC_TIMESTAMP();
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('sales','cash_refunds','cashier_shifts',
      'cash_drawer_movements','inventory_adjustments',
      'inventory_adjustment_revisions','sale_reversals')
  AND DATA_TYPE IN ('timestamp','datetime');
SELECT sale_id, sale_date, UNIX_TIMESTAMP(sale_date) AS epoch
FROM sales ORDER BY sale_id DESC LIMIT 3;
SELECT status, reversal_type, settlement_method, COUNT(*) AS records,
       SUM(refund_amount) AS amount
FROM sale_reversals GROUP BY status, reversal_type, settlement_method;
SELECT sr.reversal_id, sr.sale_id, sr.status, sr.reversal_type,
       sr.settlement_method, sr.refund_amount, sr.requested_by,
       sr.approved_by, sr.created_at, sr.approved_at,
       s.payment_method, s.total_amount, s.shift_id,
       cs.status AS shift_status, cs.closed_at, cs.expected_cash
FROM sale_reversals sr JOIN sales s ON s.sale_id=sr.sale_id
LEFT JOIN cashier_shifts cs ON cs.shift_id=s.shift_id
WHERE sr.status IN ('pending','approved') ORDER BY sr.reversal_id;
SELECT reversal_id, sale_item_id, product_id, quantity, unit_price, subtotal
FROM sale_reversal_items ORDER BY reversal_id, sale_item_id;
SET time_zone = '+00:00';
SELECT sale_id, sale_date, UNIX_TIMESTAMP(sale_date) AS epoch
FROM sales ORDER BY sale_id DESC LIMIT 3;
ROLLBACK;
```

Use a dedicated connection because session timezone survives ROLLBACK; close it afterward. If access is unavailable record that explicitly, not as an empty result. On a nonempty target also inspect the linked allocation/stock movements, audit history, refunds and fiscal/shift records per request, without guessing that a recorded amount proves payout.

| Disposable-fixture scenario | Required result |
| --- | --- |
| UTC and +08 reads of one TIMESTAMP; sale 9748 evidence above | Same epoch, same Manila display; no UPDATE or second offset |
| Manila midnight/end boundary, equal-time IDs, mixed history types | Start included, next day excluded; stable chronological order |
| Empty legacy set, rejected-only sale | Empty review message; rejected history remains; otherwise eligible Cash Refund works |
| Pending reject, repeat reject, audit failure | No cash/stock change; one terminal decision/audit; audit failure rolls back state |
| Restockable 2 of 5 units, paid PHP 500, verified cash PHP 200, expected cash PHP 600 in original open paying shift | Stock +2; legacy remaining 3; nominal paid remainder 300; drawer 400; Cash Refund remains blocked |
| Same return by card/e-wallet, externally verified reference | Stock +2, cash unchanged, one audit evidence; no gateway call |
| Zero-value `none` return | Stock rises once; cash unchanged; whole-sale refund ineligibility explained |
| Approved partial/zero-value legacy record and a new Cash Refund attempt | Refusal; no duplicated quantity/value, stock or payout |
| Unsupported exchange settlement, damaged return, invalid amount, missing batch, prior outside payout | No approval or silent repair; explicit investigation limitation |
| Closed/unassigned/different paying shift, or closed original fiscal period | Approval refused; saved expected/count/variance and original receipt unchanged |
| Overlapping pending approvals; approval vs refund; rejection vs refund; repeated request | Shared locks enforce one ledger and remaining caps; audit/state/effects atomic; no duplicate payout |
| Super Administrator without Emergency Access / Cashier direct legacy creation or decision | Server refusal; Administrator decision only; new legacy requests disabled |
| Historical reprint after Store/Register changes | Original saved receipt details and actual transaction time retained under ADR-0006 |

No live record-specific case is unresolved in the inspected empty set. Target deployments with nonempty sets must classify each unsupported case before approval implementation/rollout; lack of payout, batch, or shift evidence is a reason to leave a case pending, not permission to invent its history.
