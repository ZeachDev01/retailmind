# RetailMind Store Operations

Core domain model and language for RetailMind single-store retail operations and management.

## Language

**Receipt History**:
The store-wide immutable audit record of customer sale receipts, used for lookup, customer reprints, and dispute verification.
_Avoid_: Sales (when referring to the receipt list), Transactions

**Sale Receipt**:
The customer-facing proof of purchase generated upon checkout completion, formatted for 80mm or 58mm thermal reprinting.
_Avoid_: Bill, Invoice (when referring to retail customer sales)

**Cash Refund**:
The authorized correction workflow for returning goods and refunding payment against a completed sale within an active Cashier Shift.
_Avoid_: Reversal, Return (when referring to the transaction record)

**Cashier Shift**:
An operational session linked to a register and drawer where a cashier performs sales and refunds.
_Avoid_: Work period, Session

**Store Reports**:
Aggregated analytical summaries of business performance (e.g. daily/weekly revenue, category sales, product sales).
_Avoid_: Sales (when referring to the receipt lookup page)
