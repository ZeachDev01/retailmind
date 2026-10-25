# Saved receipts through the Store PC

Cashier Sale Receipt and Refund Receipt previews include **Print to Store Printer**. This posts a saved receipt identifier to the authenticated application on the Store PC; no tablet printer chooser or tablet-local `localhost` bridge is involved. Keep the existing **Print Receipt** action for ordinary browser printing on the PC.

## Installation

Apply database migrations using the existing migration procedure. On the intended Windows Store installation set these deployment-local `.env` values:

```dotenv
STORE_PRINT_DEPLOYMENT=shalom-store-pc
STORE_PRINT_PRINTER=XP-58IID
```

Use the actual installed Windows printer queue name for `STORE_PRINT_PRINTER`, and a unique deployment identifier for this installation. Configure the Register's existing 58mm/80mm receipt width; the XP-58IID normally uses 58mm. The CLI worker uses this installation's database configuration and only claims jobs bearing its deployment identifier. A copied installation must use a different deployment identifier. Do not expose database credentials to tablets or another installation.

Run under the Windows account that has the printer installed:

```powershell
php C:\path\to\retailmind\src\backend\scripts\store_print_worker.php
```

The command drains queued jobs and exits. Schedule it through Windows Task Scheduler at a suitable interval (for example, every minute), with an absolute PHP path and the intended installation as its working directory. Keep the task noninteractive/hidden. It opens no network listener and accepts no printer name, executable, or print content from the browser. The tablet opens RetailMind using the Store PC's LAN hostname/address. Installation, task registration, firewall changes, and live printing were not performed by this change.

## Delivery and recovery

**Queued** means the worker has not accepted the job; verify the PC, task, and printer are available. Repeated taps reconcile the queued job. **Sent to the Windows print queue** confirms spooler acceptance only, never physical paper output. A crash, incomplete write, failed acknowledgement, or claim older than two minutes leaves delivery **uncertain**. Such jobs are never replayed automatically. Check the printer and Windows queue before explicitly requesting another copy. Duplicate taps on the same reprint request create only one delivery.

Printing never calls checkout, refund completion, or stock-changing code. The saved transaction remains complete during a printing failure. Receipt content comes from the same saved-receipt services and presentation as the preview. The raw ESC/POS path wraps at 32 columns for 58mm or 48 for 80mm and converts the peso sign to `PHP ` and other non-ASCII characters to ASCII approximations. This requires hardware verification for the actual printer/encoding, long names, totals, feed length, and paper clipping. Windows spooler acceptance cannot establish those results. The worker does not cut paper automatically.

Stop the print task during database replacement/restoration. Reconcile restored queued jobs against paper already printed before resuming it; a restored database is not proof a queued receipt still needs another physical copy.

## Verification record

Automated coverage: disposable-database saved-receipt submission and ownership/workspace/Store checks; HTTP CSRF and POST-only boundary; duplicate submission and explicit reprint; exclusive claims; payload width/content; retained sale records; browser queue/uncertainty messages, informed reprint cancellation/confirmation, and orientation state. Existing cashier/browser printing coverage remains applicable.

The Windows delivery integration test runs the actual PowerShell adapter against a randomly named nonexistent printer: its C# spool adapter compiles, the unavailable queue becomes uncertain, and no physical printer receives output. Focused checks also cover the accepted POS scanner flow, existing saved receipt dialog/manual browser printing, and a simulated reduced visual viewport for checkout actions.

The broader existing `cashier_ui_browser_test.js` reported 160 failing checks out of 329; the unchanged integration baseline reported 159/329. Shared failures are page-heading counts, outdated quick-decrease/product-result selectors, and pagination contrast (4.24:1). The additional changed-branch failure was a Product Lookup wait timeout, with no Product Lookup source changes. Both runs recorded zero JavaScript and external-resource errors. These suite failures remain unresolved; the baseline comparison does not establish physical-device compatibility.

Physical acceptance remains pending for iPad Safari, iPad Chrome, Android Chrome, USB wireless receiver plus adapter, Bluetooth scanner, virtual keyboards, PC LAN routing, and the XP-58IID. Record actual device model, OS/browser version, scanner/adapter, Windows printer driver/queue, configured width, network route, and observed output. Emulated Chromium checks do not certify those combinations.
