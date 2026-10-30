# Beta questionnaire quality test plan

Later execution: [transaction/browser results](beta-questionnaire-transaction-browser-20261030.md), using authorized disposable fixtures. The thermal printer and barcode scanner are accepted as user-reported passes. Read-only scope and Not run counts below describe the initial planning round; use the follow-up for executed supporting checks and remaining gaps.

Prepared: 2026-10-30 (Asia/Manila). Scope agreed with the user: plan and report only, software checks only, and no application fixes. Execute only checks that cannot change Store data in this round. Transaction tests wait for identification of the designated test Store and authorization for its specific changes.

Source: supplied `Pasted text.txt`, attachment `dea07a9e-3b8c-4c97-b5a0-818581b06b37`, containing the Admin, Inventory Manager, and Cashier Beta Evaluation Questionnaires. All 120 statements below are preserved in their original order and wording. This plan follows the questionnaire's quality headings; it does not establish formal ISO certification or validate the questionnaire itself.

[Current execution report](beta-questionnaire-quality-report-20261030.md). Existing vocabulary: [Store/roles](../CONTEXT.md), [operations](../../GLOSSARY.md). GCash Service is separate from a Product purchase/payment; Gross Profit excludes operating expenses. No new domain term or architectural decision is needed.

## Status and acceptance

Every questionnaire case currently has status **Not run**. Static checks provide supporting evidence only. They do not establish completed workflows, human ratings, hardware compatibility, or an overall pass. Planned expected results are not observed results.

For later execution use **Pass** only when every expected result is observed with evidence; **Fail** for an observed mismatch; **Blocked** for a missing prerequisite; **Not run** before execution. Hardware and respondent portions are **Deferred: outside this software-only round**. Do not count deferred or blocked cases as passed. Do not fill in respondent names, signatures, or 1-5 ratings on their behalf.

Software release acceptance requires all applicable software cases passing, no unresolved security/data-integrity failure, and all blocking checks resolved. A claim that the entire questionnaire passed additionally requires its deferred physical/user observations. Regression-after-fix and after-update cases require an actual recorded defect or before/after build pair; do not invent either. No numerical questionnaire score threshold was approved.

## Execution prerequisites and evidence

Before any future transaction execution, record test Store URL/installation/database identity, owner, explicit allowed mutations, build/dirty-tree state, date/timezone, role-specific test accounts (no secrets in reports), browser/version, OS/device/viewport, network, and recovery/cleanup procedure. No store has been identified yet. RetailMind operates one Store per installation; a designated test Store is a separate test installation, not a branch selector.

Record each case ID, tester/date, actual steps, expected and actual values, result, screenshot/export or redacted request/response/log, duration where relevant, and defect/retest link. For denial tests record before/after state as well as the HTTP/UI result. Repeat duplicate submissions where the case expects one stock or money effect. Do not expose credentials or unnecessary personal data in artifacts.

Use deterministic prepared records: isolated test staff, an open Fiscal Period for the test date, a disposable Register/Shift, Products with known barcode/unit/stock/cost, separate issue approval and rejection samples, a Count, valid and invalid CSVs, forecast state examples, and an independently calculated money/stock ledger. Future transaction cases change the designated test data; none are authorized in this read-only round.

Suggested arithmetic fixture, to verify against current unit/cost rules before execution: Product A has 20 pieces at PHP 10 purchase cost and PHP 15 selling price; receive 5 at PHP 10; sell 2 for PHP 30 with PHP 50 tender and PHP 20 change. Expected stock: 23; Gross Sales and Net Sales: PHP 30; cost sold: PHP 20; Gross Profit: PHP 10, provided there are no discounts/refunds/other movements and the application has complete cost provenance. Approve a separate 1-piece issue to reach 22; reject another issue with no additional deduction. Test refunds separately with their actual restocking policy. GCash fees and funds use a separate prepared ledger and the configured fee schedule.

## Proposed timing criteria

These are planning targets, not approved release service levels: search/cart feedback <=2 seconds; saves, checkout-to-receipt availability, and forecast request acknowledgment <=3 seconds; dashboards, reports, history, and audit filters <=5 seconds. Job completion time is separate from acknowledgment. CSV import has no pass/fail duration until its bytes/row count and budget are approved.

For each timed case record one cold run and five subsequent runs, all durations, record count, environment, network, and success/correctness. Compare every measured run with its proposed threshold; report an overrun as a target miss, not an approved-release failure. Synthetic source checks cannot measure these timings.

Software viewport candidates: 320, 390, 768, 1024, and 1440px widths; record height, zoom, orientation, focus/keyboard access, clipping, and retained state. These are test candidates pending confirmation of supported sizes. Record two actual supported browser versions for cross-browser cases; Chromium-only emulation cannot establish second-engine or physical-device behavior. Physical scanner/printer/device/computer tests and ease/clarity/satisfaction ratings remain deferred.

## Test cases

IDs preserve role, quality characteristic, and source question number. Each row gives executable steps and an observable expected result. All rows: **Not run**; see the report for supporting source checks and blockers.

### Admin

#### Functional Suitability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| ADM-FS-1 | I could create, update and deactivate test staff accounts and assign the intended store roles. | Create, edit, assign allowed roles, and deactivate a dedicated staff account; reopen it. | Saved values and roles match; deactivated account cannot sign in. |
| ADM-FS-2 | The sales and gross profit shown for the selected dates matched the prepared test totals. | Select the seeded reporting dates and compare sales and Gross Profit with the independent ledger. | Every displayed amount matches the ledger and documented date basis. |
| ADM-FS-3 | I could complete the assigned oversight tasks for cashier shifts, GCash records, fiscal periods and operational audit records. | Review one Shift, GCash Service, Fiscal Period, and Operational Audit scenario. | All four assigned oversight outcomes and their linked records are correct. |
| ADM-FS-4 | I could create and download a Database Backup for the assigned test. | Create a Database Backup and download it. | Completed backup has a listed identifier and a nonempty downloadable artifact; restoration is not implied. |
| ADM-FS-5 | I could request forecast generation and identify whether the job was queued, completed or unsuccessful. | Request forecast generation and inspect queued, completed, and unsuccessful examples. | Request and each job state are distinguishable; completion exposes results or an explained absence. |

#### Performance Efficiency

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| ADM-PE-1 | Store Overview loaded quickly enough for me to review the selected reporting period. | Time Store Overview loads for the prepared period. | Proposed dashboard threshold is met in every recorded run. |
| ADM-PE-2 | Saving the assigned staff, register or store setting changes completed without a disruptive wait. | Time separate staff, Register, and Store Settings saves. | Each save meets the proposed save threshold and persists correctly. |
| ADM-PE-3 | Store reports opened quickly enough for me to continue reviewing store operations. | Time opening each assigned Store report. | Each meets the proposed report threshold with correct records. |
| ADM-PE-4 | Operational Audit filters returned the assigned records without a disruptive wait. | Time Operational Audit filters for known actors, dates, and actions. | Correct records return within the proposed report/filter threshold. |
| ADM-PE-5 | Requesting forecast generation returned a status message quickly enough for me to continue my other tasks. | Time the forecast request acknowledgment, separately from job execution. | Status acknowledgment meets the proposed save/status threshold; no model-runtime claim. |

#### Compatibility

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| ADM-CO-1 | Using another store application alongside RetailMind did not interrupt my administrative task. | Keep the designated companion store application active while reviewing an Admin report. | Both tasks retain state and finish; record actual companion application/version. |
| ADM-CO-2 | A report exported during my test opened correctly in the viewer used by the store. | Export a prepared report and open it in the designated viewer. | Headings, dates, row counts, and exact amounts match the report; viewer identity recorded. |
| ADM-CO-3 | Records created by the Cashier or Inventory Manager appeared consistently in the administrative reports I reviewed. | Complete a prepared Cashier sale and Inventory Manager receiving, then review Admin reports. | Linked records and totals agree across all three roles without duplicate movements. |
| ADM-CO-4 | The administrative pages worked in the supported browser used for my test. | Perform Admin navigation and report viewing in the selected supported browser. | Required pages, controls, and results work without uncaught errors. |
| ADM-CO-5 | The report I printed preserved readable headings, dates and amounts. | Inspect report print preview; defer physical printing. | Software preview preserves readable headings, dates, and amounts; physical print result remains unverified. |

#### Usability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| ADM-US-1 | The Admin menus made it easy to find Store Staff, Registers, Store Settings and Operational Audit. | Navigate to Store Staff, Registers, Store Settings, and Operational Audit. | Each destination is labelled and reachable; respondent ease rating remains uncollected. |
| ADM-US-2 | The displayed dates, sales amounts and gross profit status were clear enough to interpret. | Review dated sales and Gross Profit including an unavailable-cost scenario. | Dates, currency, and incomplete/unavailable profit state are explicit; respondent clarity remains uncollected. |
| ADM-US-3 | The system clearly explained validation errors or unavailable information during my administrative tasks. | Submit invalid staff/settings data and open an unavailable report scenario. | Specific corrective feedback appears without false success or exposing sensitive details. |
| ADM-US-4 | The fiscal period status made it clear which reporting period was open or closed. | Inspect an open and a closed Fiscal Period. | Each period and its dates/status are clearly distinguished. |
| ADM-US-5 | The Database Backup page made it clear how to create and download a backup. | Inspect Database Backup instructions and controls. | Create and download actions and their states are identifiable; respondent clarity remains uncollected. |

#### Reliability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| ADM-RE-1 | The staff account and store setting changes I saved remained correct when I reopened them. | Save staff and settings changes, reload, sign in again, and reopen. | Exact intended values persist; unrelated values remain intact. |
| ADM-RE-2 | A closed test fiscal period prevented the assigned attempt to change protected records. | Close the prepared Fiscal Period and attempt each assigned protected write. | Server rejects the writes and original protected records remain unchanged. |
| ADM-RE-3 | The Database Backup I created appeared in the backup list and could be downloaded. | Create a Database Backup, reload the list, and download the same entry. | Same completed backup remains listed and download succeeds. |
| ADM-RE-4 | The same report filters returned consistent totals when no test records had changed. | Repeat identical report filters without changing records. | Rows and totals stay identical across repeated requests. |
| ADM-RE-5 | The completed forecast job displayed its results or an understandable unavailable status when I reopened the page. | Reopen a completed forecast job after sign-in/reload. | Saved results or an explicit unavailable explanation appear for the same job. |

#### Security

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| ADM-SE-1 | An incorrect password was rejected, and signing out required me to sign in again to reopen protected pages. | Try an incorrect password, then sign in, sign out, and request a protected page directly. | Wrong password is rejected and the logged-out session cannot read the protected page. |
| ADM-SE-2 | My Admin workspace prevented direct stock editing and cashier checkout when I tested those access boundaries. | As Admin request stock-edit and checkout pages/endpoints directly. | UI and server deny mutations; stock and sales remain unchanged. |
| ADM-SE-3 | The assigned administrative action appeared in Operational Audit with the correct user and action details. | Perform an allowed administrative action and find its Operational Audit record. | Correct actor, action, target, timestamp, and outcome are recorded. |
| ADM-SE-4 | The staff role assignment screen restricted my choices to the store roles allowed for Admin. | Inspect role options and submit a forged prohibited role assignment. | Only allowed Store roles can be assigned; server rejects prohibited roles. |
| ADM-SE-5 | My Admin workspace blocked the assigned attempt to restore a database or retrain a forecasting model. | As Admin request Database Restore and model-retraining endpoints. | Both actions are denied by server authorization; no restore or retraining begins. |

#### Maintainability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| ADM-MA-1 | I could change an allowed store setting without needing a program change. | Change an allowed Store setting through its screen. | New value persists and applies without editing program files. |
| ADM-MA-2 | After an assigned configuration change, the affected administrative task worked as expected when I repeated it. | Repeat an affected administrative task before and after the prepared setting change. | Task uses the intended setting and still produces correct results. |
| ADM-MA-3 | After a reported administrative defect was corrected, I could repeat the affected task successfully. | Use a documented administrative defect and its corrected build to replay the original steps. | Original failure is reproduced on the earlier build and absent on the corrected build; otherwise blocked for missing defect/build. |
| ADM-MA-4 | After I changed a test staff role, signing in with that account showed the intended workspace. | Change a test staff role and sign in freshly with that account. | Intended workspace and permissions apply; prior permissions do not remain usable. |
| ADM-MA-5 | After a tested system update, I could still reopen an existing administrative report with its expected values. | Save a report baseline, apply an approved update in the test environment, and reopen it. | Historical records and expected amounts remain correct; blocked until update/build pair exists. |

#### Portability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| ADM-PO-1 | I could sign in and reopen the required administrative pages in a second supported browser. | Sign in and repeat required Admin page tasks in a second approved browser. | Same records and permissions work; record both engine/browser versions. |
| ADM-PO-2 | I could review the required administrative records on another supported device using the same store data. | Review the same Admin records on a second supported device. | Same Store records and permissions appear; physical-device evidence deferred this round. |
| ADM-PO-3 | The Admin pages remained readable and their required controls usable at the supported screen sizes I tested. | Inspect Admin tasks at 320, 390, 768, 1024, and 1440px software viewports. | Required controls and text are usable without clipping; supported-size list still needs owner confirmation. |
| ADM-PO-4 | I could review an administrative report after signing in again on another supported store computer. | Sign in on another approved Store computer and reopen the same report. | Same report values appear; actual second-computer check deferred this round. |
| ADM-PO-5 | I could complete the assigned administrative task after resizing the supported browser window. | Resize the browser while reviewing a report and using its filters. | Required actions remain usable and chosen filters/state survive resizing. |

### Inventory Manager

#### Functional Suitability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| INV-FS-1 | I could save the intended product details, barcode, selling price and inventory planning values. | Save a Product with known barcode, selling price, and planning values; reopen it. | Exact intended values persist and barcode resolves to that Product. |
| INV-FS-2 | Receiving the test stock recorded the expected quantity and purchase cost and updated the displayed stock correctly. | Receive the prepared Product quantity with a known purchase cost. | Saved receiving quantity/cost match; stock increases exactly once by the received quantity. |
| INV-FS-3 | The available forecasts and replenishment guidance gave me the product, stock and planning information needed for the assigned review. | Open current, unavailable, stale, and demonstration forecast/replenishment scenarios. | Product, stock, and planning context is present with correct freshness/source limitations. |
| INV-FS-4 | I could record the assigned physical inventory count and review its status. | Submit a prepared physical Inventory Count and open its review record. | Entered count and review status are retained; workflow stock effect matches its actual approval policy. |
| INV-FS-5 | I could approve or reject a test stock issue according to the assigned scenario. | Review one pending Stock Issue for approval and another for rejection. | Both decisions persist with correct outcomes and required reasons. |

#### Performance Efficiency

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| INV-PE-1 | Product search and stock records loaded quickly enough for my inventory task. | Time Product search and stock-record viewing. | Search meets proposed search threshold; stock view meets proposed report threshold. |
| INV-PE-2 | Saving the assigned stock receiving or inventory count completed without a disruptive wait. | Time a valid receiving and Inventory Count save. | Both meet proposed save threshold and acknowledge the correct committed records. |
| INV-PE-3 | Forecast results and replenishment recommendations opened quickly enough for my planning review. | Time forecast and replenishment views. | Both meet proposed report threshold with correct Product/context information. |
| INV-PE-4 | Importing the prepared product CSV completed within an acceptable time for the test file size. | Import the prepared CSV while recording bytes, rows, elapsed time, and machine. | Import completes with correct values; timing remains informational until a file-size budget is approved. |
| INV-PE-5 | Filtering receiving history returned the assigned records without a disruptive wait. | Filter receiving history to a known Product and date range; measure elapsed time. | Expected records return within proposed report/filter threshold. |

#### Compatibility

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| INV-CO-1 | The product CSV file prepared for the test imported with the expected product values. | Import a valid CSV with known barcode, price, unit, and planning values. | Imported rows match the file exactly; record rejected/duplicate-row policy separately. |
| INV-CO-2 | The barcode labels generated during my test printed legibly using the available printer. | Preview generated barcode labels and defer physical printer testing. | Labels contain intended codes and readable software layout; printer legibility remains unverified. |
| INV-CO-3 | A completed Cashier sale appeared correctly in the stock records I reviewed. | Complete one Cashier sale and inspect its stock movement as Inventory Manager. | Same sale reference explains exactly the intended stock deduction. |
| INV-CO-4 | A product barcode entered using the connected scanner appeared correctly in the product barcode field. | Enter a barcode into its field using a controlled keyboard simulation. | Field receives the exact code; actual connected-scanner behavior remains unverified. |
| INV-CO-5 | The inventory report exported during my test opened correctly in the store viewer. | Export an Inventory report and open it in the designated viewer. | Exported rows, units, headings, and totals match the on-screen report. |

#### Usability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| INV-US-1 | The Inventory Manager menus made it easy to find Products, Stock Receiving, Inventory Counts and Stock Issue Reviews. | Navigate to Products, Stock Receiving, Inventory Counts, and Stock Issue Reviews. | Each destination is labelled and reachable; respondent ease rating remains uncollected. |
| INV-US-2 | Quantity units, purchase cost and selling price labels were clear enough for me to enter the intended values. | Inspect unit, purchase-cost, and selling-price labels with piece/pack examples. | Entered values have explicit unit/currency context and cannot silently change meaning. |
| INV-US-3 | I could distinguish current stock guidance from forecast information that was unavailable, old or marked as demonstration data. | Compare current stock with unavailable, old, and demonstration forecasts. | Status/source labels distinguish each state and do not present demonstration guidance as current real evidence. |
| INV-US-4 | The receiving history made it easy to find the product and quantity from my test delivery. | Find the prepared delivery in receiving history. | Product, received quantity, date, and identifying record are visible and correct. |
| INV-US-5 | Stock issue details made the reported product, quantity and reason clear before I made a review decision. | Open a pending Stock Issue before deciding. | Product, quantity, reason, and pending status are visible before approval/rejection. |

#### Reliability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| INV-RE-1 | The product and receiving details I saved remained correct when I reopened their records. | Save Product and receiving details, reload, and reopen. | All saved values remain exact and the stock effect is not repeated. |
| INV-RE-2 | The assigned stock issue decision produced the expected stock effect: approval deducted the reported quantity and rejection did not. | Approve a known pending issue and reject another. | Approval deducts its quantity once; rejection deducts nothing. |
| INV-RE-3 | The inventory count I recorded retained the entered count and its review status when I reopened it. | Record an Inventory Count, reload, and reopen its record. | Entered quantity and review status persist unchanged. |
| INV-RE-4 | An invalid receiving entry was rejected without changing the saved stock quantity. | Attempt receiving with invalid quantities/costs and compare stock before/after. | Validation rejects invalid input and neither stock nor receiving history changes. |
| INV-RE-5 | The recorded sale and receiving movements explained the expected change in the product stock balance. | Compare opening stock plus receiving minus sales and approved issues with final stock. | Independent movement ledger reconciles exactly, including any separately documented adjustments. |

#### Security

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| INV-SE-1 | An incorrect password was rejected, and signing out required me to sign in again to reopen protected pages. | Try an incorrect password and access a protected Inventory page after signing out. | Invalid login fails and logged-out access requires sign-in. |
| INV-SE-2 | My Inventory Manager workspace blocked the assigned attempt to manage users or perform cashier checkout. | As Inventory Manager request user-management and checkout endpoints directly. | Server rejects both and users/sales remain unchanged. |
| INV-SE-3 | The system rejected an invalid quantity in the assigned receiving or stock issue test without saving that invalid movement. | Submit zero, negative, nonnumeric, and excessive quantities to receiving/issue boundaries as applicable. | Invalid movements are rejected with no saved movement or stock change. |
| INV-SE-4 | My Inventory Manager workspace blocked the assigned attempt to generate forecasts or retrain models. | As Inventory Manager request forecast generation and model retraining. | Both server endpoints deny the requests and create no jobs. |
| INV-SE-5 | Signing in with my assigned account was required before I could submit a stock receiving entry. | Submit receiving without an authenticated session. | Authentication is required and no receiving or stock change is saved. |

#### Maintainability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| INV-MA-1 | I could update an allowed product planning value without needing a program change. | Edit an allowed Product planning value using the Product screen. | Value is accepted and persists without changing program files. |
| INV-MA-2 | After correcting a product detail through the allowed edit screen, I could repeat the affected inventory task successfully. | Correct a Product detail and repeat its affected inventory task. | Corrected value is used and related inventory records stay consistent. |
| INV-MA-3 | After a reported inventory defect was corrected, the affected task and the related stock record worked as expected when I retested them. | Replay a documented inventory defect on earlier and corrected builds. | Corrected task and related stock record satisfy the original scenario; blocked without defect/build evidence. |
| INV-MA-4 | After I changed a test product planning value, the relevant product record showed the updated value when reopened. | Change a test Product planning value and reopen it after reload/sign-in. | Exact new value persists in the relevant Product record. |
| INV-MA-5 | After a tested system update, I could reopen receiving history and save a new test receiving entry correctly. | Compare existing receiving history before/after an approved update, then receive test stock. | History survives and new receiving saves once correctly; blocked until update is available. |

#### Portability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| INV-PO-1 | I could sign in and reopen the required inventory pages in a second supported browser. | Repeat required Inventory pages in a second approved browser. | Records, controls, and role boundaries behave consistently; versions recorded. |
| INV-PO-2 | I could review saved inventory records on another supported device using the same store data. | Review the same saved inventory records on a second supported device. | Values and permissions agree; actual second-device testing deferred. |
| INV-PO-3 | The inventory pages remained readable and their required controls usable at the supported screen sizes I tested. | Inspect Inventory screens/forms/dialogs at the planned software viewports. | Required controls remain readable/reachable without lost entered values; no hardware claim. |
| INV-PO-4 | I could reopen the same receiving record after signing in on another supported store computer. | Reopen the same receiving record on another approved Store computer. | Identifier and values match; actual second-computer testing deferred. |
| INV-PO-5 | I could complete the assigned product search after resizing the supported browser window. | Resize the browser and complete Product search. | Intended Product remains findable and controls remain usable. |

### Cashier

#### Functional Suitability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| CAS-FS-1 | I could open a shift, find products by scan or manual entry, and complete a sale for the selected items. | Open a Cashier Shift, locate items by simulated barcode and manual lookup, and complete a sale. | Correct items/quantities and completed sale are saved once against the Shift/Register. |
| CAS-FS-2 | The sale total, cash change and receipt matched the expected values for the test transaction. | Sell the fixture items using the prepared cash tender and reopen the receipt. | Total, tender, change, item quantities, and receipt match the independent expected values. |
| CAS-FS-3 | The saved GCash transaction showed the intended cash-in or cash-out type, amount, reference and displayed service fee. | Record separate GCash cash-in and cash-out examples. | Type, principal, reference, displayed fee, and saved state match each assigned example; separate from Product payments. |
| CAS-FS-4 | I could submit a damaged or expired item report for Inventory Manager review. | Report one damaged and one expired Product quantity as Cashier. | Pending Stock Issues are visible to Inventory Manager with correct Product/quantity/reason. |
| CAS-FS-5 | I could record an eligible test refund and reopen its refund receipt. | Refund an eligible completed sale and reopen its refund receipt. | Separate refund and receipt contain correct items, amounts, authorization, and original-sale linkage. |

#### Performance Efficiency

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| CAS-PE-1 | Scanning or searching for an item added the intended product to my cart quickly enough for checkout. | Measure barcode/manual lookup through intended Product appearing in the cart. | Proposed search threshold is met and no unintended Product is added. |
| CAS-PE-2 | Cart totals updated quickly when I changed an allowed item quantity. | Measure quantity change through updated cart totals. | Correct totals appear within proposed search/cart threshold. |
| CAS-PE-3 | Completing payment and opening the receipt took an acceptable amount of time for serving a customer. | Measure payment submit through saved receipt availability. | Proposed save/status threshold is met and the receipt identifies the committed sale. |
| CAS-PE-4 | My transaction history opened quickly enough for me to find the assigned sale. | Measure opening Transaction History and finding the assigned sale. | History meets proposed report threshold and displays the correct sale. |
| CAS-PE-5 | Saving the assigned GCash transaction completed without a disruptive wait. | Measure saving an assigned GCash Service transaction. | Proposed save threshold is met and acknowledgment identifies the correct record. |

#### Compatibility

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| CAS-CO-1 | The connected barcode scanner entered the intended product code into the POS scan field. | Simulate scanner keystrokes into the POS scan field. | Exact code and intended Product are processed; actual scanner testing deferred. |
| CAS-CO-2 | The sale receipt printed legibly on the printer used in the test. | Inspect sale receipt print preview and defer physical printing. | Preview shows complete intended details; printer legibility remains unverified. |
| CAS-CO-3 | Using another store application alongside RetailMind did not interrupt my cashier task. | Use the designated companion application during an active Cashier task. | Cart, session, and task remain intact; record application/browser versions. |
| CAS-CO-4 | The completed sale appeared consistently in the stock record reviewed with the Inventory Manager. | Review a completed Cashier sale together with the Inventory stock record. | Same sale identifier and quantity explain the stock deduction. |
| CAS-CO-5 | The recorded GCash transaction appeared consistently in the GCash record reviewed with the Admin. | Review a saved Cashier GCash record as Admin. | Same reference, principal, fee, type, status, and actor appear consistently. |

#### Usability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| CAS-US-1 | The Cashier menus made it easy to find Point of Sale, GCash, Cashier Shift and Transaction History. | Navigate to POS, GCash, Cashier Shift, and Transaction History. | Each destination is labelled and reachable; respondent ease rating remains uncollected. |
| CAS-US-2 | Payment fields, product quantities and receipt details were clear enough for me to complete checkout. | Inspect checkout fields, units, quantities, and receipt with the prepared transaction. | Controls expose the required payment/unit context and receipt values; respondent clarity remains uncollected. |
| CAS-US-3 | The messages for a missing shift, insufficient stock or invalid payment clearly explained what I needed to correct. | Attempt checkout without Shift, with insufficient stock, and with invalid payment. | Specific actionable validation appears for each and no invalid sale is committed. |
| CAS-US-4 | The stock issue form made the product, quantity and reason fields easy to understand. | Inspect Stock Issue Product, quantity, and reason inputs and their validation. | Required information and errors are explicit; valid submission records the intended values. |
| CAS-US-5 | The shift closing screen made the expected cash, counted cash and difference clear. | Inspect closing Shift screen with known expected and counted cash. | Expected amount, counted amount, and signed difference are visible and arithmetically correct. |

#### Reliability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| CAS-RE-1 | The completed sale remained in transaction history with the correct items and payment details when I reopened it. | Complete a sale, reload/sign in again, and reopen it from history. | Items, quantities, payment, and receipt remain exact without duplicate sale. |
| CAS-RE-2 | Holding and resuming the assigned sale restored the expected cart items and quantities. | Hold a cart with several items/quantities, then resume the same held sale. | Expected items and quantities return without duplicate sale or premature stock deduction. |
| CAS-RE-3 | Closing my shift showed the expected comparison between counted cash and the recorded drawer amount. | Close a Shift using independently calculated drawer and counted cash. | Displayed expected cash and variance match the independent ledger. |
| CAS-RE-4 | Submitting my stock issue created a pending report without immediately deducting the reported quantity. | Submit a Stock Issue as Cashier and compare stock before/after. | Pending report is created with no immediate stock deduction. |
| CAS-RE-5 | The saved test refund appeared as a separate record while the original completed sale remained available. | Save a test refund and reopen the original sale and refund separately. | Both remain available with distinct identifiers and accurate linkage/totals. |

#### Security

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| CAS-SE-1 | An incorrect password was rejected, and signing out required me to sign in again to reopen protected pages. | Try an incorrect password and request a protected Cashier page after sign-out. | Invalid login fails and expired/logged-out session cannot access protected records. |
| CAS-SE-2 | My Cashier workspace blocked the assigned attempt to receive stock, edit product records or manage users. | As Cashier request receiving, Product-edit, and user-management endpoints. | Each server endpoint rejects the request and no records change. |
| CAS-SE-3 | The assigned refund attempt followed the displayed eligibility and authorization requirements before it could be recorded. | Attempt eligible, ineligible, and unauthorized refunds. | Only the authorized eligible scenario commits and required approvals/limits are enforced server-side. |
| CAS-SE-4 | Checkout was blocked when I had no open cashier shift in the assigned test. | Attempt checkout with no open Cashier Shift using UI and direct request. | Both paths block the sale and stock/payment records remain unchanged. |
| CAS-SE-5 | The system rejected the assigned checkout quantity that exceeded the available stock. | Attempt quantity above available stock through UI and direct checkout request. | Checkout rejects it with no negative stock or committed sale/payment. |

#### Maintainability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| CAS-MA-1 | The error message from a failed cashier task gave me enough information to report the problem to the Admin. | Trigger a controlled failed Cashier task and inspect its message. | Task/context and corrective/reporting information are available without exposing secrets; respondent adequacy uncollected. |
| CAS-MA-2 | After a reported checkout defect was corrected, I could repeat the affected sale task successfully. | Replay a documented checkout defect before and after its correction. | Original failing sale task succeeds correctly on corrected build; blocked without defect/build evidence. |
| CAS-MA-3 | After a tested system update, I could still find a previously saved sale and complete a new test sale correctly. | After an approved update reopen a baseline sale and create a new test sale. | Historical sale remains exact and new sale/stock/payment effects are correct; blocked until update exists. |
| CAS-MA-4 | After a reported GCash defect was corrected, I could repeat the affected transaction task successfully. | Replay a documented GCash defect before and after its correction. | Corrected transaction type/amount/fee/reference/state satisfy the original scenario; blocked without evidence. |
| CAS-MA-5 | After a reported receipt defect was corrected, the reprinted test receipt showed the expected sale details. | Replay a documented receipt defect and reprint the test receipt in software. | Corrected preview preserves original expected sale details; physical reprint and missing defect/build remain deferred. |

#### Portability

| Case | Original statement | Procedure | Expected result |
| --- | --- | --- | --- |
| CAS-PO-1 | I could sign in and reopen the required cashier pages in a second supported browser. | Repeat required Cashier pages in a second approved browser. | Same records, role boundaries, and required controls work; versions recorded. |
| CAS-PO-2 | I could review my saved transaction history on another supported device using the same store data. | Review saved Cashier Transaction History on a second supported device. | Same permitted transactions appear; actual second-device testing deferred. |
| CAS-PO-3 | The cashier pages remained readable and their required controls usable at the supported screen sizes I tested. | Inspect Cashier pages, cart, dialogs, and receipt at planned software viewports. | Required controls and content remain readable/reachable; supported-size list needs owner confirmation. |
| CAS-PO-4 | I could reopen the same sale receipt after signing in on another supported store computer. | Sign in on another approved Store computer and reopen the same sale receipt. | Identifier, items, payment, and totals match; actual second-computer testing deferred. |
| CAS-PO-5 | I could complete the assigned product lookup after resizing the supported browser window. | Resize the browser and complete Product lookup. | Intended Product is found and lookup controls remain usable without losing the cart. |

## Existing coverage candidates (not run by this plan)

These scripts are discovery pointers, not evidence that their entire associated cases pass. Inspect their fixtures and database behavior before running; do not execute `npm run test:e2e` or `src/backend/tests/run_all.sh` against the existing Store as a blanket QA command. The documented E2E command writes/removes records in the configured database. The generic Playwright configuration/examples do not constitute full RetailMind browser coverage.

| Area / case families | Existing scripts under `src/backend/tests/` |
| --- | --- |
| ADM staff/settings/Register, SE role limits | `administrator_management_browser_test.js`, `role_capability_policy_contract.php`, `register_administration_contract.php`, `user_lifecycle_contract.php` |
| ADM sales/Gross Profit/report consistency | `administrator_dashboard_financial_test.php`, `gross_profit_reporting_test.php`, `gross_profit_report_browser_test.js`, `sales_refunds_payment_date_contract.php` |
| ADM Fiscal Period and Operational Audit | `fiscal_period_custody_closure_contract.php`, `audit_visibility_integration.php`, `audit_modules_test.php` |
| ADM Database Backup | `database_backup_workflow_integration.php`, `backup_package_browser_test.js`, `backup_workflow_route_contract.php` |
| ADM/INV forecasts and replenishment | `forecast_generation_contract.php`, `demand_forecast_service_test.php`, `demand_forecast_workflow_browser_test.js`, `forecast_decision_browser_test.js` |
| INV Product/CSV/receiving | `product_csv_import_test.php`, `product_create_browser_test.js`, `batch_receiving_mysql_test.php`, `receiving_history_browser_test.js` |
| INV/CAS Counts/Stock Issues | `inventory_count_reconciliation_test.php`, `stock_forms_counts_and_issues_test.php`, `stock_issue_batch_deduction_test.php`, `stock_issue_review_clarity_browser_test.js` |
| CAS checkout/held sales/Shifts/refunds/GCash | `checkout_quote_integration.php`, `checkout_attempt_integration.php`, `held_sale_shift_contract.php`, `cashier_shift_reconciliation_contract.php`, `cash_refund_contract.php`, `gcash_transaction_integration.php` |
| All SE/role sessions | `active_workspace_boundaries_contract.php`, `cashier_workspace_authorization_contract.php`, `session_policy_test.php` |
| All PE/PO and receipt software layout | `page_load_performance_browser_test.js`, `responsive_system_browser_test.js`, `page_responsive_components_browser_test.js`, `sale_receipt_browser_test.js`, `refund_receipt_browser_test.js` |

See [existing role/device acceptance limits](role-device-acceptance.md). Historic results are background, not results for this run. Additional automated coverage should only be considered after the test Store and desired mutation scope are identified; this deliverable adds no test framework or dependencies.
