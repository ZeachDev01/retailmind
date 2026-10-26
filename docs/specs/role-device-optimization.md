# Role and device optimization

The Store PC print delivery proposal below was superseded: saved Sale and Refund Receipts now offer browser printing only.

Design discussion updated 2026-10-25. The owner confirmed shared understanding and requested specification publication. See the [implementation specification](role-device-optimization-spec.md). The listed hardware and implementation prerequisites remain pending; this document does not claim implemented or device-tested support.

Published as [issue #314](https://github.com/ZeachDev01/retailmind/issues/314) with `ready-for-agent`.

## Confirmed priorities

| Workspace | Device priority | Required outcome |
| --- | --- | --- |
| Cashier | PC and tablet | Preserve the current tablet POS layout, which the owner likes. Support complete touch checkout and tablet barcode scanner and receipt printer integration while retaining PC keyboard/scanner efficiency. |
| Inventory Manager | Portable tablet, with PC available for bulk work | Complete shelf work, including counts, Stock Receiving, and Stock Issue reviews, without returning to a PC. Support an attached barcode scanner when initially adding Products to inventory. |
| Administrator | Phone for monitoring, PC for detailed administration | Prioritize Store Overview, GCash Overview, notifications, report summaries, and read-only Stock Issue Oversight. Preserve existing permissions and access to authorized actions. |

## Confirmed tablet and scanning requirements

- Support iPad and Android tablets. Cover Safari on iPad and Chrome on iPad and Android; exact models and supported OS/browser versions remain to be agreed.
- Support portrait and landscape, including rotation with an active cart or inventory form. Preserve entered data and keep actions reachable with the on-screen keyboard open.
- The owner reports scanner testing on an Android tablet using a USB dongle/adapter and Bluetooth. Equivalent iPad behavior has not been tested. Confirm the scanner model and mode before treating either connection as supported across platforms.
- The owner confirmed the USB arrangement is a wireless scanner receiver connected through a tablet adapter, and accepted keyboard-input mode with Enter terminating scans where supported.
- An unknown barcode must display a Product-not-found prompt and create nothing. Scanning must not silently create a Product. Product creation remains an explicit user workflow.
- Existing Product lookup already opens a matching Product and reports unknown codes without automatically opening Add Product. The explicit Add Product wizard already accepts keyboard-style scanner input at its Barcode step without saving or advancing on Enter. Preserve these boundaries.
- The owner's goal is reliable operation across the target tablets and browsers. Acceptance requires defined workflow checks and physical hardware verification; emulation alone does not establish bug-free operation.
- Outside editing, scanner input finds an existing Product or shows the Product-not-found prompt. While editing a Product, accept scanner input only for an explicitly selected barcode field. Scans must not overwrite name, price, quantity, or other unrelated fields, submit the form, or advance the wizard.

## Confirmed inventory interruption behavior

When connectivity interrupts an inventory save, retain every entered value and show a clear unsaved warning. Show success only after server confirmation. A safe retry must not duplicate Stock Receiving or apply stock changes twice. Retained form values are an unsaved draft, not committed inventory.

Preserve all entered inventory form values across page reload, tab closure, browser restart, and tablet restart. Offer Resume / Discard to the same signed-in user; another user on a shared tablet must not see or restore those drafts. Clear the draft after confirmed submission or explicit discard. Recovering a draft must never automatically commit inventory changes; final submission must still validate current permissions, Products, quantities, and other server-side rules.

Draft storage and recovery must distinguish an unsent form from an operation the server already committed before the response was lost. Reconcile uncertain submissions before offering another stock-changing submission. Durable drafts do not constitute offline inventory posting, and rotation must not reset them. Storage technology remains an implementation choice; clearing browser data, storage eviction, or device loss must not be represented as covered recovery without verification of the selected design.

## Confirmed printing destination

The Cashier must be able to request printing from an iPad and have the saved receipt printed by the existing thermal printer connected to the Store PC on the same local network. The prior guide identifies that printer as the XP-58IID. Keep the current tablet POS layout.

Keep the saved receipt preview visible and offer an explicit Print to Store Printer action that routes to the PC-connected thermal printer. The owner accepted direct sending without an iPad printer-selection dialog; "direct" here means the confirmed PC printer destination, not a newly selected Bluetooth or USB printer attached to the tablet.

Print failure must leave the completed sale untouched. Offer a receipt-print retry without repeating checkout. When delivery is uncertain, warn before a reprint because a paper copy may already exist. A print request or retry must never create another sale or apply stock deductions again.

This requires a PC-side printing path; opening the tablet browser's print dialog alone does not implement this requirement. The tablet must reach the Store PC by its network address or hostname: `localhost` on the tablet refers to the tablet itself. The PC and its printing service must be available for this route to work. Bridge/worker technology, installation, print authorization, and job-status implementation remain design decisions, not implemented support.

## Confirmed acceptance requirements

Require real-device checks in iPad Safari, iPad Chrome, and Android Chrome for checkout, consecutive scans, unknown barcodes, inventory entry, rotation, on-screen keyboard behavior, and physical receipt output. Include print failure and retry checks that prove printing does not repeat checkout. Exact hardware and OS/browser versions must be recorded with the results; no physical-device pass is claimed by this design document.

Verify inventory draft recovery after reload, browser closure, and tablet restart; Resume / Discard; isolation between users; clearing after confirmed submission; retention on validation errors; and response-loss retries without duplicate stock changes. Verify Administrator monitoring at phone widths with reachable details and unchanged authorization. Record physical phone coverage separately from tablet results.

## Existing evidence and constraints

- The existing POS already has tablet layouts. Improve the required interactions without replacing the accepted layout.
- Receipt printing currently uses `window.print()` through `src/frontend/assets/js/sale-receipt.js`; this invokes browser printing and does not establish compatibility with a particular tablet or thermal printer.
- Existing responsive verification covers emulated layouts; physical devices, scanner behavior, and printer output require acceptance checks with the selected hardware. See [responsive verification](../verification/responsive-ui-verification.md).
- [Local Windows deployment](../adr/0015-local-windows-host-with-private-remote-access.md) records a wired scanner and thermal printer, but does not establish their tablet compatibility. Tablet usability and remote connectivity are separate requirements.

## Open decisions

- Tablet models, screen sizes, supported OS/browser versions; Administrator phone platform.
- Exact scanner model and physical iPad compatibility of the confirmed wireless USB receiver/adapter and Bluetooth connections.
- PC-side print delivery technology, installation, authorization, and job-status implementation.
- Durable draft storage and persistence-failure feedback; reconciliation of uncertain submissions.

## Final review

Implementation scope is role-specific device optimization, preserving the accepted POS layout, tablet scanner support, PC-routed thermal printing, durable inventory drafts, and mobile Administrator monitoring. No automatic Product creation, automatic draft submission, changed role permissions, or offline stock posting is included. Shared understanding was confirmed on 2026-10-25; deployment and hardware acceptance remain separate.

No hardware purchase, native wrapper, print bridge, or direct-device API has been selected.
