# Receipt logo

Confirmed with the Store owner on 2026-11-05.

- The Administrator maintains one Receipt Logo in Store Settings: upload, replace, view the saved print preview, or remove it. Existing authorization, local-request restrictions, CSRF checks, write coordination, and audit attribution apply.
- Accept actual JPG/PNG uploads up to 2 MB, 4096 pixels per side, and 4 million pixels. Reject invalid uploads without changing the saved logo.
- Normalize to an opaque black-and-white PNG, preserving aspect ratio within 384 × 192 pixels. Show the saved result so the Administrator can check contrast.
- Display the logo above Store details on Sale, Refund, and GCash Service Receipts. Fit the existing 58/80 mm layouts and wait for image decoding before printing.
- Capture the logo when each transaction completes. Replacing or removing it affects future receipts only. Existing snapshots without a logo and legacy sales remain without a logo.
- Keep the bounded PNG data URI in Store Settings and each receipt snapshot. Existing database backups and encrypted Store packages include current and historical logos without external upload files or a schema migration.
- Physical printer output remains a hardware acceptance check; browser layout and print-to-PDF checks cannot prove thermal contrast or driver settings.

This extends [receipt preservation](../adr/0006-preserve-customer-receipt-details.md). Receipt design/template uploads are outside this change.
