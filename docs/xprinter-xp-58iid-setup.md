# Xprinter XP-58IID checkout printing

## Confirmed setup

- User-reported printer: Xprinter / Xinye XP-58IID, 58 mm paper.
- Cashier device: Windows, Chrome or Edge.
- Initial connection: USB. Bluetooth can be tested separately.
- Flow: complete checkout, review Sale Receipt, click Print Receipt, confirm the browser print dialog.

This uses the existing receipt flow and Register paper-width setting. No application code or direct device integration is needed for this approach. It follows [the thermal receipt spec](specs/thermal-customer-receipts.md).

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

Code inspection confirms manual browser printing and the existing 58 mm Register setting. Driver installation, Windows test printing, Bluetooth operation, and physical receipt output have not been verified.
