# GCash Service Receipts

Confirmed 2026-10-24. Implementation specification. Published as [GitHub issue #289](https://github.com/ZeachDev01/retailmind/issues/289), labeled ready-for-agent.

## Problem Statement

Cashiers can complete GCash Cash-In and Cash-Out exchanges but cannot give customers a matching receipt or reprint one from GCash history. Customers need a clear record of the exchange, its fee, and its reference. The reference should be readable and scannable on the printed receipt.

## Solution

Show a saved GCash Service Receipt after each completed exchange, using the existing POS receipt modal's appearance and centered thermal-paper preview. Center a barcode of the saved GCash reference with the reference digits beneath it. Support the existing Register's 58 mm or 80 mm paper width, manual printing, and an unchecked per-transaction automatic-print option. Cashiers can reprint their own receipts; Administrators can reprint Store-wide receipts through GCash Overview.

## User Stories

1. As a Cashier, I want a receipt after completing Cash-In, so that I can give the customer proof of the recorded exchange.
2. As a Cashier, I want a receipt after completing Cash-Out, so that I can document the cash handed over and the service fee.
3. As a Cashier, I want both Cash-Out fee methods represented accurately, so that the receipt explains cash and wallet exchanges correctly.
4. As a Cashier, I want Needs checking exchanges excluded from completed receipts, so that unfinished exchanges do not appear complete.
5. As a Cashier, I want a receipt when a Needs checking exchange is later completed, so that delayed completion has the same customer proof.
6. As a Cashier, I want the receipt modal to match the POS receipt modal, so that reviewing and printing feel familiar.
7. As a Cashier, I want the paper preview and action buttons centered, so that the modal follows the supplied POS example.
8. As a customer, I want the GCash reference barcode centered on the receipt, so that staff can scan it easily.
9. As a customer, I want readable reference digits beneath the barcode, so that the reference remains useful without a scanner.
10. As a customer, I want leading zeroes preserved, so that the printed and scanned reference match the original transfer.
11. As a customer, I want the exchange type, transaction number, and completion date/time, so that I can identify the exchange.
12. As a customer, I want Store details, Cashier, and Register identification, so that I know where and by whom the exchange was recorded.
13. As a customer, I want the principal, service fee, and fee method shown separately, so that the cost is clear.
14. As a customer, I want cash collected, cash paid out, and wallet transfers labeled accurately, so that I can check what changed hands.
15. As a Cashier, I want the customer copy to omit internal notes and Store balances, so that operational information stays private.
16. As a Cashier, I want a manual Print Receipt button, so that I can print or retry when needed.
17. As a Cashier, I want an unchecked Print receipt automatically option for each completion review, so that printing starts only when I request it.
18. As a Cashier, I want failed completions never to print, so that an unsuccessful operation cannot produce proof of completion.
19. As a Cashier, I want canceling or failing printing to leave the exchange saved, so that I can retry printing without repeating money movements.
20. As a Cashier, I want refreshes and reprints not to print automatically, so that reopening a receipt does not create unexpected copies.
21. As a Cashier, I want receipts to follow the current printing Register's 58/80 mm width, so that the layout fits the available thermal roll.
22. As a Cashier, I want to reopen my saved receipts from GCash History, so that I can replace a lost copy.
23. As an Administrator, I want to reopen Store-wide receipts from GCash Overview, so that I can investigate or reprint an exchange.
24. As a Store owner, I want receipt access to respect existing workspace permissions, so that Cashiers cannot retrieve other Cashiers' receipts.
25. As a customer, I want reprints to retain completion-time Store, Register, amount, and footer details, so that later setting changes do not rewrite my receipt.
26. As an Administrator, I want a later audited correction to leave the original receipt unchanged, so that the original exchange remains traceable.
27. As a Cashier, I want long references and labels to remain readable on narrow paper, so that information is not clipped.
28. As a Cashier, I want to close the receipt and return to the existing GCash workflow, so that receipt viewing does not start another exchange.

## Implementation Decisions

- Extend the existing GCash transaction completion and later-resolution workflows to preserve receipt details atomically when an exchange becomes Completed. A Needs checking record does not qualify. Failed or rolled-back completion must not leave a receipt.
- Persist the customer-facing Store, Register, Cashier, transaction identity, completion timestamp, exchange type, principal, fee, fee method, actual customer cash/wallet exchange amounts, reference, and footer needed for faithful reprints. Use completion-time values, not mutable current settings. Retain saved values after linked audited corrections; separate correction receipts are outside scope.
- Keep GCash Service Receipts distinct from Sale Receipts and Refund Receipts. Do not add service exchanges to Product sales or Sales Transactions receipt history. Keep GCash receipts in their existing GCash history/overview contexts.
- Reuse the existing POS receipt modal shell, shared thermal presentation, print behavior, Register width resolution, and saved-receipt conventions. Follow the supplied POS example: header and Close control, completion banner, centered actions, and centered paper preview. Use GCash-specific wording and a workflow-appropriate return action; do not label an exchange as a sale or display a fabricated change-due amount.
- Use the existing Code 128 SVG barcode renderer. Encode only the saved normalized GCash reference as text, preserving leading zeroes. Center the barcode and readable digits beneath it on both preview and print. Retain sufficient quiet space and validate readability within both roll widths. Do not truncate reference data to make it fit.
- A barcode represents a recorded reference, not independent GCash verification. No new scan-to-open or scan-to-complete workflow is introduced. Preserve existing reference capture, duplicate checks, and manual verification rules.
- Label the financial amounts by their actual purpose rather than an ambiguous total. For PHP 1,000 principal and PHP 10 fee: Cash-In collects PHP 1,010 cash and sends PHP 1,000 by wallet; Cash-Out with wallet fee receives PHP 1,010 by wallet and pays PHP 1,000 cash; Cash-Out with cash fee receives PHP 1,000 by wallet, collects PHP 10 cash, and pays PHP 1,000 cash. Do not substitute net Store balance deltas for these customer-facing amounts.
- Exclude internal notes, service cash/wallet balances, reconciliation details, and new customer name/phone collection.
- Extend successful completion responses with a saved-receipt locator suitable for opening the shared modal. Render and retrieve only persisted Completed exchange receipts. Reopening and printing are read-only and must never invoke completion or repost financial movements.
- Add an unchecked Print receipt automatically choice to each completion confirmation, including later resolution to Completed. After successful persistence, open the receipt and request printing once only if selected. Failed operations never print. Refresh, history retrieval, and reopening never automatically print. Manual Print remains available after cancellation or failure.
- Use the current printing Register's configured 58 mm or 80 mm width, with the existing 80 mm fallback when no current Register resolves. Browser and installed driver handle the printer, margins, feed, and cutting. The application does not confirm physical paper output.
- Add receipt actions to Cashier GCash History and Administrator GCash Overview. Preserve own-only Cashier access and Store-wide Administrator access, enforced on the server for direct receipt requests as well as visible actions. Receipt retrieval does not require replaying a financial write.
- Preserve existing authentication, workspace, shift/write checks, CSRF protection for writes, protected audit behavior, duplicate-reference protections, and exact monetary handling. Receipt integration must not change fees, balances, exchange state rules, or correction authority.
- The project is in development: no legacy receipt reconstruction, historical-data notices, or backfill is required. Preserve existing records without inventing receipt snapshots. Entries without a saved receipt do not expose an actionable receipt link. Maintain fresh-install/migration parity and existing backup coverage for persisted receipt data.
- Reuse existing modules and dependencies; no new barcode or printing package is needed.

## Testing Decisions

- User confirmed reuse of existing GCash browser/API workflow tests and receipt-modal tests, backed by database integration checks for saved details and permissions. Prefer these highest existing seams over a new framework or private-helper tests.
- Test externally visible outcomes: a successful completion opens its saved receipt; amounts and reference match the exchange; history retrieval returns the same receipt; unauthorized access fails; printing never changes financial state.
- Cover Cash-In and both Cash-Out fee methods, including the PHP 1,000 examples, centavo fees, and readable separate collection/payout labels.
- Cover direct completion, Needs checking without a receipt, and later resolution to Completed with a receipt. Verify failed completion and audit rollback leave neither a receipt nor partial money posting; retries do not create duplicate receipt snapshots or financial effects.
- Verify Cashier own-only retrieval and Administrator Store-wide retrieval, including direct endpoint requests for another Cashier's exchange.
- Change Store settings, Cashier display details, Register name, and fee settings after completion and verify saved receipt content remains stable. Record a linked audited correction and verify the original receipt is unchanged.
- Exercise centered modal presentation, keyboard focus/Close behavior, manual Print, unchecked defaults for every confirmation, selected automatic printing exactly once, and no automatic print on refresh or history reopen.
- Verify barcode payload and readable digits preserve the complete reference and leading zeroes. Check preview and print layout at 58/80 mm with maximum configured reference length, long Store details, and long Cashier/Register names; no clipped amounts or reference digits.
- Use existing receipt presentation fixtures and disposable database integration patterns. Verify schema setup and backup retention for added receipt data through existing project checks.
- Browser checks do not establish physical scan reliability. When hardware is available, print and scan both widths, especially the longest supported references, and check driver scaling and margins. Do not claim hardware compatibility without that check.

## Out of Scope

- Legacy-data support, historical reconstruction, backfill, and development-data cleanup.
- GCash transfers, provider verification APIs, wallet credentials, or live balance integration.
- Customer data collection, receipt photos, separate adjustment receipts, and changes to existing correction workflows.
- New scanner workflows, hardware purchases, and guaranteed printer/scanner compatibility.
- Direct device access, ESC/POS, printer discovery/selection, drawer/cutter control, and guaranteed silent printing.
- Changes to Product sales, inventory, fee rules, GCash financial posting, or other receipt layouts.

## Further Notes

- The user confirmed centered reference barcode/digits and the existing POS receipt modal appearance using a screenshot.
- The user confirmed all reprint, preservation, and later-completion decisions, and explicitly waived legacy handling because the project is in development.
- Related specifications: [GCash service](../gcash-service-spec.md), [thermal customer receipts](thermal-customer-receipts.md), and [opt-in receipt printing](opt-in-receipt-printing.md).
- Specification only. No application implementation or runtime tests are part of this task.
