# Preserve customer receipt details at transaction time

New Sale Receipts and Refund Receipts preserve the customer-facing details as they were when the transaction was recorded, so later changes to Store settings, product names, or Register names do not rewrite a reprint's history. Older sales lack those saved details; their reprints use the best available current information and clearly state that original Store details are unavailable. This favors faithful historical records over rendering every receipt from mutable live data.

The paper layout is separate from the preserved receipt details. Each Register has an 80 mm or 58 mm paper width, defaulting to 80 mm, and a reprint adapts to the printer used at reprint time. Cashiers review the thermal preview and print through the browser dialog; checkout and refunds do not start printing automatically.
