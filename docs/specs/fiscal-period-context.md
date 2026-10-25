# Fiscal period design

Design decisions confirmed; application implementation has not been requested.

Confirmed on 2026-10-22:

- A Fiscal Period is a reporting and accounting window, separate from a Cashier Shift.
- A transaction requires an open Fiscal Period covering its date. A date outside every period must not bypass protection.
- The owner defines each period's date range; periods are not restricted to calendar months. Fiscal Period management remains in the Administrator workspace.
- At most one Fiscal Period may be open, and date ranges must not overlap, including closed periods.
- The Administrator explicitly closes the current period and opens the next; closure is not automatic.
- Closing requires every Cashier Shift to be closed.
- A later refund belongs to the open period covering the refund date. The original sale and its closed period remain unchanged.
- Each next period starts the calendar day after the previous period ends; date ranges have no gaps.
- A period cannot close before its end date. An incorrect end date requires a controlled correction before closure.
- Closed periods cannot reopen. Corrections are new, traceable entries in the current open period and preserve the original records.
- Open-period enforcement covers all recorded money and stock movements, including GCash and drawer cash movements. Viewing reports and closing Cashier Shifts remain available.
- Pending Inventory Counts dated within a period must be approved or rejected before that period closes.
- Existing transactions remain unchanged. Conflicting existing period ranges require review and resolution before strict enforcement starts; transactions and historical dates are never silently rewritten.
- Only the Administrator may correct an open period's end date, with an audit record. The correction must not exclude existing transactions, overlap another period, or break date continuity. Closed period dates remain fixed.
- The Administrator chooses the first period's dates, which must include today before trading starts. Subsequent periods follow the continuity rule; existing history remains unchanged.
- All GCash sessions must be closed and reconciled before a Fiscal Period closes. Session closure remains available when transactions are blocked.

The design interview has no remaining policy questions. Before implementation, inspect actual existing period conflicts and verify every affected money and stock write path, including settlement during shift and GCash session closure.
