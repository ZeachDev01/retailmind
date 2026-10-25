# Optimize role workspaces for tablets and mobile monitoring

## Problem Statement

Cashiers need reliable PC and tablet checkout with attached scanners and access to the Store's PC-connected thermal printer. Inventory Managers need to work throughout the Store on portable tablets without losing entered values or returning to a PC to complete shelf work. The Administrator needs convenient phone monitoring. Existing responsive layouts and emulated checks do not establish physical iPad, Android, scanner, or printer compatibility. The owner likes the existing tablet POS layout and wants it preserved.

## Solution

Optimize each workspace around its work: retain PC and tablet POS, make Inventory Manager workflows practical on tablets, and prioritize Administrator monitoring on phones. Support Safari and Chrome on iPad and Chrome on Android tablets, portrait and landscape, attached keyboard-style scanners, and saved-receipt printing through the Store PC's thermal printer. Preserve all entered inventory values as recoverable drafts without silently creating Products, posting offline stock changes, or duplicating completed operations.

## User Stories

1. As a Cashier, I want the current tablet POS layout retained, so that familiar checkout remains efficient.
2. As a Cashier, I want to complete checkout by touch, so that a tablet does not require a physical keyboard.
3. As a Cashier, I want keyboard and scanner efficiency retained on PC, so that tablet improvements do not slow counter work.
4. As a Cashier, I want product search, cart editing, totals, and payment actions reachable, so that I can complete sales without fighting the layout.
5. As a Cashier, I want GCash Services, Cashier Shifts, and Cash Refunds usable on a tablet, so that related counter tasks remain practical.
6. As a Cashier, I want consecutive scans to resolve correctly, so that selling several items does not lose or misdirect scanner input.
7. As a tablet operator, I want USB wireless-receiver and Bluetooth keyboard-style scanner connections supported on compatible hardware, so that I can reuse attached scanners.
8. As a tablet operator, I want iPad Safari, iPad Chrome, and Android Chrome workflows verified, so that browser choice does not unexpectedly break my work.
9. As a tablet operator, I want portrait and landscape support, so that I can use the device comfortably.
10. As a tablet operator, I want rotation to preserve my active cart or form, so that changing orientation does not discard work.
11. As a tablet operator, I want fields and actions reachable with the on-screen keyboard open, so that text entry does not block completion.
12. As a Cashier, I want the saved receipt preview shown after checkout, so that I can review the completed transaction.
13. As a Cashier, I want Print to Store Printer from my tablet, so that the thermal printer connected to the Store PC prints the saved receipt.
14. As a Cashier, I want direct PC-routed printing without selecting an iPad printer, so that printing is a simple action at the counter.
15. As a Cashier, I want existing PC receipt printing retained, so that both cashier device types remain usable.
16. As a Cashier, I want printing failures to leave my completed sale intact, so that printer availability does not undo checkout.
17. As a Cashier, I want to retry printing a saved receipt, so that I never need another checkout to obtain a paper copy.
18. As a Cashier, I want a warning when print delivery is uncertain, so that I can check for an existing copy before reprinting.
19. As a Cashier, I want repeated print requests to avoid new sales or stock deductions, so that a printing problem cannot change transaction records.
20. As an Inventory Manager, I want Products, Inventory Counts, Stock Receiving, Stock Issue reviews, and Supplier Pullouts usable on a portable tablet, so that I can finish shelf work without returning to a PC.
21. As an Inventory Manager, I want tablet-friendly stock lookup and quantity entry, so that I can work near the goods being counted or received.
22. As an Inventory Manager, I want a scan outside editing to open an existing Product, so that I can quickly inspect it.
23. As an Inventory Manager, I want an unknown scan to show a Product-not-found prompt, so that I understand why lookup failed.
24. As an Inventory Manager, I want unknown scans to create nothing, so that the catalog does not accumulate silent or incomplete Products.
25. As an Inventory Manager, I want Product creation to remain explicit, so that I control when a new Product is added.
26. As an Inventory Manager, I want to scan into a selected barcode field during explicit Product entry, so that I do not manually transcribe the identifier.
27. As an Inventory Manager, I want scans to leave name, price, quantity, and other unrelated fields untouched, so that scanner input cannot corrupt a form.
28. As an Inventory Manager, I want a scan to avoid submitting or advancing a Product form, so that I retain control of review and completion.
29. As an Inventory Manager, I want duplicate barcode validation retained, so that different Products cannot accidentally share conflicting identifiers.
30. As an Inventory Manager, I want all entered inventory values retained when saving is interrupted, so that connectivity failures do not force re-entry.
31. As an Inventory Manager, I want a clear unsaved warning and success only after server confirmation, so that I know whether inventory actually changed.
32. As an Inventory Manager, I want drafts to survive refresh, browser closure, and tablet restart, so that unfinished work can be resumed.
33. As an Inventory Manager, I want Resume and Discard choices, so that I decide what happens to unfinished work.
34. As an Inventory Manager, I want drafts available only to the same signed-in user, so that another operator on a shared tablet cannot inspect or restore my work.
35. As an Inventory Manager, I want a draft cleared after confirmed submission or explicit discard, so that completed work does not return as an unfinished task.
36. As an Inventory Manager, I want draft recovery to avoid automatic stock posting, so that resuming work cannot silently change inventory.
37. As an Inventory Manager, I want interrupted submission retries to avoid duplicate Stock Receiving and stock changes, so that response loss cannot inflate or reduce stock twice.
38. As an Inventory Manager, I want restored values checked against current permissions and Product state, so that an old draft cannot bypass current rules.
39. As an Inventory Manager, I want entered values retained after validation errors, so that correcting a mistake does not require rebuilding the form.
40. As an Administrator, I want Store Overview, GCash Overview, notifications, and report summaries easy to read on my phone, so that I can monitor the Store while away from my PC.
41. As an Administrator, I want monitoring summaries to open relevant details, so that I can investigate exceptions on my phone.
42. As an Administrator, I want Stock Issue Oversight to remain read-only, so that monitoring does not change operational authority.
43. As an Administrator, I want authorized actions to remain accessible while detailed administration stays comfortable on PC, so that device optimization does not remove existing capabilities.
44. As a Store operator, I want readable failures, touch controls, keyboard focus, and reachable dialogs across target devices, so that errors and accessibility needs do not block work.

## Implementation Decisions

- Preserve the existing server-rendered application, role workspaces, house components, responsive system, and accepted tablet POS composition. Inspect existing behavior first and repair demonstrated gaps rather than replacing functioning pages or creating a separate tablet application.
- Primary scope: Cashier POS, GCash Services, Cashier Shifts, Cash Refunds, and receipt presentation; Inventory Manager Products, Inventory Counts, Stock Receiving, Stock Issue reviews, and Supplier Pullouts; Administrator Store Overview, GCash Overview, notifications, report summaries, and Stock Issue Oversight. Retain PC usability and existing authorized access.
- Cover iPad Safari, iPad Chrome, and Android Chrome in portrait and landscape. Preserve active work during rotation and keep focused fields and primary actions reachable when the software keyboard is present. Exact device models and supported OS/browser versions must be recorded during acceptance; none are invented by this specification.
- Reuse existing keyboard-style scanner handling and Product lookup. The confirmed USB arrangement is a wireless scanner receiver connected through a tablet adapter; Bluetooth is also required on compatible hardware. Use Enter-terminated input where supported. The owner reports Android testing, not physical iPad validation.
- Scope scanner handling to context. Outside Product editing, resolve an existing Product or show Product not found. During Product editing, accept a scan only into an explicitly selected barcode field. Do not redirect scans into unrelated fields, auto-advance, auto-submit, or auto-create Products. Preserve identifier uniqueness checks and existing POS scan semantics.
- Reuse saved Sale Receipt and Refund Receipt presentation and configured thermal paper width. Keep the receipt preview and provide Print to Store Printer, routing the saved receipt to the existing XP-58IID connected to the Store PC. The direct route must not require the tablet browser's printer-selection dialog. Existing authorized PC printing remains available.
- Implement the smallest PC-side print delivery path that meets the contract. A tablet browser's print dialog alone is insufficient. The tablet reaches the PC through its local network address or hostname; localhost on the tablet refers to the tablet. The PC and its printing service must be available. Select bridge/worker technology during implementation rather than assume the current browser helper already provides this integration.
- Print submission must identify an existing authorized receipt and configured printer destination; it must not accept arbitrary executable commands or unrestricted printer targets. Enforce active-workspace authorization and request protections at the application boundary, and protect PC-side delivery from unauthenticated network requests. Bind any PC worker to its intended Store deployment.
- Separate sale/refund completion from printing. Print jobs, repeated taps, and retries must never invoke checkout again or repeat stock changes. Report saved-receipt and printing status distinctly. When job outcome is uncertain, require an informed reprint choice rather than silently replaying an ambiguous job. Do not equate queue acceptance with confirmed physical paper output.
- Extend existing inventory draft behavior to retain every entered form value for the in-scope inventory entry workflows, not merely the visible page or a subset of receiving fields. Preserve form context and necessary identifiers, with the same signed-in user's Resume / Discard choices after reload, browser closure, and device restart.
- Draft isolation is an authorization requirement on shared devices, not just a user identifier in a readable browser key. Select storage that meets recovery and isolation requirements; do not expose another user's values. Show a clear preservation failure if durable storage is unavailable. Do not claim browser-data deletion, eviction, or device-loss recovery without evidence from the chosen design.
- Keep drafts separate from committed inventory. Clear after confirmed submission or explicit discard; retain on recoverable errors. Never submit automatically when restoring a draft or reconnecting. Revalidate current permissions, Products, quantities, and existing business rules before final submission.
- Distinguish unsent work from a server-committed operation whose response was lost. Reconcile uncertain outcomes and reuse existing operation identity/idempotency patterns where suitable; safe retry must not repeat stock changes. Inventory saving remains server-confirmed, not offline posting.
- Reuse existing receiving draft logic, stock-change validation, checkout attempt handling, role authorization, and shared responsive components before adding new mechanisms. Any persistence or job schema changes must be limited to the confirmed draft, reconciliation, and print-delivery contracts; exact tables and APIs are implementation choices.
- Preserve Store versus technical authority and active Workspace boundaries. Administrator Stock Issue Oversight remains read-only. Device class does not grant or remove roles. Preserve nontechnical Operator Alerts and keep technical details in existing logs.

## Testing Decisions

- The primary seam is the existing authenticated browser/HTTP workflow, extended rather than replaced. The owner accepted real-device workflow checks and no-duplicate-operation checks during the design discussion. Test visible outcomes and persisted business effects, not CSS strings, private helpers, exact timing thresholds, or an implementation-shaped mock graph.
- Reuse existing responsive system/component, cashier UI, keyboard scan, inventory scan, checkout attempt, receipt dialog, receiving draft, and Administrator dashboard browser coverage. Existing stock receiving and inventory reconciliation integration tests provide prior art for checking persisted quantities and records. Use disposable databases for stock-changing tests.
- Verify accepted POS layout and PC keyboard/scanner behavior alongside touch checkout, cart editing, modal actions, scrolling, orientation changes, and software-keyboard reachability on the target tablet browser matrix. Test relevant empty, populated, long-content, validation-error, and network-error states.
- Verify consecutive scans, explicit Add Product barcode entry, unknown-code prompts without creation, duplicate identifiers, unrelated-field protection, Enter handling, and no scan-triggered submission or wizard advancement. Exercise both USB-receiver and Bluetooth hardware where compatible; synthetic keyboard events alone do not establish hardware support.
- Verify every entered inventory value survives reload and browser restart, with Resume / Discard, same-user isolation, validation-error retention, clear-on-success, clear-on-discard, and no automatic posting. Use a physical tablet restart for that acceptance claim. Check storage-unavailable feedback and changed Product/permission validation.
- Simulate a connection failure before submission and a lost response after a successful server commit. Retry through the user flow and assert exactly one Stock Receiving or stock mutation. The second case must prove reconciliation, not merely form retention.
- For printing, use the saved-receipt print request boundary plus the smallest necessary PC delivery integration check. Verify authorization, duplicate taps, unavailable PC/printer, uncertain delivery, explicit reprint, and no extra sale/refund or stock movement. A fake or test spooler may prove job behavior, but not thermal output.
- On actual iPad Safari, iPad Chrome, and Android Chrome, verify the end-to-end local-network print route to the PC and physical XP-58IID. Inspect long names, totals, configured paper width, clipping, and repeat/reprint behavior. Record OS/browser, scanner, adapter, printer, and connection details. Do not claim a device pass if that hardware was unavailable.
- Verify phone-width Administrator summaries, drill-downs, navigation, notifications, and existing authorization. Record physical phone/browser coverage separately; a responsive viewport pass is not a physical phone pass.
- Run focused existing checks appropriate to each implementation change. Report the tested matrix, skipped hardware combinations, failures, and remaining limitations without promising the absence of every possible bug.

## Out of Scope

- Replacing the accepted tablet POS layout, a separate native tablet app, or a broader visual redesign.
- Silent Product creation, automatic draft submission, offline checkout commitment, or offline inventory posting.
- Changes to role authority, multi-Store behavior, reporting definitions, forecasting behavior, or existing business validation.
- Direct tablet-to-printer Bluetooth/USB integration as a replacement for the confirmed PC-routed printer destination; selecting or purchasing new hardware.
- Cross-device draft synchronization or recovery after device loss/browser-data deletion unless separately established by the selected storage design; no such guarantee was discussed.
- Live deployment, firewall/network changes on the Store installation, or claiming physical-device acceptance based solely on emulation.

## Further Notes

The owner confirmed shared understanding and requested publication on 2026-10-25. The accepted scope includes persistent drafts and safe retry, not just responsive CSS.

The existing role-device design brief and the decision on tablet receipts through the Store PC retain the discussion context. Respect the single-Store role boundaries, existing server-validated operation policy, and proposed local Windows deployment. Internet failure and loss of the Store PC connection are different conditions; final commits require the application server even when that server is reachable locally without internet.

Exact tablet/scanner models and OS/browser versions remain acceptance inputs. PC print delivery and durable draft storage are implementation choices constrained by this specification. They do not require another product interview; physical verification must remain explicitly pending until performed. Publish with ready-for-agent; readiness for implementation does not claim hardware certification or authorize production deployment.
