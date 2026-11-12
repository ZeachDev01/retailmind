# Cashier history consolidation

Confirmed design, 2026-11-12.

Keep Receipt History for the Administrator, Inventory Manager, and Cashier. Cashiers can search, open, and reprint all Store sale receipts to verify a customer's purchase, including another Cashier's sale. Viewing a receipt grants no refund authority: another Cashier's sale still requires the existing Supervisor override. Preserve the shared receipt endpoint and existing receipt records.

Transaction History stays private to the authenticated Cashier: Sales shows only sales they completed, Cash Refunds shows only refunds they issued, and Drawer Movements and Cashier Shifts retain their existing ownership rules. Request filters cannot select another Cashier's records.

Cash Refunds has From date, To date, and Category filters (All categories / Partially refunded / Fully refunded), with the sale's status shown per row. Status uses all issued Cash Refunds against the original sale, across dates and issuing Cashiers. All refund entries linked to a sale become Fully refunded when its combined refunds reach its original paid total, even if each refund was individually partial. Date filters apply to the refund entry, not the sale date or the date it became fully refunded.

Drawer Movements has From date, To date, and Category filters (All categories / Cash in / Cash out / Safe drop). Filters apply together, persist through pagination and page-size changes, and clear together. Rows retain newest-first ordering.

Verification: `cashier_operational_history_contract.php`, `transaction_history_layout_browser_test.js`, `receipt_history_integration.php`, `receipt_caching_http_test.php`, and the existing Cash Refund authorization contract.
