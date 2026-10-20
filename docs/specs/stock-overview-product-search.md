# Stock Overview product lookup

Confirmed on 2026-10-18 for the Inventory Manager's Stock Overview.

- Show the product table below the Stock Health Distribution and Stock Volume by Category charts when the page opens, with stock movements still reachable.
- Use one case-insensitive search field for product name, brand, SKU, and barcode. Trim surrounding search whitespace and allow partial matches.
- Combine search with Category and the existing stock-status filter. Apply all filters before the existing ten-product pagination; changing a filter returns to page one.
- Preserve batch actions, overlapping stock-status rules, and the Missing Expiration quantity column.
- Show a matching count and a useful empty state. Keep controls labeled and usable on narrow screens.
- Data-column headers toggle ascending/descending sorting across all products before pagination. Use numeric order for prices and quantities, chronological order for expiration dates (missing dates last in either direction), and alphabetical order for text. Search and filters preserve sorting; changing sort returns to page one. Header buttons support keyboard activation and announce direction. Selection and Actions columns stay unsorted.

Per-column search fields and price, quantity, or expiration-range filters are outside this change. This reversible UI decision does not require an ADR or new domain terms.
