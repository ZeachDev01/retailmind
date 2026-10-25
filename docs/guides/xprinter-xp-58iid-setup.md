# Xprinter XP-58IID checkout printing

## Confirmed setup

- User-reported printer: Xprinter / Xinye XP-58IID, 58 mm paper.
- Cashier device: Windows, Chrome or Edge.
- Initial connection: USB. Bluetooth can be tested separately.
- Default flow: complete checkout or refund, review the saved receipt, and print manually.
- Optional flow: select **Print receipt automatically** before confirming payment
  or recording a refund. Both checkboxes start unchecked for every transaction.

This uses the existing receipt flow and Register paper-width setting, with no direct
device integration. See [opt-in receipt printing](../specs/opt-in-receipt-printing.md).

## Seamless printing on this cashier PC

A **RetailMind POS** shortcut was created on Mike's Desktop on 2026-10-24. Open it
and sign in to RetailMind. It uses a separate Chrome profile, so an existing login
in normal Chrome is not copied. Keep this window for Store work.

The shortcut opens the local POS with these options:

```text
--no-first-run --no-default-browser-check --kiosk-printing --use-system-default-printer --user-data-dir="C:\Users\Mike\AppData\Local\RetailMind\ChromePOS" "http://localhost/retailmind/src/frontend/components/cashier/pos.php"
```

Chrome's [kiosk printing option](https://chromium.googlesource.com/chromium/src/+/ca9fc3f47b7e6cb6f914ad7d1835046b076aac68/chrome/common/chrome_switches.cc)
automatically accepts print preview.
The [system default printer option](https://chromium.googlesource.com/chromium/src/+/refs/tags/133.0.6928.0/chrome/common/chrome_switches.cc)
selects the Windows default printer. Use both options only in this dedicated
profile; printing other pages in that window also prints without confirmation.
Normal Chrome windows retain their regular printing behavior.

At setup, **Xprinter XP-58** was the Windows default printer, using driver
**Xprinter XP-58** on **USB001**, and both RetailMind Registers were set to 58 mm.
Windows reports the printer online; that does not prove paper output. Its driver
reports a custom page with 48 mm width, so inspect the actual result before changing
the existing paper calibration. No driver or Register settings were changed.

Test first by opening an existing saved receipt in the shortcut and clicking Print;
this prints paper without creating a new sale. Then, during a normal sale/refund,
check **Print receipt automatically** before confirming. The saved receipt opens
and printing starts once. Unchecked transactions do not print. Refresh/history do
not print again. A brief print-preview flash can occur.

To return to print confirmation, close the dedicated POS window and use normal
Chrome, or remove `--kiosk-printing` from the shortcut's Properties before reopening
it. Do not open the dedicated profile simultaneously with different launch options.
If the default printer changes, silent output follows that printer; verify the
Windows default after replacing or reconnecting hardware.

The alternative `SilentPrintingEnabled` browser policy requires access to protected
registry keys on this PC. An attempted user-level change was denied; no policy was
changed. The shortcut avoids needing that policy or administrator access.

## Setup

1. Connect the powered printer to the cashier computer by USB.
2. Install the appropriate Windows receipt-printer driver from [Xprinter's official downloads](https://www.xprinter.net/Download_Details/3.html). The manufacturer lists this universal package for its 58/80/76 mm receipt series; successful XP-58IID installation remains to be verified on the device.
3. Select the installed printer's 58 mm paper profile in Windows printing preferences. Print a Windows test page before testing the POS.
4. In RetailMind, open the Administrator's Registers page and save **58 mm** for the Register used by this cashier's open Cashier Shift. Receipts use that Register's width; without a matching open cashier shift, the application defaults to 80 mm.
5. Open an existing authorized Sale Receipt from cashier history, then click **Print Receipt**. This avoids creating a sale just to test printing.
6. In Chrome or Edge, select the installed Xprinter printer and its 58 mm paper profile. Start with scale 100%, margins None where available, and headers/footers disabled. Driver margins and printable area may require adjustment after inspecting the physical result.
7. Confirm long item names, quantities, prices, totals, and the footer print without clipping. Check paper feed and extra blank space on both short and long receipts.

## Printing failures and reprints

Canceling printing does not undo a completed checkout. If paper runs out or printing fails, reopen the saved receipt and print it again; do not repeat checkout.

For Bluetooth, first verify that the particular unit supports it and can print a Windows test page through its installed driver. Once Windows exposes a working printer queue, select that queue in the same browser print dialog. USB success alone does not confirm Bluetooth support.

## Verification status

Automated checks cover unchecked defaults, opted-in sale/refund printing, manual
retry, refresh/history, failed refunds, and 58/80 mm receipt layouts. The shortcut's
saved target and launch options were read back and verified. Physical receipt
output, silent-mode behavior in the installed browser, driver calibration, and
Bluetooth operation still require a device test.
