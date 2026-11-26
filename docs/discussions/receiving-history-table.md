# Receiving history table

Confirmed on 2026-11-26: keep the existing My Receiving History appearance and simplify the entry table to Product, SKU, Receive, Purchase cost, and Batch / Expiry.

Receive means good base units accepted into stock. Pack or package conversion appears beneath that quantity and uses the recorded conversion size. Historical damaged goods do not contribute to either the Receive total or its conversion.

Remove Delivered, Damaged, Quantity Mode & Conversion, and Discrepancy / Notes as separate entry columns; remove Damaged from the history list and rename Accepted to Receive in both tables. These columns are redundant for the current good-items-only receiving workflow. Existing historical data stays stored. Replacement references remain beneath the product name, and entry-level notes remain in the entry summary.

Keep product/SKU/Receive/expiry sorting, filtered navigation, pagination, purchase-cost details, and horizontal table scrolling on narrow screens. Reuse the list's spacing and existing theme tokens.

Verification: `npm run check`, PHP lint, and `RUN_DB_TESTS=1 node src/backend/tests/receiving_history_browser_test.js` passed. The browser suite uses its own disposable database and covers historical damaged pack/package conversions, 100-item sorting/paging, filtered navigation, keyboard access, contrast, both themes, and 320/390/1280px layouts. Desktop and narrow dark-theme captures were inspected under ignored `output/playwright/receiving-history/`.

[TestSprite run 2a6c2d54](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/dbec6ae3-5f0d-4a89-b114-3b6b6fa6e041/test/2a6c2d54-254f-45cc-a5f8-e1fee94bc0ac) passed 12/12 steps against a disposable Store. Physical mobile devices remain unverified. The view audit retains the existing duplicate `open-register` ID warning in `cashier/shifts.php`.
