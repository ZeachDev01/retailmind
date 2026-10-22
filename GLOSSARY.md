# RetailMind Store Operations

Core domain model and language for RetailMind single-store retail operations and management.

## Language

**Promotion**:
An Administrator-created discount with defined eligibility conditions that a Cashier may explicitly select for a sale. Only one Promotion may apply to a sale.
_Avoid_: Cashier-created discount, automatic discount

**Eligible Promotion**:
A Promotion that is active, within its scheduled dates, and satisfies its product, category, or entire-cart scope and minimum quantity for the current cart.
_Avoid_: Any existing discount, expired promotion

**GCash Service**:
A customer cash-in or cash-out exchange handled by the Store, separate from a Product purchase or its payment.
_Avoid_: Product sale, GCash product payment

**GCash Service Cash**:
The physical money kept in a dedicated cash box or pouch for GCash Services, separate from the product-sale drawer.
_Avoid_: Product-sale cash, wallet balance

**GCash Service Wallet**:
The single Store-owned GCash wallet dedicated to GCash Services. It is separate from personal wallets and accounts receiving Product payments.
_Avoid_: Personal wallet, product-payment account

**Bread Pullout Reminder**:
An expiry-based prompt to prepare bread for supplier collection before its printed expiration date. It does not represent a completed collection.
_Avoid_: Completed pullout, stock deduction, supplier collection schedule

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
An operational period linked to a register and drawer where a cashier performs sales and refunds. It is separate from the cashier's authenticated access to RetailMind.
_Avoid_: Work period, Session

**Fiscal Period**:
A date-bounded reporting and accounting window for the Store, governed by the Administrator. It is separate from a Cashier Shift.
_Avoid_: Cashier Shift, work session, branch fiscal period

**Store Reports**:
Aggregated analytical summaries of business performance (e.g. daily/weekly revenue, category sales, product sales).
_Avoid_: Sales (when referring to the receipt lookup page)

**Stock Issue**:
A Cashier-submitted report that units were Damaged, Missing/Lost, Expired, or Other, reviewed and decided by an Inventory Manager.
_Avoid_: Damage report, damage claim, damage adjustment

**Stock Issue Oversight**:
The read-only audit view used by the Administrator to review Store-wide stock-issue lifecycle history and correction counts without mutating inventory.
_Avoid_: Stock issue approval, inventory adjustment (when referring to administrator oversight)

**Stock Receiving**:
The Inventory Manager's record of goods brought into the Store, including bulk purchases made by the owner at a supermarket. Quantities may be entered as individual units or packs/cases; supplier and receipt details are optional, and no purchase request, purchase order, or Administrator approval is required.
_Avoid_: Purchase approval, stock count (when referring to newly bought goods)

**Replenishment Recommendation**:
A suggestion of which products need more stock and how much to buy, reviewed by the Inventory Manager. It guides buying without placing an order or changing stock; actual purchases may differ from the suggestion.
_Avoid_: Automatic order, required purchase quantity

**Replenishment Receipt**:
A receipt issued by a supplier or larger retailer for goods the Store buys to replenish its inventory. It records the Store's purchases, rather than purchases made by the Store's customers.
_Avoid_: Sale Receipt, customer demand record

**Category Replenishment Forecast**:
A prediction of which product categories the Store is likely to buy during a future period, based on its previous replenishment purchases. It supports the Inventory Manager's buying decisions and does not directly measure customer demand.
_Avoid_: Customer demand forecast (when based only on replenishment receipts)

**Reorder Level**:
The stock quantity, measured in Base Units, at or below which the Store should buy more. It includes expected demand while waiting for new stock plus Safety Stock.
_Avoid_: Order quantity, backup stock

**Safety Stock**:
The extra quantity of a Product kept as a buffer against unexpected demand or delayed replenishment, measured in Base Units. It is included in the Reorder Level and remains available for sale.
_Avoid_: Reserved stock, additional reorder trigger

**Target Stock**:
The desired quantity of a Product to have after replenishment, measured in Base Units. It is distinct from the Reorder Level, which determines when more stock is needed.
_Avoid_: Reorder level, required purchase quantity

**Base Unit**:
The atomic, single physical item sold to retail customers and tracked in inventory counts (e.g. piece, sachet, can, bottle, stick).
_Avoid_: Selling unit, retail unit, loose unit

**Receiving Unit**:
The bulk commercial container or package used when purchasing goods from suppliers or supermarkets (e.g. case, box, ream, carton, pack).
_Avoid_: Bulk unit, supplier unit, container

**Sales Pack**:
A sealed package containing a fixed number of a Product's Base Units, sold at a dedicated price cheaper than buying that number individually. It may have a barcode distinct from the individual piece barcode; unopened Sales Packs and loose pieces are counted separately within the same Product's stock.
_Avoid_: Receiving Unit (when referring to a customer purchase)

**Pack Opening**:
The conversion of an unopened Sales Pack into its fixed number of loose Base Units for individual sale. It changes the form of the stock without changing the total quantity of goods.
_Avoid_: Sale, Stock Receiving (when referring to opening existing stock)

**Prepared Oil Pouch**:
Oil portioned and packaged before sale, counted as individual pouches of a specified volume. The Store sells 250 mL (1/4 litre) pouches for PHP 20 and 500 mL (1/2 litre) pouches for PHP 40.
_Avoid_: Bulk oil, peso amount (when referring to the pouch size)

**Units per Package**:
The fixed multiplier representing the exact number of Base Units contained within one Receiving Unit.
_Avoid_: Pack size, multiplier, conversion rate

**Hosted Demo**:
A client-accessible, non-production RetailMind deployment used to demonstrate workflows and gather feedback. It contains Demo Data and does not represent a commitment to production readiness.
_Avoid_: Production system, live production

**Demo Data**:
Synthetic accounts, products, sales, and inventory records isolated for demonstration. It must not contain real customer, employee, or operational data.
_Avoid_: Production data, client data

**Typical Store Day**:
The end-to-end demonstration scenario: open a Cashier Shift, complete a sale, view its Sale Receipt, perform Stock Receiving, review stock or replenishment information, then review Administrator oversight and reports. Discounts are outside this scenario.
_Avoid_: Feature tour, production workflow

**Workspace**:
The active role context through which a user accesses RetailMind. A multi-role user switches Workspace to perform each role's permitted tasks; assigned privileges are not combined.
_Avoid_: Combined permissions, role bundle
