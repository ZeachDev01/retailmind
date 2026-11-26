# RetailMind Store Operations

Core domain model and language for RetailMind single-store retail operations and management.

## Language

**Super Administrator**:
The single platform-governance account responsible for creating and maintaining ordinary Administrator accounts. It holds one Workspace and never takes store operational Workspaces.
_Avoid_: Super admin, main admin, owner account

**Super Administrator Recovery Email**:
The Super Administrator's verified account email address used to recover access when the login password is forgotten.
_Avoid_: Recovery Account, Recovery Key, store email

**Administrator**:
The ordinary account that governs one Store, including creating Staff Accounts and assigning their Workspaces.
_Avoid_: Admin user, store manager, super admin

**Audit Action Type**:
The kind of event an Administrator can select in Operational Audit, such as Stock received or Cash refund issued. Several records may share an action type while retaining their own details.

**Staff Account**:
A login for a person who works in the Store, holding one or more Workspaces and created by an Administrator.
_Avoid_: User account, employee login, cashier account

**Multi-role Account**:
A Staff Account assigned more than one Workspace. Each Workspace is entered separately; capabilities are never combined.
_Avoid_: Role bundle, combined permissions, role switching

**Receipt Logo**:
The Store's chosen brand image displayed above the receipt details. Each Store has one Receipt Logo; it is not a complete receipt design or template.
_Avoid_: Receipt template, receipt background

**Starter Category**:
A common Product category available when a Store begins with no categories. Staff choose the category that fits each Product and may create other categories.
_Avoid_: Default product category, automatic classification

**Brand Suggestion**:
A familiar brand name offered while entering a Product's optional Brand. It does not restrict or automatically set the Product's Brand.
_Avoid_: Default brand, required brand

**Store PC**:
The single dedicated Windows machine that hosts the live RetailMind installation and its local database.
_Avoid_: Server, cloud host, second live PC

**Store Migration**:
The transfer of the Store's recorded state and uploaded files to another RetailMind installation, which becomes its sole active installation after verification. It supports movement between a local host and web hosting.
_Avoid_: Synchronization, merging Stores

**Store Reopening**:
The authorized operator's explicit resumption of Store operations after Database Restore, once automated verification and human review are complete. Readiness for Store Reopening does not itself resume operations.
_Avoid_: Automatic reopening, completed verification (when referring to resumed operations)

**Verified Encrypted Database Backup**:
A completed encrypted Store package whose contents passed authentication and structural validation at creation time. Its durable completion record remains recovery evidence even after the temporary download is removed.
_Avoid_: Plain SQL backup, unverified backup

**Recovery Key**:
The private encryption key required to authenticate and decrypt a Verified Encrypted Database Backup. It is separate from the database and application files.
_Avoid_: Backup password, database password

**Backup Keyring**:
The Recovery Keys retained by an installation: its Primary Recovery Key plus any Imported Recovery Keys.
_Avoid_: Key vault, key list

**Primary Recovery Key**:
The Recovery Key used to encrypt new Verified Encrypted Database Backups.
_Avoid_: Active key, master key

**Imported Recovery Key**:
A Recovery Key added from another installation so that installation's packages can be opened. It becomes the Primary Recovery Key only when explicitly promoted.
_Avoid_: Foreign key, secondary key

**Scheduled Backup**:
A Verified Encrypted Database Backup created automatically on the Store's weekly schedule without an operator present.
_Avoid_: Cron backup, automatic dump

**Backup Destination**:
The configurable private folder outside the web document root where Scheduled Backups are retained.
_Avoid_: Backup folder, download folder

**Backup Retention**:
The rule that keeps only the newest Scheduled Backup and removes the previous one after the new package is verified.
_Avoid_: Cleanup policy, backup rotation

**Recovery Key Acknowledgement**:
An administrator's confirmation that a copy of a specific Recovery Key is saved separately from its packages.
_Avoid_: Key dismissal, download reminder

**Identity Reconciliation**:
The restoring Administrator or Super Administrator's explicit determination of whether accounts from the source and destination represent the same person before Database Restore. Different people remain separately attributable, and the destination's protected access remains authoritative.
_Avoid_: Automatic account matching, general account merging

**Historical Actor**:
A person whose identity is retained to attribute past Store activity, without a usable login or current role through that identity. A Historical Actor cannot be enabled as an ordinary staff account.
_Avoid_: Disabled Account, deleted user, active staff

**Gross Sales**:
The Store's completed Product sales before discounts and Cash Refunds. Gross Sales does not deduct product costs or operating expenses.
_Avoid_: Net Sales, Gross Profit, earnings

**Net Sales**:
The Store's completed Product sales after discounts, minus issued Cash Refunds. Net Sales does not deduct product costs or operating expenses and is not profit.
_Avoid_: Gross Sales, Gross Profit, Net Profit, earnings

**Gross Profit**:
Net Sales minus the corresponding cost of products sold, adjusted for returns. Gross Profit excludes operating expenses and is not Net Profit or take-home earnings.
_Avoid_: Net Sales, Gross Sales, Net Profit, take-home earnings

**Markup**:
The percentage an Inventory Manager adds on top of a Product's cost while pricing it. It is a pricing aid only: it is not the recorded Gross Profit and not a Gross Profit percentage.
_Avoid_: Gross margin, profit percentage, gross profit rate

**Promotion**:
An Administrator-created discount with defined eligibility conditions that a Cashier may explicitly select for a sale. Only one Promotion may apply to a sale.
_Avoid_: Cashier-created discount, automatic discount

**Eligible Promotion**:
A Promotion that is active, within its scheduled dates, and satisfies its product, category, or entire-cart scope and minimum quantity for the current cart.
_Avoid_: Any existing discount, expired promotion

**GCash Service**:
A customer cash-in or cash-out exchange handled by the Store, separate from a Product purchase or its payment.
_Avoid_: Product sale, GCash product payment

**GCash Service Receipt**:
The customer-facing record of a completed GCash cash-in or cash-out exchange, identifying its principal, service fee, money exchanged, and GCash reference. An unfinished exchange does not qualify for this receipt.
_Avoid_: Sale Receipt, payment verification

**Held GCash Exchange**:
A customer Cash-In or Cash-Out exchange awaiting checking or resolution. Any cash or wallet transfer already made remains part of its money history while the exchange is unfinished.
_Avoid_: Held Product sale, completed exchange

**GCash Service Cash**:
The physical money kept in a dedicated cash box or pouch for GCash Services, separate from the product-sale drawer.
_Avoid_: Product-sale cash, wallet balance

**GCash Service Wallet**:
The single Store-owned GCash wallet dedicated to GCash Services. It is separate from personal wallets and accounts receiving Product payments.
_Avoid_: Personal wallet, product-payment account

**GCash Service Session**:
The period during which one Cashier is responsible for the GCash Service Cash and Wallet, from opening or accepting a handover until closing or handing over custody.
_Avoid_: Cashier Shift, GCash exchange

**Service Fund Movement**:
A recorded addition to or withdrawal from GCash Service Cash or the GCash Service Wallet, such as replenishment, owner capital, a product-drawer transfer, or shortage recovery. It is separate from a customer's Cash-In or Cash-Out exchange.
_Avoid_: Customer transaction, Cash-In (when referring to replenishment)

**GCash Live Custody**:
The physical cash in the service cash box and digital balance in the GCash merchant app for the active shift session. It reflects real-time operational money currently held by the store and is unaffected by historical date range filters.
_Avoid_: Filtered balance, historical balance, period balance

**GCash Handover**:
The recorded transfer of responsibility for GCash Service Cash and the GCash Service Wallet to the incoming Cashier, using counted cash and a checked wallet balance; a normal handover includes all Held GCash Exchanges assigned to the outgoing Cashier, including inherited follow-ups. Earlier shortage liability remains separate, and closing the outgoing Cashier Shift alone does not transfer custody or follow-up responsibility.
_Avoid_: POS shift closure, automatic custody transfer

**GCash Activity Scope**:
The administrator-selected reporting period (such as today, month-to-date, or custom dates) and optional cashier filter applied to completed exchanges, gross fees, and transaction history. It governs aggregate performance metrics, distinct from current live custody funds.
_Avoid_: Session balance, drawer count

**GCash Closing Difference Review**:
The Administrator's recorded check and explanation of a GCash session's cash and wallet shortages or excesses. Both differences must be checked before the review finishes; original amounts remain in history, and review does not mean missing money was returned.
_Avoid_: Waive (in user-facing actions), cash adjustment, recovered shortage

**GCash Follow-up Responsibility**:
A Cashier's accepted responsibility to follow up a specific unfinished GCash exchange, retained across Shift and service-session closure. It preserves the original recorder and money history and does not assign blame for an earlier shortage or mean the Cashier currently holds the service funds.
_Avoid_: Transfer of blame, completed exchange, current fund custody

**Refund Correction Review**:
The Administrator's investigation of a mistake in a completed Cash Refund, with linked correction work assigned to the proper roles. Finishing the investigation does not mean outstanding money or stock corrections are complete, and the original Sale Receipt and refund remain unchanged.
_Avoid_: Refund deletion, edited original receipt, automatic repayment

**Recorded Stock Value**:
The purchase cost recorded for goods remaining in the Store, using their remaining quantities and recorded batch costs. It excludes quantities whose purchase cost is only estimated or missing.
_Avoid_: Latest-price value, earned profit, estimated stock value

**Estimated Stock Value**:
The value of remaining goods without recorded purchase costs, estimated from usable current Product costs and identified separately from Recorded Stock Value. Goods with no usable cost remain unvalued rather than being treated as free.
_Avoid_: Exact stock value, recorded purchase cost, zero-cost stock

**Supplier Pullout**:
The actual collection or return of stock to its Supplier under an agreed return arrangement. It is distinct from preparing stock for collection or discarding damaged goods.
_Avoid_: Expiry alert, automatic stock removal, stock disposal

**Supplier Pullout Eligibility**:
The Inventory Manager's explicit designation, made when adding a Product, that its stock may be returned under its Supplier's return arrangement. Bread, expiry, or wholesale purchasing alone does not establish eligibility.
_Avoid_: Automatic bread eligibility, expiry-based permission

**Bread Pullout Reminder**:
An expiry-based prompt to prepare bread eligible for Supplier Pullout for collection before its printed expiration date. It does not represent a completed collection.
_Avoid_: Completed pullout, stock deduction, supplier collection schedule

**Receipt History**:
The store-wide immutable audit record of customer sale receipts, used by authorized staff, including Cashiers, for lookup, customer reprints, and dispute verification. Viewing another Cashier's receipt does not authorize a Cash Refund against that sale.
_Avoid_: Sales (when referring to the receipt list), Transactions

**Transaction History**:
A Cashier's own recorded sales, issued Cash Refunds, Drawer Movements, and Cashier Shifts. A sale belongs to the Cashier who completed it; a Cash Refund belongs to the Cashier who issued it.
_Avoid_: Store-wide receipt lookup

**Partially Refunded Sale**:
A completed sale whose combined issued Cash Refunds are less than its original paid total, with at least one Cash Refund issued.
_Avoid_: Pending refund, partial payment

**Fully Refunded Sale**:
A completed sale whose combined issued Cash Refunds have reached its original paid total. Several partial Cash Refunds can together fully refund a sale.
_Avoid_: Canceled sale, single full refund

**Sale Receipt**:
The customer-facing proof of purchase generated upon checkout completion, formatted for 80mm or 58mm thermal reprinting.
_Avoid_: Bill, Invoice (when referring to retail customer sales)

**Cash Refund**:
The authorized correction workflow for returning goods and refunding payment against a completed sale within an active Cashier Shift.
_Avoid_: Reversal, Return (when referring to the transaction record)

**Register**:
The named checkout counter associated with a Cashier Shift and its drawer.
_Avoid_: Shifter (when referring to the counter)

**Cashier Shift**:
An operational period linked to a register and drawer where a cashier performs sales and refunds. It is separate from the cashier's authenticated access to RetailMind.
_Avoid_: Work period, Session

**Open Cashier Shift**:
A Cashier Shift that has not been closed, including one opened on an earlier date. Locking the shift or logging out does not close it.
_Avoid_: Logged-in cashier, unlocked shift

**Shift Close Reminder**:
A daily reminder for a Cashier to close their Open Cashier Shift at the Store's configured time. It does not close the shift or replace the normal closing process.
_Avoid_: Automatic shift closure, forced logout

**Previous-day Cashier Shift**:
An Open Cashier Shift whose opening date is earlier than the current Philippine calendar date. It retains its actual opening, activity and closing times.
_Avoid_: Automatically closed shift, backdated shift

**Shift Close Email Reminder**:
A limited email notice to the responsible Cashier and Administrators that a Cashier Shift remains open after its reminder deadline. It provides awareness without closing the shift or confirming inbox receipt.
_Avoid_: Automatic closure, repeated daily shift email

**Drawer Movement**:
A recorded physical addition to or removal from a Cashier Shift's product-sale drawer, identifying where the cash came from or went. It is separate from a Product sale, Cash Refund, or customer GCash exchange.
_Avoid_: Sale, refund, GCash Cash-In

**Expected Drawer Cash**:
The cash that should remain in a Cashier Shift's drawer according to its recorded opening cash, cash sales, additions, removals, and cash refunds. It is an estimate from records, separate from the cash physically counted.
_Avoid_: Counted cash, sales income, actual cash

**Shift Activity**:
The recorded Product sales, issued Cash Refunds and Drawer Movements attributed to a Cashier Shift. A Cash Refund remains an activity even when the original sale occurred in an earlier Shift or used a non-cash payment method.
_Avoid_: Transactions (when referring only to sales), cash-only activity

**Fiscal Year**:
A twelve-month reporting cycle for the Store, measured from its fiscal-year start rather than necessarily January. It is divided into 1, 2, 3, 4, 6, or 12 consecutive Fiscal Periods.
_Avoid_: Calendar year (unless the cycle starts on January 1)

**Fiscal Period**:
A date-bounded reporting and accounting window for the Store, governed by the Administrator. It is separate from a Cashier Shift.
_Avoid_: Cashier Shift, work session, branch fiscal period

**Scheduled Period**:
A Fiscal Period whose dates are reserved in advance but which accepts no transactions until its start date is reached and it is opened, manually or by Automatic Rollover.
_Avoid_: Draft period, upcoming period, pending period

**Fiscal Period Closing Reminder**:
A notice to affected staff that a Fiscal Period is nearing its end and unfinished work, including open Cashier Shifts and GCash Service Sessions, needs attention before period closure. The notice does not close the period or complete reconciliation.
_Avoid_: Automatic Rollover, shift closing reminder

**Period Schedule**:
A contiguous set of Fiscal Periods spanning one Fiscal Year, generated together from the day after the latest period ends or from today when no periods exist. Generating a set is a single all-or-nothing Administrator action.
_Avoid_: Recurring period, auto-generated periods, fiscal calendar

**Automatic Rollover**:
An opt-in Store Setting that closes a Fiscal Period once its end date has passed and opens the next Scheduled Period. Closure prerequisites still block it, and it never creates, skips, or invents a period. Generating a Period Schedule turns it on once; an Administrator may turn it off again.
_Avoid_: Auto-close, auto-renew, recurring period

**Store Reports**:
Aggregated analytical summaries of business performance (e.g. daily/weekly revenue, category sales, product sales).
_Avoid_: Sales (when referring to the receipt lookup page)

**Stock Issue**:
A record of units affected by damage, loss, expiry, or another stock issue. A Cashier reports it for an Inventory Manager's decision, or an Inventory Manager records the confirmed issue directly.
_Avoid_: Damage report, damage claim, damage adjustment

**Stock Issue Oversight**:
The read-only audit view used by the Administrator to review Store-wide stock-issue lifecycle history and correction counts without mutating inventory.
_Avoid_: Stock issue approval, inventory adjustment (when referring to administrator oversight)

**Stock Issue Clarification**:
A historical question or reply about an existing Stock Issue, retained as read-only evidence. New Stock Issues are recorded or decided without a clarification conversation.
_Avoid_: Edit report, correction (when referring only to a question or reply)

**Stock Issue Correction**:
A change to the reported details of a Stock Issue, such as its issue type, stock form, quantity, or reason.
_Avoid_: Reply (when referring to changing report details)

**Verified Stock Issue Quantity**:
The number of affected units confirmed by the Inventory Manager when recording or reviewing a Stock Issue. It may differ from the Cashier's reported quantity and is the quantity authorized for stock deduction.
_Avoid_: Reported quantity (when referring to the manager's verified quantity)

**Reported Stock Issue Quantity**:
The quantity originally submitted by the Cashier for a Stock Issue, preserved after submission. It is distinct from the quantity verified by the Inventory Manager.
_Avoid_: Verified quantity (when referring to the Cashier's original report)

**Stock Receiving**:
The Inventory Manager's record of goods brought into the Store, including bulk purchases made by the owner at a supermarket. Quantities may be entered as individual units or packs/cases; supplier and receipt details are optional, and no purchase request, purchase order, or Administrator approval is required. Only good items are recorded: damaged or rejected goods stay with the supplier and enter no stock, and damage found after receiving is reported as a Stock Issue.
_Avoid_: Purchase approval, stock count (when referring to newly bought goods)

**Shopping Entry**:
A saved record of goods brought into the Store together through Stock Receiving. It can contain multiple products or batches, with an optional supplier receipt reference.

**Receive Quantity**:
The number of good base units accepted into Store stock in a Shopping Entry. Damaged or rejected goods are excluded, including in historical entries that recorded them separately.
_Avoid_: Delivered quantity (when referring to goods accepted into stock)

**Replenishment Recommendation**:
A suggestion of which products need more stock and how much to buy, reviewed by the Inventory Manager. It guides buying without placing an order or changing stock; actual purchases may differ from the suggestion.
_Avoid_: Automatic order, required purchase quantity

**Stock Movement**:
The recorded inflow and outflow of a Product's stock over a stated period, reviewed alongside remaining stock. Recorded sales help the Inventory Manager identify products with little or no recorded sales; they do not guarantee future demand or when stock will run out.
_Avoid_: Expiry review, stock depletion forecast

**Sales Frequency**:
The number of distinct days within a stated period on which a Product has recorded customer sales. Multiple purchases on the same day count as one day; the quantity sold is measured separately.
_Avoid_: Receipt count, customer count, quantity sold

**Stock Risk**:
An indication that stock may tie up the Store's money or become a loss because it sells slowly, exceeds likely needs, or approaches expiration. It calls for review rather than declaring goods unsellable or requiring a purchase or disposal.
_Avoid_: Dead Stock (when referring to the broader range of stock risks)

**Dead Stock**:
A Product with stock remaining now and no recorded customer sales during a complete preceding 90-day product history. This classification does not itself require disposal or a stock adjustment.
_Avoid_: Expired stock, automatic disposal

**Limited History**:
A Product whose recorded history does not cover the full 90-day Stock Movement review period. Its recorded sales remain visible, but a complete movement judgment is unavailable.
_Avoid_: Dead Stock, zero demand

**Product History**:
The span of a Product's recorded existence within the Stock Movement review period. It is sufficient when it covers the full 90 days and limited when it covers less or its starting date is unknown.
_Avoid_: Sales quantity, sales frequency

**Forecast Quantity**:
The estimated number of a Product's Base Units expected to sell over a stated future period. It is distinct from the suggested purchase quantity in a Replenishment Recommendation.
_Avoid_: Suggested purchase quantity, guaranteed sales, stock quantity

**Usable History Day**:
A day when the Store is open and a Product's demand is supported by recorded evidence, including genuine zero sales. Closures, stockouts, and unknown observations do not count.
_Avoid_: Day with sales, day since installation, active day

**Store Training Eligibility**:
Whether a Product's Store history satisfies the requirements for model training, including sufficient usable history, supported recent observations, and chronological evaluation coverage. Eligibility does not mean a model is approved or a usable Demand Forecast exists.
_Avoid_: Forecast availability, model approval, history level

**Eligible Product**:
A Product whose Store history currently satisfies Store Training Eligibility and may be included in model training.
_Avoid_: Training candidate, approved Product

**Training Candidate**:
A model produced by a completed training run and awaiting quality review and explicit approval. It is not a forecast and is not the approved Store model.
_Avoid_: Eligible Product, approved model

**Model Retraining**:
The creation of a new Training Candidate from eligible Store history. It does not itself approve the candidate or replace the approved Store model.
_Avoid_: Forecast Generation, model activation

**Forecast Generation**:
The production of estimated future Product sales using the approved Store model. It is separate from Model Retraining.
_Avoid_: Model training, model approval

**Candidate Error Limit**:
The maximum permitted prediction error for a Training Candidate over a stated forecast period. Meeting the limit alone does not qualify or approve the candidate.
_Avoid_: Accuracy guarantee, model tuning parameter

**Training Readiness Notice**:
An operational notice explaining whether Store Products are ready for model training, including when no Product currently satisfies Store Training Eligibility.
_Avoid_: Training failure, model approval notice

**Forecast Cold Start**:
The period when the Store has no approved forecasting model and no Product yet satisfies Store Training Eligibility. Forecasting has not failed; it has not started, and no alert is raised.
_Avoid_: Forecasting failure, cold start error, setup failure

**Blocked Forecast Job**:
A recorded forecast or model-training attempt that could not start because a prerequisite is missing. It records a waiting condition, not a failed run, and never raises a Forecasting Failure.
_Avoid_: Failed forecast, retry failure, exhausted job

**Stockout Day**:
A Store trading day when a Product was unavailable for sale during part or all of the day. Recorded sales remain valid, but do not reveal how many additional units customers might have bought.
_Avoid_: Zero demand, complete demand observation

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

**Barcode Lookup Suggestion**:
Draft Product details found using a scanned barcode, such as name, brand, declared package size, front photo, and a suggested Store category. Before saving the Product, the Inventory Manager verifies the details against the physical item, chooses the Store category, and sets the prices.

**Receiving Unit**:
The bulk commercial container or package used when purchasing goods from suppliers or supermarkets (e.g. case, box, ream, carton, pack).
_Avoid_: Bulk unit, supplier unit, container

**Cost Price per Piece**:
The purchase cost of one Base Unit, entered directly or calculated from the purchase package cost divided by its number of Base Units. It does not imply that the Product is sold as a Sales Pack.
_Avoid_: Pack selling price, selling price

**Selling Price per Piece**:
The price charged to a customer for one Base Unit. Markup may help determine it, but the Inventory Manager chooses the price.
_Avoid_: Cost price, Sales Pack price

**Confirmed Zero Cost**:
A Product purchase cost explicitly confirmed as zero because the goods were received free. It is distinct from a cost that has not yet been entered or verified.
_Avoid_: Missing cost, unknown cost, default zero cost

**Sales Pack**:
A sealed package containing a fixed number of a Product's Base Units, sold at a dedicated price cheaper than buying that number individually. It may have a barcode distinct from the individual piece barcode; unopened Sales Packs and loose pieces are counted separately within the same Product's stock.
_Avoid_: Receiving Unit (when referring to a customer purchase)

**Saleable Stock**:
A Product's remaining stock eligible for sale, excluding expired goods. It includes unopened Sales Packs and loose Base Units where applicable.
_Avoid_: Total physical stock (when expired goods remain)

**Stock Count Correction**:
A correction of a Product's recorded stock to its verified physical count, including unopened Sales Packs and loose pieces where applicable. It covers incorrect starting quantities and differences found during inventory checks, with a recorded reason.
_Avoid_: Emergency Stock Adjustment, Physical Sales Pack Reconciliation

**Inventory Count**:
A recorded check of a Product's actual physical stock against its recorded stock, by batch and by unopened Sales Packs and loose pieces where applicable. A verified difference results in a Stock Count Correction.
_Avoid_: Stock Receiving (when referring to checking existing stock)

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

**Tunnel Session**:
A RetailMind session opened through the Store's tunnel link, away from the Store computer. All assigned Workspaces, including Super Administrator, have the same capabilities as on localhost; each user retains only their assigned Workspace permissions.
_Avoid_: Remote monitoring, read-only remote access, owner-only monitoring

**Stock Status Indicator**:
A standardized color-coded visual indicator (🔴 Out of stock, 🟡 Low stock, 🟢 In stock/Available, 🔵 Needs replenishment) displayed across inventory dropdowns and status badges to convey stock health without altering underlying filter values.
_Avoid_: Custom icon component, unstandardized badge color


**Attention Item**:
An actionable store-wide or platform-level condition (such as overdue high-value Stock Issues, unreviewed cash variances, or approaching Fiscal Period closures) highlighted on an Administrator or Super Administrator dashboard for review. Normal waiting states and records of routine activity are not Attention Items.
_Avoid_: Notification (when referring to dashboard alerts), Task, To-do

**Notification Acknowledgement**:
A staff member's read status for a Notification in the active Workspace. Marking a Notification as read preserves its history and the current status of any underlying condition.
_Avoid_: Clear (when the intent is to mark as read), resolve alert

**Notification Dismissal**:
A staff member's choice to hide a read Notification that requires no further action from their active Workspace's All list. The linked business record and its history remain available.
_Avoid_: Delete notification, resolve alert

**Store Email Alert**:
An automated transactional notification sent to designated store email addresses regarding operational events—such as critical system health, security actions, or consolidated stock summaries—once verified by a successful test email to the Store's official email address. It delivers actionable operational awareness outside the store without modifying recorded stock or shift custody.
_Avoid_: Marketing newsletter, promotional email, SMS alert, per-product stock email

**Daily Stock Brief**:
A consolidated operational inventory report generated and emailed on a schedule (such as morning opening or evening closing) to the Store's official email address and opted-in Inventory Managers and Administrators when at least one stock or expiry condition requires attention. It groups out-of-stock items, low-stock reorder thresholds, and expiring batches into a single high-density summary instead of sending separate per-product notifications.
_Avoid_: Individual stock alert, marketing digest, full catalog export, empty status email
