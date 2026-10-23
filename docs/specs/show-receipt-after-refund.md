# Show Refund Receipt after a completed refund

## Problem Statement

After completing a Cash Refund, the Cashier needs to see the customer's Refund Receipt immediately, without searching refund history. The Cashier must be able to review and print it without opening the print dialog automatically or risking another refund.

## Solution

Immediately show the saved Refund Receipt in a modal matching POS checkout after a successful Cash Refund. Keep the original Sale Receipt unchanged. Provide a Print Refund Receipt button that opens the browser print dialog only when clicked. Retain the existing receipt fields, thermal preview, and configured paper width.

## User Stories

1. As a Cashier, I want to see the Refund Receipt immediately after a successful refund, so that I know which refund was completed.
2. As a Cashier, I want the receipt to appear without searching history, so that I can finish serving the customer.
3. As a customer, I want a separate Refund Receipt, so that I have proof of the refund.
4. As a customer, I want the original Sale Receipt preserved, so that my purchase record remains unchanged.
5. As a Cashier, I want the receipt to identify the refund and original sale, so that I can match the refund to the purchase.
6. As a customer, I want the receipt to show only items and quantities returned in this refund, so that it accurately describes this return.
7. As a customer, I want the amount for this refund shown clearly, so that I can check the money refunded.
8. As a Cashier, I want each later refund against the same sale to have its own receipt, so that separate refunds remain distinguishable.
9. As a Cashier, I want the existing payment, date and time, Cashier, Register, and reason fields retained, so that the receipt remains complete.
10. As a Cashier, I want the existing inline thermal preview retained, so that I can review the customer copy before printing.
11. As a Cashier, I want the receipt to use the configured paper width, so that it fits the available printer.
12. As a Cashier, I want printing to start only when I click Print Refund Receipt, so that I control when the browser dialog opens.
13. As a Cashier, I want to select my installed printer through the browser dialog, so that I can use the existing printing setup.
14. As a Cashier, I want canceling printing to leave the refund completed and receipt visible, so that printing cannot undo the transaction.
15. As a Cashier, I want the Print Refund Receipt button available after canceling, so that I can retry without recording another refund.
16. As a Cashier, I want refreshing the completed receipt to avoid another refund or automatic print dialog, so that refresh is safe.
17. As a Cashier, I want to reopen my saved Refund Receipt from history, so that I can provide another copy later.
18. As a customer, I want later reprints to retain the recorded receipt details, so that subsequent Store or product edits do not change my refund record.
19. As a Cashier, I want existing receipt access restrictions preserved, so that unauthorized accounts cannot retrieve customer receipts.
20. As a Cashier, I want a failed refund to show its existing error rather than a completed receipt, so that I do not mistake a failed attempt for a successful refund.

## Implementation Decisions

- Reuse the existing Cash Refund completion flow, saved Refund Receipt retrieval, receipt presentation, and shared thermal print behavior. Prefer fixing an observed gap over introducing another receipt system.
- A successful refund already redirects to a GET for its saved receipt and renders an inline preview. Verify this behavior through the real completion flow; do not assume a new screen or module is needed.
- Show the receipt only after the refund transaction succeeds. A failed refund retains existing validation and error handling.
- Preserve the original Sale Receipt and append-only Cash Refund accounting. Each Refund Receipt represents exactly one refund and references the original sale.
- Keep Print Refund Receipt as an explicit action. Do not open the print dialog automatically after completion, refresh, or a history visit.
- Canceling or retrying printing must not repeat the refund. Refreshes and reprints retrieve an existing receipt without creating another transaction.
- Preserve existing receipt fields, exclusion of internal notes and stock classifications, saved transaction-time details, and current 80 mm or 58 mm paper-width behavior.
- Preserve Cashier, Store, Cashier Shift, Register lock, and receipt authorization rules.
- Reuse the POS checkout receipt dialog styling and behavior, including keyboard focus, Close, and Escape. Closing removes the saved receipt selection so refreshing keeps it dismissed; history can reopen it. No new schema, public API, dependency, or separate browser tab is required. Remain consistent with ADR-0006.

## Testing Decisions

- The user confirmed the existing refund completion flow as the primary verification seam: submit a valid refund, follow completion to its receipt, then exercise manual printing, canceling, and reopening.
- Assert observable behavior rather than source strings or private helper structure: the correct receipt is visible after success, its refund and original sale identifiers match, its items and amount cover only this refund, and the original sale remains unchanged.
- Cover both full and partial refunds; for multiple refunds against one sale, verify that each receipt contains only its own refund.
- Use existing Cash Refund contract and Refund Receipt browser/reprint coverage as prior art. Reuse their synthetic fixtures and isolated test databases; do not create refunds against operational data.
- Verify that completion, refresh, and reopening do not invoke printing automatically; clicking Print invokes the browser print action.
- Verify that canceling leaves the completed receipt visible and permits retry, and that printing, refresh, and reopening create no additional financial writes.
- Retain existing authorization, saved-detail stability, internal-field exclusion, and 80 mm/58 mm checks. Extend tests only where the completion path lacks coverage.
- Check the existing error path does not present a successful receipt for a failed refund.

## Out of Scope

- Automatic or silent printing, direct printer integration, printer purchase, and device-specific feed or cutter controls.
- Replacement or edited Sale Receipts, cumulative refund summaries, or retained-item receipts.
- Receipt paper redesign, new fields, new navigation, or opening another browser tab.
- Changes to refund eligibility, approval, settlement, inventory, or accounting rules.
- Changes to Sale Receipt behavior after checkout.

## Further Notes

The final requirement is to show the receipt automatically, not print automatically. This supersedes the earlier interview answer requesting an automatic print dialog.

Code inspection indicates that the existing implementation already redirects a completed refund to its saved inline Refund Receipt. End-to-end runtime behavior has been verified through the production completion flow, with one repair to the persistent-shell navigation described below.

## Modal update — 2026-10-23

The latest requirement replaces the inline receipt surface with a modal matching POS checkout. The existing completion GET and saved receipt remain unchanged. The refund page now uses the shared receipt dialog script and styles, with focus returning to receipt lookup after dismissal. Dialog initialization precedes the print script so a failed print-script load still leaves the saved receipt visible.

The modal also reuses checkout's success banner and print-button treatment: “Refund completed”, the saved Refund Receipt number, and “Amount refunded” replace payment/change messaging. The banner is screen-only; the customer receipt paper remains unchanged. The completion browser test verifies the banner and amount for full and partial refunds.

`refund_completion_browser_test.js` covers the real completion flow for full and partial refunds at both paper widths, modal focus, Close/Escape, refresh after dismissal, manual print retry, history reopening, and desktop/mobile sizing. The original inline verification below records the earlier behavior.

## Verification — 2026-10-22

The first runtime report was “no receipt after a refund” even though the server logged the `303` and receipt `200`. The persistent shell committed the receipt correctly, but reset the scroll position only after optional page scripts finished. When a receipt script failed or hung, the swapped-in receipt stayed above the viewport and the operator still saw the refund page, so the completion looked like it had not redirected.

`src/frontend/assets/js/page-navigation.js` now reveals the committed page (instant `scrollTo(0, 0)`) immediately after replacing the content and before page scripts run. `src/backend/tests/refund_completion_browser_test.js` therefore scrolls the refund form to its bottom before submitting and proves the failure path:

- A fourth refund submits with `sale-receipt.js` aborted. The navigation error is reported, yet `window.scrollY` is `0` and the completed receipt is inside the viewport. With the previous scroll timing this assertion fails (`scrollY` 931, receipt above the viewport).

`src/backend/tests/refund_completion_browser_test.js` drives the real Cash Refund handler against a disposable database for both 80 mm and 58 mm Register widths:

- A valid full refund and two partial refunds each submit through the confirmation dialog, redirect to a GET for a distinct saved Refund Receipt, and render the inline thermal preview without a history search. The completed receipt is scrolled into view after submit.
- Each receipt shows its own refund and original sale identifiers, only that refund's product, quantity, and amount, plus the existing Date/Time, Cashier, Register, Reason, payment method, and configured paper width. The internal note, kept item, and Damaged classification are absent.
- The original Sale Receipt, sale lines, saved sale details, stock movements, inventory, and product batches remain unchanged by completion, printing, refreshes, and history visits. Only the four refunds append financial rows and audit records.
- Completion, two Print Refund Receipt attempts, refresh, and each history reopening never invoke `window.print`; clicking Print invokes it exactly once per click, uses the configured paper width, and leaves the completed receipt visible after the browser dismisses the dialog.
- An already-refunded sale submitted directly returns the existing "already been fully refunded" validation error with no completed receipt shown.

Existing coverage remains responsible for the exception path, narrow authorization, and saved-detail stability after Store, product, Cashier, and Register edits (`cash_refund_concurrency_integration.php`, `safe_refund_http_scenarios.php`), and for print styling and reprint behaviour (`refund_receipt_browser_test.js`, `refund_reprint_browser_test.js`).
