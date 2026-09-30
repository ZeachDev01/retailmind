# Thermal Sale and Refund Receipts

## Problem Statement

Cashiers currently see a wide, desktop-style Sale Receipt after checkout. Its print layout fills the page instead of matching thermal paper, so the on-screen result does not resemble what a customer receives. Cash Refunds have no customer-facing receipt. Reopening a Sale Receipt also uses current Store and Register information, which can change the apparent history of an earlier transaction. The Store wants a practical path to printing on a chosen thermal printer without selecting a printer model now.

## Solution

Present every customer-facing Sale Receipt and Refund Receipt as a narrow thermal paper preview and print it at the paper width configured for the cashier's current Register. Support 80 mm by default and 58 mm where configured. Show the Sale Receipt immediately after checkout and the Refund Receipt immediately after a Cash Refund is recorded. Keep both available for later reprint. Printing remains a deliberate cashier action through the browser print dialog, so a Windows-installed thermal printer can be selected when the Store obtains one.

Preserve the customer-facing details of new receipts when their transactions are recorded. Older sales without preserved receipt details remain printable using the best available data, with a clear historical notice. A reprint adapts to the current printer width without changing the recorded information.

## User Stories

1. As a Cashier, I want to see a Sale Receipt immediately after checkout, so that I can review what I will give the customer.
2. As a Cashier, I want the screen preview to resemble narrow thermal paper, so that I can spot wrapping and spacing problems before printing.
3. As a Cashier, I want to print a Sale Receipt using the browser print dialog, so that I can choose the installed thermal printer.
4. As a Cashier, I want checkout to finish without opening the print dialog automatically, so that I can review the receipt first.
5. As a Cashier, I want a completed sale to remain saved when I cancel printing, so that printing cannot undo checkout.
6. As a Cashier, I want a Sale Receipt reopened from transaction history to use the same thermal layout, so that its preview and reprint are consistent with checkout.
7. As a Cashier, I want a Sale Receipt opened in the existing receipt modal to use the same thermal layout, so that the entry point does not change its appearance.
8. As a Cashier, I want Store name, address, contact details, business identifier, and footer on the Sale Receipt, so that the customer has the existing Store information.
9. As a Cashier, I want the sale number, date and time, Cashier, and Register on the Sale Receipt, so that the transaction can be identified.
10. As a Cashier, I want every sold item's SKU, name, quantity, unit price, and line total readable on narrow paper, so that the customer can check the purchase.
11. As a Cashier, I want long item names and payment references to wrap without clipping amounts, so that 58 mm receipts remain legible.
12. As a Cashier, I want the existing quantity, subtotal, discount, promotion or discount reason, total, payment method, cash received, change, and payment reference retained when applicable, so that the thermal layout loses no sale information.
13. As a Cashier, I want the existing verification code and receipt URL retained in a readable compact format, so that the existing verification information remains available.
14. As an Administrator, I want to set each Register's paper width to 80 mm or 58 mm, so that the preview and print layout match that till's roll.
15. As an Administrator, I want newly created and upgraded Registers to default to 80 mm, so that no setup is required for the common initial configuration.
16. As a Cashier, I want an 80 mm receipt to fit a 58 mm printer when reprinted from a Register configured for 58 mm, so that I can use the printer available now.
17. As a Cashier, I want a separate Refund Receipt immediately after recording a Cash Refund, so that I can give the customer proof of the refund.
18. As a Cashier, I want the Refund Receipt to identify its refund number and original Sale Receipt number, so that it can be matched to the purchase.
19. As a Cashier, I want refunded item names, quantities, amounts, total refunded, payment method, date and time, Cashier, Register, and predefined reason on the Refund Receipt, so that the customer can understand what was returned and how the money was settled.
20. As a Cashier, I want the Refund Receipt to omit my free-text note and Restockable or Damaged classification, so that internal operational details stay out of the customer copy.
21. As a Cashier, I want to reopen and print my own Refund Receipts from My refunds, so that I can replace a lost or damaged copy.
22. As a Cashier, I want recording a refund to remain complete when I cancel printing, so that print problems cannot cause a duplicate refund.
23. As a customer, I want a reprint of a new Sale Receipt or Refund Receipt to preserve its transaction-time Store, Register, item, amount, and footer details, so that later edits do not rewrite my receipt.
24. As a Cashier, I want an older sale without preserved Store details to remain printable with a visible historical-data notice, so that I do not mistake current Store information for an exact original copy.
25. As an Administrator, I want this to work with a standard Windows-installed thermal printer through the browser dialog, so that I can choose printer hardware later.

## Implementation Decisions

- Treat Sale Receipt and Refund Receipt as distinct customer-facing records. A Cash Refund adds its own receipt and never edits the original sale.
- Use the existing checkout and Cash Refund transaction boundaries to preserve receipt details at the time each transaction commits. Keep the details immutable after recording. Preserve customer-facing Store, transaction, cashier, Register, product, payment, total, and footer values needed for a faithful reprint.
- Render all Sale Receipt entry points from one receipt presentation seam and all Refund Receipt entry points from one refund presentation seam. Reuse shared thermal styling and print behavior for both types.
- Add a paper-width setting to each Register, restricted to 80 mm or 58 mm, with 80 mm as the default. The Administrator maintains it alongside Register details. When a receipt is reopened, use the current printing Register's width; use 80 mm if no current Register can be resolved.
- Use single-column receipt composition, compact type, clear separators, right-aligned money, and multi-line item rows. The on-screen paper preview and print styles use the same content and width. The screen may frame the paper within the application, but that framing and action buttons do not print.
- Keep the existing Sale Receipt content, including verification code and URL, while reorganizing it for thermal width. Do not silently drop long values; wrap them without clipping numeric totals.
- Show a Refund Receipt after a successful Cash Refund and link each entry in My refunds to its saved receipt. Keep access scoped to the Cashier who issued the refund, matching the existing Cash Refund visibility rule.
- Show a clear historical notice on pre-feature Sale Receipts lacking preserved Store details. Do not claim that reconstructed current settings are the original values.
- Use the browser print dialog and installed printer driver. Do not add direct device access or automatic printing. The cashier or printer setup selects the target printer and matching paper profile. The app provides width-aware print CSS; the physical driver controls feed, cutting, and device-specific settings.
- Respect the existing Cashier Shift, Register Lock, Store scope, and receipt authorization rules. Printing and reopening receipts do not initiate another financial transaction.

## Testing Decisions

- Test observable outcomes through the highest existing seams: successful checkout followed by Sale Receipt retrieval, and successful Cash Refund followed by Refund Receipt retrieval. Avoid tests tied to HTML helper internals or storage implementation.
- Extend existing sale receipt, attribution, Cash Refund, and Register administration contract coverage. Verify that a cashier sees only authorized receipts and that checkout and refund transactions remain complete if printing is canceled.
- Verify that transaction-time details remain stable after Store settings, product names, or Register names change. Verify that pre-feature sales show the historical notice and remain printable.
- Verify 80 mm defaulting, 58 mm configuration, and width adaptation on reprint from another Register.
- Check actual browser print previews at both widths with short and long receipts, long product names, discounts, non-cash payment references, and a multi-item partial refund. Confirm readable totals, no horizontal clipping, and no application controls in print output.
- Use an installed thermal printer for a final device check once a model is selected; browser or PDF preview alone cannot confirm feed, cutter, and driver margins.

## Out of Scope

- Selecting or purchasing a printer model.
- Silent printing, automatic printing after checkout or refund, ESC/POS commands, cash drawer opening, cutter control, or a local printer bridge.
- Changing sale, refund, inventory, or payment accounting rules.
- Generating a QR code or adding a new verification service; the existing verification code and URL remain.
- Reconstructing original Store details for older transactions where those values were never saved.

## Further Notes

The existing sale receipt is shared by the post-checkout page, saved transaction view, and receipt modal, but its print target currently uses full page width. The Cash Refund workflow has an internal history table and no customer-facing slip. The Store has not selected a thermal printer. A standard Windows driver and the browser print dialog are the first integration path; direct device printing can be considered later against a selected model. This spec follows the domain terms Sale Receipt, Refund Receipt, Cash Refund, Cashier Shift, and Register and the decision to preserve customer receipt details at transaction time.
