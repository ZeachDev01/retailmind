---
status: accepted
---

# Own expiration on stock batches and preserve supplier replacement movements

Starting stock and later deliveries of the same Product can expire on different dates, especially short-shelf-life bread. Use each Stock Batch as the authority for its expiration and remaining quantity: Add Product creates an Opening Batch when it includes starting stock, subsequent Stock Receiving creates separate batches, and product summaries derive expiration from remaining batches. Receiving must not replace historical batch dates or present its newest expiration as the entire product's expiration.

Supplier pullouts and fresh replacements are distinct stock movements. Preserve the old batch, deduct the actual returned quantity, and receive replacements into a new batch; this retains history when quantities, dates, or delivery times differ. This extends ADR-0011's direct receiving and Inventory Control boundaries without restoring procurement requests, purchase orders, or supplier administration.

## Trade-off

A product-level expiration is simpler to display but cannot represent mixed batches. Rewriting an old batch during replacement is simpler to enter but destroys evidence of what was sold, returned, and received. Batch ownership requires consistent balance handling across sales, refunds, counts, Stock Issues, and supplier returns; that cost is necessary for trustworthy expiration and stock summaries.

## Consequences

- Reuse existing batch storage and FEFO allocation. Keep expired physical stock visible separately from saleable quantities and do not deduct it merely because the date passes.
- Stock Overview summaries and a View Batches modal expose the underlying quantities without repeating product rows. Next Expiration excludes depleted and expired batches; optional missing dates remain explicit as **Not recorded**.
- A confirmed **Missing Expiration** filter identifies products with remaining undated batches, including mixed dated/undated stock, and shows quantity without a date. It reuses View Batches and excludes depleted stock; missing warning overrides are a separate concept.
- Inventory Managers may set a product warning lead time, allowing bread to warn two calendar days before expiration. It controls overview, FEFO urgency, and future notifications, replacing global milestones for that product; an unset override preserves existing channel defaults and global governance. Expiration day remains eligible through the end of that Asia/Manila calendar day.
- Inventory Managers record completed supplier pullouts directly without an additional approval stage; Administrators retain oversight. Quantity changes require a transaction, concurrency checks, staff attribution, and history. Separately received partial or later replacements link to the pullout; financial settlement remains outside scope.
- Audit legacy product expiration readers and missing or mismatched batches before switching the source of truth. Preserve uncertain legacy dates rather than manufacturing batch history.

See [the context and agreed missing-expiration filter](../batch-expiration-context.md). The user accepted the six policy recommendations and the filter design on 2026-10-18. Acceptance records the design; application implementation has not been requested or performed in this documentation task.
