# Cashier workflow investigation

Investigated 2026-09-30. This records current implementation, confirmed direction, and unresolved decisions. Read alongside `CONTEXT.md`, ADR-0001, ADR-0002, and ADR-0006.

## Evidence limits

Source inspection covered the Cashier workspace and shared Sales Transactions / Sales Reversals page. Browser navigation reached the transactions page's “Completed receipts are locked” notice, then a fresh tab redirected to login with “The database was restored. Please sign in again.” Authenticated UI interaction remains unverified. No sales, refunds, shift changes, migrations, or database test fixtures were created.

## Working sequence

| Stage | Current behavior | Main implementation |
| --- | --- | --- |
| Access | Individual Staff login; Temporary Password gate; active Cashier workspace controls POS access. An Administrator assigned the Cashier role may switch workspace. | `src/backend/app/Authorization/RoleCapabilityPolicy.php`, `RoleWorkspaceRouter.php`, `src/backend/includes/auth.php` |
| Open drawer | Select an available Register and opening cash; one open Cashier Shift per Cashier and per Register. | `src/frontend/components/cashier/shifts.php`, `src/backend/app/Services/CashierShiftService.php` |
| Build cart | Barcode/SKU entry, camera scanning, product search and quick-add; scan changes the cart, not inventory. Checkout validates products, stock, prices, discount and payment server-side. | `src/frontend/components/cashier/pos.php`, `src/backend/app/Services/SalesWorkflowService.php` |
| Hold/resume | Held Sale belongs to its Cashier and originating shift. Resume leaves it unresolved until checkout or reasoned discard; unresolved carts block shift close. | `src/backend/app/Services/HeldSaleService.php`, `CashierShiftService.php:658` |
| Complete sale | Sale, line items, stock/batch depletion, receipt snapshot and held-cart completion commit together; sale retains its shift attribution. | `src/backend/app/Services/SalesWorkflowService.php:100` |
| Receipt | Checkout redirects to shared Sales Transactions detail. View/reprint preserves new transaction-time details; printer width follows the current printer. Printing is manual. | `src/frontend/components/invoice/sales.php:172`, ADR-0006 |
| Refund | Cashier may refund own sales from own open, unlocked shift; partial/full refunds use remaining quantities and paid amount. Original payment method is retained; Restockable returns stock, Damaged does not. Separate Refund Receipt and audit record. | `src/frontend/components/cashier/refunds.php`, `src/backend/app/Services/CashRefundService.php:143` |
| Cash movements / break | Reasoned drawer movements; Register Lock retains ownership. Owner password unlocks; logout does not reconcile or close a shift. | `src/frontend/components/cashier/shifts.php`, `src/backend/app/Services/CashierShiftService.php` |
| Stock Issue | Report, edit/resubmit returned report, or cancel before terminal decision. Inventory Manager decides; approval deducts stock. | `src/frontend/components/cashier/stock_issues.php`, `src/backend/app/Services/StockIssueService.php` |
| History / dashboard | My History scopes sales, refunds, drawer movements and shifts to the authenticated Cashier. Stock Issue history is on its reporting page. Shared Sales page offers own receipt history; reporting-capable workspaces see Store history. | `src/frontend/components/cashier/history.php`, `dashboard.php`, `src/backend/app/Services/CashierOperationalHistoryService.php`, `src/frontend/components/invoice/sales.php:72` |
| Close | Resolve Held Sales, count cash, compare expected cash, supply a reason for material variance. Administrator intervention can close another Cashier's abandoned shift with a reason. | `src/backend/app/Services/CashierShiftService.php:628` |
| Profile | Update name, email and profile image; password settings provide Voluntary Password Change. | `src/frontend/components/auth/user_info.php:44` |

Manual discounts above 10% require supervisor credentials; a larger automatic promotion takes precedence (`SalesWorkflowService.php:312`). Checkout uses first-expiring batch allocation (`SalesWorkflowService.php:434`).

## Findings needing attention

### 1. Completed checkout can be reported as failed

`SalesWorkflowService.php:116` commits before low-stock and expiry notification calls, which remain inside the same catch/rethrow boundary. If either call throws, `pos.php:107` displays “The sale could not finish. Please try again” although the sale already exists. Retrying can create a second sale. This is a code-path risk, not a reproduced duplicate. Preserve successful checkout response after commit and report notification failure separately; evaluate retry protection as part of the fix.

### 2. Holding a cart is not serialized with closing or locking its shift

`HeldSaleService.php:121` reads its shift before rehydrating and inserting at line 136, without a surrounding transaction holding the shift row. `CashierShiftService.php:648` locks the shift and checks unresolved Held Sales before closing. A concurrent hold can pass its initial check, then insert after closure or lock. Serialize these operations under the same shift lock. Runtime concurrency reproduction remains outstanding.

### 3. Two correction workflows create different accountability

`sales.php:107` allows an own-sale reversal request without an open/unlocked shift guard. Its form at line 358 offers refund, exchange, return and complete cancellation, alongside free-entered refund amounts and settlement methods. Approval in `SaleReversalService.php:267` restores every returned quantity to inventory without Restockable/Damaged classification. The request stores `max(0, refundAmount)` at line 154 without capping it against the amount paid.

`CashierShiftService.php:551` deducts approved legacy cash reversals from the original sale's shift. By comparison, Cash Refunds deduct from the issuing shift at line 568. A later reversal therefore affects calculation for an old drawer rather than identifying the drawer paying now. Closed shift reports retain saved expected cash while some totals are recalculated, so inspect reporting consequences before changing this path.

The ledgers deliberately cannot mix: `CashRefundService.php:177` blocks pending/approved legacy reversals; `SaleReversalService.php:361` blocks sales with Cash Refunds. Their coexistence is a policy decision requiring clarification, not grounds to silently delete historical records.

### 4. Stock Issue authorization conflicts with active-workspace access

`StockIssueService.php:49` invokes `assertRole`; lines 1089 and 1132 resolve the account's stored primary role. An Administrator assigned the Cashier workspace can sell but fails Cashier-only Stock Issue operations. This conflicts with the glossary's active-workspace model. Apply the same explicit workspace authorization model at service boundaries.

### 5. Transactions filters and actions are inconsistent with refunds

`ReceiptTableService.php:66` includes Cash Refund amount/count, and `sales.php` displays those alongside reversal badges. The “No reversal” filter (`ReceiptTableService.php:207`) checks only legacy reversal records, so a refunded sale can still match it. The per-row action routes to legacy Reverse rather than the Cash Refund workflow. Decide whether the view should distinguish these concepts explicitly or offer one correction-status model.

Other smaller issues: the shared receipt renderer always shows “Payment completed” even on historical views (`sales.php:55`); “New Sale” depends on being any role other than Inventory Manager rather than POS capability (`sales.php:22`); the informational blocking dialog runs on every table page load. These create misleading or repetitive navigation.

### 6. Ordinary cart storage does not identify its Cashier or shift

`pos.php:1293` saves the cart under a generic `sessionStorage['pos_cart']` key and restores it at line 1299 without owner/shift metadata. Logout destroys the server session but leaves tab storage. Another Cashier signing in through the same tab can inherit the ordinary cart. Server-side Held Sale ownership remains separately enforced. Scope saved ordinary carts to their owner and define when a workspace/shift change clears them.

Holding also silently drops unavailable/inactive products and clamps requested quantity to current stock (`HeldSaleService.php:454`), without returning a change report. Decide whether the Cashier must confirm those adjustments.

## Confirmed direction

User answered yes to all three on 2026-09-30:

1. New Cashier refunds use Cash Refunds exclusively; existing Sales Reversals remain reviewable. Exchange/cancellation and pending legacy requests still need explicit treatment.
2. Sales Transactions remains customer receipt lookup/reprint; My History remains the Cashier's operational record.
3. Prioritize checkout reliability, shift concurrency, and workspace authorization before UI polish.

Receipt History and Cashier Operational History are now defined in `CONTEXT.md`. No new ADR yet: the legacy transition and remaining correction policies are unresolved. These confirmations establish direction, not completion of the clarification interview.

## Remaining confusing business rules

These are decision questions, distinct from the implementation defects above. Recommendations are proposals, not accepted policy. Existing glossary/ADR boundaries stay in force until explicitly changed.

| Question | Current behavior / ambiguity | Recommended direction |
| --- | --- | --- |
| Q4: Exchange and cancellation | Legacy form includes exchange, store credit and full cancellation. New Cash Refund policy does not define their replacements. | Before payment, remove/discard cart items; after payment, record full/partial refund. Exchange is a refund plus a separately paid new sale; no store credit initially. |
| Q5: Pending legacy requests | Pending/approved reversals block Cash Refunds (`CashRefundService.php:177`); existing requests need a transition. | Preserve records; Administrator resolves pending requests through legacy process. Never silently convert to a refund or pay twice. No new legacy requests. |
| Q6: Refund owner | Only the original Cashier can refund (`CashRefundService.php:439`, `:457`). An absent/Disabled Account leaves no operational route. | Keep routine own-sale refunds; design an explicit Administrator-authorized exception with issuing Cashier, approver and current shift attribution. This would revise the existing Cash Refund glossary rule. |
| Q7: Closed Fiscal Period | Original sale date must remain in an open sales period (`CashRefundService.php:162`), even for a new refund today. | Permit today's append-only refund when today's required periods are open; keep original sale unchanged. Explicitly revise current policy if accepted. |
| Q8: External settlement | Checkout requires a nonempty noncash reference (`SalesWorkflowService.php:434`); refund commits without provider action/reference/status (`CashRefundService.php:186`). | Cashier confirms external success before recording; require refund reference for noncash and state clearly that RetailMind records, rather than performs, payment/refund. |
| Q9: Price and promotion changes | Browser review uses stored cart prices/manual discount (`pos.php:1074`, `:1112`); checkout uses current prices and promotion (`SalesWorkflowService.php:181`, `:95`). | Present a server-calculated final total before collecting payment; invalidate/reconfirm if it changes. Held carts do not guarantee prices. |
| Q10: Changed held-cart stock | Hold clips quantity and drops inactive/unavailable items (`HeldSaleService.php:454`), silently. | Show changed lines and require Cashier confirmation; held carts do not reserve inventory. |
| Q11: Ordinary cart lifecycle | Generic per-tab cart survives logout and has no owner/shift metadata (`pos.php:1293`). | Same Cashier/same open shift may recover after reload; another Cashier/shift starts empty. Preserve work through explicit Held Sale before logout/workspace switch. |
| Q12: Register Lock scope | Blocks sale/refund/drawer movement; history remains readable. Stock Issue operations do not check shift lock (`StockIssueService.php:1035`). | Block operational writes while locked; permit own history and receipt reprint. Owner alone unlocks. This scope needs an explicit UI explanation. |
| Q13: Cash sufficiency | Positive movement/refund validation has no drawer-balance cap (`CashierShiftService.php:464`, `CashRefundService.php:617`). | Block cash payouts above expected cash; record a real top-up first. Expected cash is a ledger estimate, not proof of physical contents. |
| Q14: Drawer spending | Cash out permits Supplier payment, Petty cash and Other; Other note may be blank (`CashierShiftService.php:51`, `:473`). | Cashier handles float/safe drops; operational spending requires Administrator authorization; Other requires a note. Do not record customer refunds a second time as drawer movements. |
| Q15: Discount authority | Manual amount above 10% requires admin or super_admin credentials; approval happens before choosing the winning promotion (`SalesWorkflowService.php:95`, `:345`, `:423`). | Administrator approves routine manual discounts above 10%; best single discount wins, no stacking; approval only for applied manual discount. Keep 10% initially. Super Administrator routine approval conflicts with ADR-0001. |
| Q16: Damaged-return ownership | Damaged refund never restores available stock (`CashRefundService.php:201`); approved Stock Issue deducts available stock (`StockIssueService.php:527`). | Damaged customer returns stay in refund records; Stock Issues concern units currently included in available stock. Never report the same damaged-return unit to deduct it again. Separate custody/disposal workflow only if needed. |

The own-history model includes sales, refunds, drawer movements and shifts (`CashierOperationalHistoryService.php:17`); Stock Issues retain their own history. Receipt screens should distinguish original paid amount, refunded amount and remaining net amount without rewriting the original receipt. Legacy records should be labelled separately from new refund statuses.

## Second-round decisions — 2026-10-01

User accepted the recommendations for Q4–Q6 and Q8–Q16. Q7 (refunds of sales from closed Fiscal Periods) was not answered and remains open.

- Keep refunds simple: full/partial refunds only; exchanges use a refund plus a new sale. No store credit, credit scores, loyalty points, or rewards feature.
- Preserve existing legacy reversal records and resolve existing pending requests; prevent new legacy requests.
- Permit an explicitly Administrator-authorized refund exception when the original Cashier is absent or disabled, retaining seller, issuing Cashier, approver and paying-shift attribution.
- Card/e-wallet transactions are externally completed and verified, then recorded with a reference. No payment gateway integration.
- Review the server-calculated final price before collecting payment; reconfirm changes. Held carts do not guarantee price or reserve stock; explicitly confirm changed quantities/products.
- Recover ordinary carts only for the same Cashier and shift; use Held Sale to preserve work before logout/workspace change.
- Register Lock blocks operational writes, while history and receipt reprints remain available.
- Block cash payouts above expected drawer cash; record real top-ups first.
- Require Administrator approval for supplier/petty-cash spending and explanation for Other. Never double-record refunds as drawer movements.
- Best single discount wins without stacking; only the applied manual discount needs approval above 10%, from the Administrator.
- Damaged customer returns belong in refund records and do not create another inventory deduction through a Stock Issue.

The accepted exception revises the Cash Refund glossary rule; Payment Reference is now defined. Implementation still needs the remaining policy answers and final shared-understanding confirmation.

### Post-checkout receipt notice

The user's reported “Completed receipts are locked” popup comes from the unconditional `Swal.fire` call in `src/frontend/components/invoice/sales.php:499`, after the receipt-table existence check. Checkout redirects to that same page with a receipt and the history table, so the generic reminder runs after a successful checkout too. It reports receipt immutability, not a Register Lock or failed payment. Proposed presentation: success/receipt/change with Print Receipt and New Sale after checkout; explain refund corrections contextually rather than showing a mandatory popup on every visit. This popup has not yet been removed.

## Final clarification and specification

Q7 was confirmed: allow a refund recorded today for a sale from a closed historical Fiscal Period, when today's required periods are open, preserving the original sale. The user confirmed `Oct 1, 2026 · 8:39 AM` in Philippine time for receipts/history, and the unified centered receipt modal described in the conversation. No incorrect stored hour was established.

The user requested discussion/specification only, with no application coding. The agreed direction is consolidated in [Cashier workflow clarity and reliable receipts](../specs/cashier-workflow-clarity.md). The earlier unanswered-Q7 note above reflects its status at that earlier interview round; Q7 is now settled. Application changes and runtime verification are still pending.
