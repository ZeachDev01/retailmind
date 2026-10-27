# Stock status visual indicators in filter dropdowns

## Context
Inventory operators navigating stock filter dropdowns across Replenishment Recommendations (`replenishment_queue.php`), Products catalog (`products.php`), and Inventory Overview (`inventory_overview.php`) required quick visual distinction between critical stock states (`Out of stock`, `Low stock`, `In stock` / `Available`, and `Needs replenishment`). Native HTML `<option>` elements on modern desktop/mobile browsers do not render custom markup or CSS styling.

## Decision
1. Standardize on native Unicode circle indicators directly within the `<option>` label text:
   - 🔴 Out of stock
   - 🟡 Low stock
   - 🟢 In stock / Available
   - 🔵 Needs replenishment
2. Preserve clean labels without dots for neutral options (`All statuses`, `All Products`).
3. Explicitly retain underlying `value` attributes (`value="Out of stock"`, `value="Low stock"`, `value="In stock"`, `value="available"`, etc.) so existing client-side filters, API contracts, and browser tests remain unaffected.

