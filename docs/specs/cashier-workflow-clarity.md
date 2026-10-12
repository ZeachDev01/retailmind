# Cashier workflow clarity and reliable receipts

Status: agreed product direction, ready for specification review. Application implementation has not started.

Confirmed through the Cashier workflow clarification conversation ending 2026-10-01. Evidence and current-code findings are in [the investigation](../research/cashier-workflow-investigation.md). Use the domain vocabulary in [CONTEXT.md](../CONTEXT.md) and preserve ADR-0001's Administrator boundaries and ADR-0006's transaction-time receipt details.

## Purpose

A Cashier must be able to complete a sale once, see its receipt immediately, return eligible items through one simple refund workflow, and reconcile the drawer that actually handled the money. Remove conflicting correction paths, unexplained cart changes, misleading success/error messages, and the disjointed post-checkout receipt presentation.

## Agreed scope

- Reliability first: checkout outcome, retries, shift concurrency, and active-workspace authorization.
- One workflow for new full/partial refunds. Existing Sales Reversal records retain their history and existing pending requests receive an explicit resolution.
- Sales Transactions is Receipt History for customer receipt lookup/reprint. My History is Cashier Operational History for sales, refunds, drawer movements and shifts. Stock Issues retain their own report history.
- A coherent receipt modal after checkout and for historical receipt viewing.
- Clear Philippine date/time display.
- No application changes during this specification phase.

## Access, shifts and Register Lock

1. Use the authenticated identity and active workspace consistently. An Administrator assigned the Cashier workspace can perform the same Cashier operations, including Stock Issue reporting, under their own attribution.
2. Keep one open Cashier Shift per Cashier and one per Register. Opening records the Register and actual opening float.
3. Sales, refunds, Held Sale changes, drawer movements and Stock Issue changes require the appropriate own open, unlocked shift. Check authorization at the server boundary; disabling a control is insufficient.
4. Register Lock retains shift ownership and blocks operational writes and cart changes. Own history and receipt reprints remain available. Only the owner unlocks with their account password.
5. Logout does not close or reconcile a shift. Closure requires resolving all Held Sales and counting cash. Existing material-variance explanations and reasoned Administrator intervention remain.
6. Serialize hold/resume/discard, checkout, lock and closure where they compete over shift state. A concurrent hold cannot insert an unresolved cart after the shift closes or locks.

## Cart, prices and discounts

1. An ordinary cart may recover after reload only for the same Cashier and open shift. Another identity or shift starts empty.
2. Preserve unfinished work through an explicit Held Sale before logout/workspace switching. Prevent silent loss: offer a hold/discard decision when work needs resolution. A held cart retains its existing owner and originating shift rules.
3. Held Sales reserve neither stock nor price. Revalidate availability and price when holding/resuming and before checkout; show changed products, quantities and prices and obtain explicit review rather than silently clipping or dropping lines.
4. Show a server-calculated final quote, including the selected discount/promotion, before payment is collected. If relevant inputs change before saving, invalidate the quote and require another review rather than recording an unconfirmed amount.
5. Apply the best single eligible discount: no stacking. Retain the current 10% threshold for manual discounts. Only an applied manual discount above that threshold needs approval; a manual discount displaced by a better automatic promotion does not.
6. Routine manual-discount approval belongs to the Administrator. Super Administrator authority does not bypass the existing Emergency Access boundary.

## Payment and checkout outcome

1. Support cash, card and e-wallet recording. Card/e-wallet payment is completed and verified externally before the Cashier records it with a Payment Reference. RetailMind does not initiate or verify payment through a gateway.
2. Cash received must cover the confirmed total; calculate change from that total. Only cash payments affect expected drawer cash.
3. Sale, inventory/batch depletion, receipt snapshot and any Held Sale completion succeed together or fail together.
4. Once committed, the sale remains successful even if a notification fails. Log/report that ancillary failure separately and return the saved receipt.
5. Retrying the same checkout attempt must retrieve its saved result rather than create a duplicate. Distinguish a genuinely new sale from a retry; recover the committed outcome after a lost response before prompting another payment.
6. Reopening, refreshing, closing or printing a receipt never creates a financial transaction.

## Simple refunds and historical corrections

1. Before payment, remove cart lines or discard the cart/Held Sale with the required reason. After payment, preserve the original sale and create a full/partial Cash Refund.
2. An exchange is a refund plus a separate new sale. No store credit, credit score, loyalty points, rewards balance, or exchange-credit ledger.
3. Routine own-sale refunds retain predefined reasons, a note for Other, remaining-quantity limits and a cap at the amount actually paid after discount. Derive amounts server-side and enforce limits under concurrent attempts.
4. When the original Cashier is absent or disabled, permit an explicit Administrator-authorized exception. Record original seller, issuing Cashier, approver, reason and the issuing Cashier's current open/unlocked shift. Bind approval to the specific refund; it is not general permission to access other Cashiers' sales.
5. Keep routine identity-scoped history and receipt access. The authorized exception exposes only the sale needed for that refund; it does not grant Store-wide history.
6. Settle on the original payment method. For card/e-wallet refunds, require confirmation of externally completed refund and its reference before recording. The UI and Refund Receipt must not imply RetailMind transferred the money.
7. A sale from a closed Fiscal Period may be refunded today if today's refund and applicable stock-movement periods are open. Record the refund today and leave the original sale and historical period unchanged. Do not reopen a closed period merely to refund its sale.
8. Only cash refunds reduce expected cash, and they reduce the issuing shift's drawer, regardless of when or where the original sale occurred.
9. Every returned quantity is Restockable or Damaged. Only Restockable quantity returns to available inventory. Damaged returns consume refundable quantity/value but must not also generate a Stock Issue deduction for the same unit.
10. Stock Issues concern units currently counted in available inventory. Preserve Inventory Manager review and immutable terminal decisions; do not introduce a disposal/quarantine workflow in this scope.
11. Preserve legacy reversal records and resolve existing pending requests through an Administrator-controlled transition. Disable creation of new legacy reversal requests on all reachable entry points, not just the Cashier navigation.
12. Do not silently convert legacy records, duplicate payouts, or allow both correction ledgers to spend the same quantity/value. Before implementing the transition, inspect existing pending records and their cash/stock consequences; document how each supported resolution preserves balances. Historical records remain readable and clearly labelled Legacy Reversal.

## Drawer movements and cash sufficiency

1. Keep reasoned float additions, cash removals and safe drops with identity/shift attribution.
2. Block cash refunds, withdrawals and safe drops that exceed current expected drawer cash. Record a real top-up first. Expected cash is a ledger estimate; the Cashier must still check physical funds.
3. Supplier payments and petty-cash spending require Administrator authorization. Other requires an explanation.
4. A customer refund is recorded through Cash Refunds, never again as a drawer movement. Movement labels and instructions must make this distinction clear.
5. Maintain reconciled expected cash as opening float + cash sales + cash additions − cash removals − safe drops − applicable cash refunds, with no duplicate legacy deductions.

## Receipt modal and history presentation

1. After successful checkout, open one centered, labelled Sale Receipt modal. Keep Receipt History behind it; do not render a second inline receipt above history.
2. Within that same modal, show Payment completed, saved receipt number, change due, thermal receipt preview, Print Receipt and New Sale. Keep its header/actions and paper preview visually together.
3. Remove the automatic Completed receipts are locked popup from page initialization. Explain corrections beside relevant refund actions instead.
4. Closing the modal returns to Receipt History. New Sale opens a fresh POS cart. History's View opens the same modal using saved receipt data, without showing a new-payment success announcement.
5. Keep the original saved receipt immutable. History may separately show original paid amount, refunded amount and remaining net amount. Distinguish refund statuses from Legacy Reversal statuses so a refunded sale cannot misleadingly appear uncorrected.
6. Offer Refund to the authorized Cashier workflow instead of sending new corrections to Reverse. Access and eligibility remain server-enforced.
7. Keep Print Receipt manual through the browser dialog; canceling print changes no transaction state. Preserve 58/80 mm widths, long-content wrapping, transaction-time details, and the existing historical notice for older unsnapshotted receipts.
8. Keyboard focus enters the modal, stays within it while open, and returns to its trigger when closed. Escape and the visible close control dismiss it. Support narrow screens, long receipts and scrolling without horizontal clipping; ensure receipt paper/actions remain reachable.
9. Refund Receipt remains a separate saved customer document, uses consistent date/time formatting and printing, and identifies the original sale. Do not merge it into or rewrite the original Sale Receipt.

## Date and time

- Display receipt, Receipt History and relevant Cashier workflow timestamps as `Oct 1, 2026 · 8:39 AM`: abbreviated month, day, four-digit year and 12-hour time with AM/PM; omit seconds on screen and on customer receipts.
- Use `Asia/Manila` and make Philippine time clear in the surrounding UI. Format the actual stored instant; do not replace a sale's timestamp with the time of viewing/reprinting.
- Before implementation, establish how existing PHP/database timestamps are stored. Do not blindly add eight hours or rewrite historical timestamps. If existing values are already Manila local time, format them without shifting.
- Sort/filter using the underlying timestamp rather than the formatted display string. Date filters use Philippine calendar-day boundaries.
- The user confirmed the display format; no incorrect underlying hour was established in the screenshots.

## Acceptance scenarios

| Scenario | Required result |
| --- | --- |
| Successful checkout | One sale and one coherent receipt modal; no locked-receipt popup or second inline preview. |
| Notification fails after commit | Checkout still succeeds and offers its saved receipt. |
| Double-click/retry/lost checkout response | Same checkout attempt returns the same sale; no second sale or inventory deduction. |
| Historical View or reprint | Same receipt presentation, saved transaction details/time, no Payment completed announcement. |
| Print canceled or modal closed | Sale stays saved; no payout or repeat checkout. |
| Desktop/mobile, long receipt, both paper widths | Usable modal and unclipped preview/print; application controls do not print. |
| Price/promotion/stock changes | Cashier sees the recalculated quote or changed lines and explicitly confirms before payment/save. |
| Hold races close/lock | No new unresolved Held Sale appears after closure/lock; no unauthorized operation commits. |
| Identity/workspace/shift changes | No ordinary-cart leakage; assigned Administrator in Cashier workspace can report Stock Issues. |
| Locked Register | Operational writes fail server-side; authorized viewing/reprinting still works. |
| Partial discounted refund / concurrent requests | Remaining quantities and paid-value caps hold; no over-refund. |
| Absent/disabled original Cashier | Authorized exception records issuing Cashier, Administrator and current paying shift. |
| Original sale period closed, today's required periods open | Refund posts today; original sale/closed period is preserved. |
| Today's required period closed | Refund is refused without partial cash/inventory records. |
| Card/e-wallet refund | External completion and reference required; no gateway call or drawer-cash deduction. |
| Cash refund/top-up/safe drop | Insufficient cash blocks payout; actual recorded top-up permits a valid payout once. |
| Damaged return | Refund records damage; available stock is neither restored nor deducted a second time. |
| Legacy pending/approved record | History retained, explicit resolution, no duplicate quantity/value consumption or payout. |
| Manual vs automatic discount | Best single discount; approval only for applied manual discount above 10%, from Administrator. |
| Date display / ordering / day boundaries | Manila format is consistent; chronological ordering and filtering remain correct. |

## Implementation sequence

1. Confirm timestamp storage and legacy pending-record transition; establish meaningful checkout/concurrency/authorization regression coverage.
2. Fix checkout outcomes/retry handling, shift serialization and active-workspace authorization.
3. Implement reviewed quotes, cart lifecycle, lock scope, cash sufficiency and bound Administrator approvals.
4. Consolidate new refund entry points, current-period refunds and external references, preserving legacy history and accounting.
5. Unify receipt modal and date display, then verify receipt history/refund navigation and desktop/mobile/print behavior.

## Review boundary

This spec records the accepted product rules. It does not claim the existing application already satisfies them or authorize starting implementation before the user requests it. Backend authorization, settlement/reference validation, retry handling and concurrency must be verified through observable behavior, rather than only inspecting disabled controls or matching source text.
