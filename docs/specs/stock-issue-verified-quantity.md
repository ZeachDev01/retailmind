# Stock Issue verified quantity

Active workflow update — 2026-11-25: [Simplified Stock Issues](simplified-stock-issues.md) replaces active chat and adds direct Manager recording. Original report and verified quantities remain distinct; the implementation history below describes the earlier workflow.

Status: User confirmed the complete workflow and authorized implementation on 2026-11-25. Implemented; verification results below.

## Confirmed decisions

- An Inventory Manager may approve the verified affected quantity directly, without sending the report back to the Cashier solely to correct its quantity.
- Example: the Cashier reports 5 damaged pieces, but physical inspection confirms only 2. Approval deducts exactly 2 and closes the issue as approved; the remaining 3 are not left pending.
- A changed quantity requires a reason. History retains the original reported quantity, the verified quantity, and the manager who made the decision.
- Reported quantity and Verified Stock Issue Quantity are distinct concepts.
- The verified quantity may exceed the reported quantity when the manager discovers additional affected units, provided a reason is recorded and sufficient stock exists in the selected batches. Example: reported 5, verified 7, approved deduction 7.
- When inspection confirms zero affected units, the manager rejects the report with a reason. Rejection closes the issue without deducting stock.
- Batch deductions must total the verified quantity on approval, rather than the original reported quantity. Existing stock availability checks remain required.

## Previous behavior

- Approval deducted the saved reported quantity. Batch allocations had to total that quantity.
- A manager returned a quantity discrepancy for correction. The reporting Cashier saved the edited report, then resubmitted it before approval.

## Implementation

- The manager enters Verified quantity in the report's existing review form. Editing it updates the suggested batch deductions in FEFO order; editing batch deductions updates the verified total. The approval button names the quantity and stock form to deduct.
- Approval validates the quantity, changed-quantity reason, selected batches, available stock, and existing role and Fiscal Period rules on the server. Zero is refused for approval; Reject remains the explicit way to close a report with no affected units.
- Approval keeps `reported_qty` as the original report quantity and records the actual base-unit deduction in `adjustment_qty`. Pack size remains a snapshot. Legacy rows missing the quantity snapshot receive their original quantity before approval.
- The existing append-only revision and activity records include reported and verified quantities, the review reason, and the manager. The linked movement and batch attribution record the actual deduction. Repeated submissions cannot deduct twice.
- Manager, Cashier and Administrator histories display actual deductions alongside the original reported quantity. Cashier approval notifications also identify both quantities. No schema migration or new dependency is required.

## Verification

- `php src/backend/tests/stock_issue_batch_deduction_test.php` passed, including smaller/larger verified quantities, required reason, invalid values, stock and batch refusal, legacy quantity preservation, duplicate/idempotent submissions, audit attribution, zero-damage rejection, and pack quantity formatting.
- `RUN_DB_TESTS=1 node src/backend/tests/stock_issue_review_layout_browser_test.js` passed against a disposable MySQL database, including real authenticated approvals and rejection, piece and pack conversions, batch/verified-field synchronization, Manager/Cashier/Administrator history, and responsive queue layout. Desktop/mobile screenshots were inspected.
- `npm run check` and `npm run check:contracts` passed. The view audit reports the existing duplicate `open-register` ID warning in Cashier Shifts.
- Three older standalone Stock Issue contracts remain blocked by their fixtures: `stock_issue_contract.php` and `stock_issue_correction_contract.php` have no open Fiscal Period; `stock_issue_oversight_contract.php` has no `product_batches` table. The same failures were reproduced using the pre-change `StockIssueService.php` from HEAD. Those fixture repairs are outside this change.
- TestSprite's focused history/rejection check passed all 19 steps: [execution 8e9baf50-1d0d-457d-a612-8829c4855768](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/17563eb6-1905-42ec-996f-9f33e0e1238d/test/8e9baf50-1d0d-457d-a612-8829c4855768). It verified the original 5 alongside actual deductions of 2 and 7, required review reasons, disabled zero-quantity approval, and explicit rejection with unchanged inventory.
- The initial combined TestSprite run `6b36525d-1ad1-4bae-a2ca-729c43c1a6ad` failed: its runner selected Return for correction instead of Reject and failed its history assertions. Inspection of its HTML evidence confirmed the two correct approved deductions and original quantities were present. The synthetic returned report was resubmitted through the existing service, then the focused check explicitly opened Recent decisions and selected Reject. Responsive coverage comes from local Playwright, not TestSprite's fixed viewport.
- Verification used disposable Stores only; no live Store records were modified.
- The disposable TestSprite database was removed after verification. Automatic approval review rejected stopping the temporary fixture server with `blocked by policy`; the local test process on port 64553 and its temporary recovery directory may remain. The server uses only the removed disposable database, not the live Store.

## Review views follow-up â€” 2026-11-25

The user requested separate Pending review, Returned to Cashier, and Recent decisions views, removal of the returned/recent accordions beneath the pending queue, and clearer empty states.

The old overview cards were fragment links to collapsed sections on one page. They now navigate to validated `issue_view=pending|returned|decisions` URLs with `aria-current="page"` marking the active link. Only the selected section is rendered, so returned reports and recent decisions no longer sit below Pending review. Direct URLs, refresh, browser history, and navigation without JavaScript work without relying on an accordion-opening handler. Pending pagination retains its view; unknown view values fall back to Pending review.

All three views use a consistent empty panel with a relevant icon, a clear heading, and one short explanation. The active view uses the existing blue action tokens in light and dark themes. Existing report review actions, history, and stock rules are preserved.

The browser regression first failed with `Recent decisions must navigate to its own view` (actual query value `null`). The expanded `stock_issue_review_layout_browser_test.js` now passes mouse/keyboard navigation, refresh, Back/Forward, JavaScript-disabled navigation, empty and populated destinations, pending pagination, invalid-view fallback, and existing piece/pack approval/history checks. Empty states were measured and captured at 390, 768 and 1440 pixels in both themes. `npm run check` and `npm run check:contracts` passed; the existing Cashier Shifts duplicate-ID warning remains.

TestSprite navigation verification passed all 15 steps: [execution 2ddc77fd-0c92-4f79-8a80-b4790007fc4b](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/f8d1d683-17b5-42d7-8113-0804fde3e17f/test/2ddc77fd-0c92-4f79-8a80-b4790007fc4b). The run used read-only navigation in a separate disposable Store, which was cleaned up afterward. No live inventory was changed.
