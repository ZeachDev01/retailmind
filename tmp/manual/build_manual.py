from pathlib import Path
from xml.sax.saxutils import escape
from reportlab.pdfgen import canvas
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Image, PageBreak, KeepTogether, Table, TableStyle
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.utils import ImageReader
from pypdf import PdfReader

ROOT = Path(r'C:/laragon/www/retailmind')
OUT = ROOT / 'output/pdf/RetailMind_User_Manual.pdf'
OUT.parent.mkdir(parents=True, exist_ok=True)
pages = []

def section(title, route, bullets, image=None, caption=None):
    return dict(title=title, route=route, bullets=bullets, image=image, caption=caption)

def page(title, *sections):
    pages.append((title, sections))

page('Getting started',
section('Log in to RetailMind', 'All users', [
'Open the RetailMind address supplied by your Store administrator. Select Log in to RetailMind.',
'Enter your Username or email and Password, then select Log in. Use your assigned account; do not share passwords.',
'If the account has several workspaces, select the workspace for the task. Only assigned workspaces are available.',
'If sign-in fails, check your entry and Caps Lock. Use the available password recovery option or ask the administrator to review your account.']),
section('Use the workspace and navigation', 'All users', [
'Use the left sidebar to open a page. Select a grouped heading, such as Store Administration or Inventory Control, to reveal its pages.',
'On a small screen, open the menu first. Scroll within wide tables to see additional columns.',
'Use the search control to find available pages or products. Search results and actions depend on your current workspace.',
'Open the account menu to change workspace, manage your account, or log out. A person with several roles must switch workspace before performing another role\'s task.']),
section('Manage your account', 'Account menu', [
'Open your account details to check your information. Use Change password to update your password and complete the required fields.',
'For a profile picture, select a still JPG, PNG, or WebP image up to 5 MB. Adjust the square crop, then choose Save picture. Cancel leaves the saved picture unchanged.',
'Use Preferences for the options available to your account. Log out when finished, especially on a shared device.']))

page('Super Administrator',
section('Review the Control Center', 'Super Administrator > Control Center', [
'Open Control Center to review platform attention items and available health information.',
'Open the relevant attention item or Platform Administration page to investigate. Read the current status before taking action.',
'Use this workspace for security, recovery, and technical governance. Routine checkout and stock work require the appropriate operational workspace.']),
section('Manage Users and Access', 'Super Administrator > Platform Administration > Users & Access', [
'Search the account list and open the person\'s record. Confirm the person and existing access before changing it.',
'When creating or updating an account, complete the required identity fields and assign only the workspaces the person needs.',
'Save the change and review the resulting access. Disable an account when access must stop; historical actions remain attributed to that person.',
'Respect messages that prevent removal of required administrator access. Do not reuse another person\'s account to preserve access.']),
section('Review security evidence', 'Super Administrator > Platform Administration', [
'Open Protected Audit Records to inspect recorded security and operational actions. Filter the records and open the relevant details.',
'Protected Audit Records are retained evidence; the application does not provide individual editing or deletion.',
'Use Recovery Account for the separate recovery access controls. Keep recovery credentials private and outside ordinary staff use.']))

page('Platform health and forecasting governance',
section('Check System Health', 'Super Administrator > Platform Administration > System Health', [
'Review health indicators and the attention items available on the page. Open the relevant detail to identify the affected service or requirement.',
'When a failure needs technical investigation, use the application log review available through the technical workspace. Review the error before acknowledging it.',
'For pending database updates, arrange a backup and a quiet Store period before using the technical update workflow.',
'Acknowledge an issue only after reviewing it. Acknowledgment records a review; it does not itself repair the cause.']),
section('Manage ML Operation', 'Super Administrator > Platform Administration > ML Operation', [
'Check the candidate model, training status, recent runs, and current forecast parameters.',
'Start training only when the page permits it and the intended data and environment are ready. Wait for the run result before trying another run.',
'Review candidate quality and the approval controls before enabling a candidate for its intended use.',
'Save parameter changes deliberately. Store staff should continue normal stock checks when forecasting is unavailable.']),
section('Review Forecast Model Governance', 'Super Administrator > Platform Administration > Forecast Model Governance', [
'Inspect model evaluation, readiness, and governance information before approving model use.',
'Separate a successful training run from an approved model and from usable forecast outputs. Review the displayed state for each.',
'Forecasts support human decisions. They do not place orders, guarantee sales, or replace physical stock checks.']))

page('Create and keep a backup',
section('Create an encrypted Store backup', 'Administrator > Store Administration > Database Backup', [
'Enter a new backup passphrase of at least 16 characters. Keep a recovery copy separately from the server and the backup file.',
'Select Create backup and wait for the result. Store saving may pause briefly while the snapshot is captured.',
'Download the completed encrypted package and confirm that the file is saved on your device. A Created history entry alone does not confirm a download.',
'Keep the package in secure storage outside the Store server. Losing every copy of its passphrase makes that package unusable. Resetting a login password cannot unlock it.'],
'output/playwright/backup-clarity/overview-1440.png', 'Database Backup with passphrase entry and backup history'),
section('Review backup history', 'Administrator or Super Administrator', [
'Check the file, snapshot time, and outcome in backup history. Retain the package and its matching passphrase together in your recovery records, stored separately for security.',
'The Super Administrator can also create an encrypted backup through Platform Administration > Backup & Restore.',
'If backup storage is unavailable or creation fails, ask the Super Administrator to resolve the issue. Do not assume that an unsuccessful attempt protects the Store.']))

page('Restore and recover Store data',
section('Validate the incoming backup', 'Super Administrator > Platform Administration > Backup & Restore', [
'Restore replaces Store records with a snapshot. Sales and changes made after that snapshot disappear from the active database. Schedule recovery with the Store owner.',
'Stop source sales and background workers before replacement. Keep only one active Store installation during a migration.',
'Select the encrypted .rmpkg package. Enter the requested destination account password and the package\'s original passphrase, then select Validate package.',
'Review the snapshot details and identity conflicts. The Super Administrator must approve Identity Reconciliation for the exact package before an ordinary restore proceeds.',
'Resolve conflicts explicitly. Do not assume matching account numbers mean the same person. Destination privileged access and security evidence must remain protected.']),
section('Restore the approved package', 'Administrator Database Backup or Super Administrator Backup & Restore', [
'Open Restore & recovery or the restore section. Select the approved encrypted package and enter your destination login password, the original package passphrase, and a new destination safety passphrase.',
'Keep the safety passphrase outside both servers. Read and select the replacement confirmation, then choose Restore and verify.',
'The system verifies a destination safety copy before replacement. Keep Store operations paused until the recovery checks and operator review finish.',
'Use the Database Recovery Console to inspect results and complete the verification checklist. Confirm records, stock, receipts, uploaded files, account access, and the intended active installation.',
'An authorized Administrator or destination Super Administrator must complete the required authentication and reopening confirmation. If recovery fails, leave the Store paused and use the console\'s supported recovery path.']),
section('Handle a failed recovery', 'Database Recovery Console', [
'Preserve the error message, package, and passphrases for the authorized technical administrator.',
'Use the verified safety copy or approved incoming package as directed by the console. Do not bypass the pause or resume transactions against a partially restored database.',
'Forecast models require destination readiness checks after migration. Resume forecasting only when the destination reports that it is ready.']))

page('Administrator Store management',
section('Review Store Overview', 'Administrator > Store Overview', [
'Review Store performance, sales information, and operational attention items. Use the displayed period when comparing totals.',
'Open an attention item to review the underlying record. Follow up on shifts, stock issues, and other exceptions with the responsible staff.',
'Use Store Reports for a detailed report. Gross profit uses net sales and the cost of sold units; it is not the same as profit after rent, wages, and other expenses.']),
section('Manage Store Staff', 'Administrator > Store Administration > Store Staff', [
'Search for the staff member before creating another account. Review the person\'s status and assigned operational workspaces.',
'Complete the required fields when adding or editing staff. Grant Cashier or Inventory Manager access only when appropriate.',
'Save and confirm the resulting record. Disable staff access when necessary while retaining historical attribution.',
'Refer privileged platform access changes to the Super Administrator.']),
section('Manage Registers', 'Administrator > Store Administration > Registers', [
'Enter a Register name, choose the receipt paper width, and select Create Register.',
'Use Rename or Save width to update the corresponding setting. Match the paper width to the receipt printer used at that Register.',
'Enable or disable a Register as needed. Review its current usage and any blocking message before changing availability.',
'A Cashier must select an available Register when opening a shift. Investigate a locked or occupied Register instead of opening a second conflicting shift.']))

page('Store calendar and operating settings',
section('Record the Store Calendar', 'Administrator > Store Administration > Store Calendar', [
'Use Previous, Today, or Next to locate the date. Select Record date status and complete the date and status fields.',
'Record the appropriate Open, Closed, Holiday, or Exceptional closure status and the requested details, then save.',
'Review the calendar entry. Not recorded means no status has been entered; do not interpret it as a confirmed closure.'],
'.scratch/calendar-light-1440.png', 'Store Calendar with daily operating statuses'),
section('Update Store Settings', 'Administrator > Store Administration > Store Settings', [
'Review the attention thresholds and other settings shown for the Store. Enter the required values within the displayed limits.',
'Select Save settings and confirm success. Change thresholds deliberately because they affect which operational exceptions need attention.']),
section('Manage Fiscal Periods', 'Administrator > Store Administration > Fiscal Periods', [
'Enter a period name, start date, and end date, then select Create period.',
'Review the period and unresolved operational responsibilities before selecting Close period or Lock period.',
'Treat closure and locking as controlled accounting actions. Resolve blocking shifts, custody issues, or other messages before continuing.']))

page('Administrator oversight and reporting',
section('Review Cashier Shifts and GCash', 'Administrator > Store Reports > Cashier Shifts or GCash Overview', [
'Select the relevant date or staff record and inspect opening cash, sales activity, movements, closing counts, and differences.',
'Review large differences and the staff explanation. Complete any available review only after checking the underlying evidence.',
'Use GCash Overview to monitor service cash, wallet responsibility, and unresolved exchanges. Keep GCash service balances distinct from POS drawer balances.',
'Use the supported exceptional shift workflow when a Cashier is absent. Record the required reason and treatment of unpaid held sales.']),
section('Manage Promotions', 'Administrator > Promotions', [
'Enter the promotion name, discount type, value, scope, minimum quantity, and start and end times.',
'Choose the relevant product or category when the selected scope requires it. Review the price effect and dates, then select Create promotion.',
'Use Enable or Disable to control an existing promotion. Verify the resulting checkout price before applying a promotion to a customer sale.']),
section('Review stock and operational records', 'Administrator > Stock Issue Oversight and Store Reports', [
'Use Stock Issue Oversight to inspect pending issues and their review state. Inventory execution belongs to the Inventory Manager workspace.',
'Open Receiving History for recorded bought stock, quantities, cost information, and recording staff.',
'Open Generate Report, choose a report and its available filters, and review the results. Use Export CSV or Print / Save as PDF when offered.',
'Use Store Administration > Operational Audit to trace recorded actions. Filter by the relevant period and inspect the associated details.']))

page('Inventory Manager stock and products',
section('Review Stock Overview', 'Inventory Manager > Stock Overview', [
'Check available stock and the low-stock, out-of-stock, or expiry information presented on the page.',
'Find the product and review its stock context before deciding whether to receive stock, count it, or investigate an issue.',
'Select Review recommendations when planning replenishment. A recommendation is guidance; opening it does not change inventory.']),
section('Add or edit a Product', 'Inventory Manager > Products', [
'Search the catalog first to avoid creating a duplicate. Use filters for category, status, price, or stock where available.',
'Open the add menu and choose Add Product. Complete the required product identity, category, unit, pricing, barcode, and stock or planning fields shown in the guided entry.',
'If the product uses packs or cases, verify its saved pack size and the appropriate unit and pack barcodes. Enter quantities in the requested form.',
'Review the details and save. Confirm the product in the catalog. For an existing product, open its details, edit the permitted fields, and save.',
'Use Stock Receiving for bought goods and Inventory Counts for count discrepancies. Avoid creating another product merely to correct an existing stock balance.']),
section('Use barcodes and recover an unfinished entry', 'Inventory Manager > Products and inventory forms', [
'Scan into the intended product or barcode field. If a code is unknown or ambiguous, select the correct product manually and check its saved barcode.',
'Use the available barcode label print action after confirming the product and packaging information.',
'Where encrypted draft recovery is available, wait for the Unsaved values kept status. Choose Resume to restore the entry or Discard to remove it.',
'Resuming a draft does not save stock. Recovery is limited to the same account, browser, and device; clearing browser data or losing the device can remove the draft.']))

page('Replenishment and Demand Forecast',
section('Review Replenishment Recommendations', 'Inventory Manager > Replenishment Recommendations', [
'Search or filter the products, then compare On hand, Stock-rule suggestion, and Expected sales when available.',
'Use stock-rule guidance even when a forecast is unavailable. Unavailable demand is unknown demand, not a forecast of zero.',
'Check the forecast period and readiness information. Decide what the owner should buy; the owner purchases goods outside RetailMind.',
'Select Record bought stock after the goods arrive. Recommendations do not create purchase orders or change stock.'],
'.scratch/replenishment-comparison/desktop.png', 'Replenishment table comparing stock rules and expected sales'),
section('Review forecasts and readiness', 'Inventory Manager > Reports', [
'Open Demand Forecast and select the available product, date, or period filters. Read the forecast together with its readiness and governance labels.',
'Use Data Readiness and Forecast Exceptions to inspect missing history, unsuitable data, or other conditions affecting use.',
'Open Insights & Risk for the available inventory risk information. Combine the displayed guidance with physical stock knowledge.',
'Refer model or service failures to the Super Administrator. Receiving refreshes guidance from saved stock and existing forecasts; it does not train a model or generate a new forecast.']))

page('Record bought stock',
section('Complete Stock Receiving', 'Inventory Manager > Stock Receiving', [
'Follow Select products, Enter quantities, and Review. Search by product name or SKU, or scan the unit or case barcode.',
'Add each active product once. A scan selects the product and quantity mode; enter the actual bought quantity yourself.',
'Enter base units or packs/cases using the saved pack size. For example, 8 units plus 2 cases of 24 equals 56 base units.',
'Enter batch and expiry details when applicable. Identify damaged bought units so they are excluded from usable stock.',
'Shopping store, receipt reference, and purchase cost are optional. Enter purchase cost per base unit, not the total case cost. A blank cost preserves the product\'s existing cost and leaves the receipt cost unknown.',
'Review every line and save once. Only the final save changes stock. Read the summary and use Review updated recommendations to inspect the new guidance.']),
section('Check Receiving History and correct a failed entry', 'Inventory Manager > Receiving History', [
'Find the receiving record and review products, accepted quantities, batch details, optional costs, recording staff, and time.',
'If a receiving save fails, correct the indicated entry and retry the retained draft. A failed save must not be treated as received stock.',
'If the response is lost, check the saved history or the application\'s recovery status before starting another entry. Confirm whether the first operation completed.',
'For an error in a committed record, follow the appropriate stock correction process with the responsible staff. Do not record the same bought stock again to fix a display or network problem.']),
section('Keep receiving and stock corrections separate', 'Inventory Manager', [
'Receiving records newly bought goods. Inventory Counts reconcile physical stock already held by the Store.',
'Cashier Stock Issues report a problem for review. Supplier Pullouts record eligible goods removed through that process.',
'Use the workflow that matches the physical event so stock movements and accountability remain understandable.']))

page('Inventory Control',
section('Record and review Inventory Counts', 'Inventory Manager > Inventory Control > Inventory Counts', [
'Select or scan the product and compare its system quantity with a physical count.',
'For batch-tracked products, count each batch. Use Add found batch when goods exist outside the listed batches and enter the applicable details.',
'Enter the Counted quantity or the Sealed Packs and Loose Pieces quantities, then select the count reason.',
'Select Record Count and inspect the recent count record. For a pending count, review the details before selecting Approve or Reject.',
'Confirm the resulting status and stock balance. Recording a pending count is different from approving its stock correction.']),
section('Review Stock Issues', 'Inventory Manager > Inventory Control > Stock Issue Reviews', [
'Open the pending issue and review the product, quantity, reason, reporting Cashier, and any existing decision.',
'Inspect the actual goods or stock evidence. Record the required notes and choose the appropriate available decision.',
'Verify the saved status and stock effect. Use the supported correction process when an earlier report or decision is wrong.',
'Do not report damaged customer returns as new Stock Issues after recording their condition in a refund.']),
section('Record Supplier Pullouts', 'Inventory Manager > Inventory Control > Supplier Pullouts', [
'Choose the Product and Batch, then enter the base-unit quantity or the shown pack and loose-piece quantities.',
'Choose the reason and enter optional supplier information or notes. Check the available batch balance before submitting.',
'Record the pullout only for the actual removal. Review the history to confirm the product, batch, quantity, and staff attribution.',
'Use the appropriate receiving workflow when replacement goods arrive; do not count a promised replacement as available stock.']))

page('Cashier Point of Sale',
section('Open a Cashier Shift', 'Cashier > Cashier Shift', [
'Select an available Register. Count the actual starting cash and enter it in Starting cash.',
'Select Open shift and confirm that the shift is open. A locked Register or missing shift can prevent checkout.',
'Use your own account and assigned Register. Ask the Administrator to resolve an unavailable or locked Register.']),
section('Complete a sale', 'Cashier > Point of Sale', [
'Scan a product barcode or search and select the product. Check the product name, selling form, unit or pack size, quantity, and available stock.',
'Adjust quantities or remove unwanted lines. Review discounts and the total before taking payment.',
'Select the Payment method. For cash, enter the amount received and check the change. For an external payment, complete and verify it separately and enter its reference.',
'Choose Checkout, review the confirmation, and complete the sale. Use the server-confirmed total if price, promotion, or stock has changed.',
'Wait for the completed Sale Receipt. Print when needed. A cart or held sale is unpaid and must not be treated as a completed transaction.',
'If completion is uncertain after a connection failure, use checkout recovery or check history before collecting payment or submitting again.']),
section('Hold or resume an unpaid sale', 'Cashier > Point of Sale', [
'Select Hold sale when a customer is not ready to finish. Use the held-sale controls to select and resume the correct cart.',
'Review current products, quantities, prices, and stock after resuming, then complete checkout when the customer is ready.',
'Discard an abandoned held sale using the required reason. Complete or discard all unresolved held sales before closing your shift.',
'Checkout requires a connection. Cached stock and prices can be out of date; an offline catalog is not confirmation that a sale was saved.']))

page('Cashier GCash service',
section('Open GCash and record an exchange', 'Cashier > GCash', [
'Select Open GCash when available. Enter Counted service cash and the Checked GCash app balance, then confirm.',
'Choose Cash-In or Cash-Out, enter the principal amount, and choose Fee payment where required. Review the calculated fee, cash exchange, and wallet effect.',
'Carry out the real transfer in the authorized GCash account and the physical cash exchange. RetailMind records the event; it does not send the GCash transfer.',
'Enter the correct reference. If using Take photo or Choose photo, check every suggested digit against the actual GCash transaction.',
'Confirm the wallet transfer and physical cash only after both occurred, then use Complete Cash-In or Complete Cash-Out. If the outcome is uncertain, use Hold for checking and record the facts.']),
section('Review expected balances', 'Cashier > Dashboard Overview > GCash tab', [
'Compare expected service cash and wallet balances with the actual cash and authorized GCash app. Open Balance details when needed.',
'Open Needs checking or View history to inspect an unresolved transaction. A customer\'s screenshot alone is not proof of payment.'],
'output/playwright/cashier-dashboard-typeset/gcash-1440.png', 'GCash dashboard showing expected balances and an exchange needing review'))

page('GCash review and custody',
section('Resolve a pending or held exchange', 'Cashier > GCash > Transaction history', [
'Open the relevant transaction and compare its reference, type, amount, fee, and current state with the real GCash records and physical cash.',
'For the pending-record review path, enter a review note and verify only after checking the authorized account. Reject an incorrect record with a reason.',
'For a held exchange, use Resolve held exchange and choose the action that matches what happened. Enter the required reference, explanation, and evidence.',
'When money was returned or recovered, enter the actual returned or recovered cash and wallet amounts. Obtain the requested Administrator authorization.',
'Refresh history after an uncertain response before trying again. Do not reuse a reference for a different transaction.']),
section('Move funds or hand over GCash', 'Cashier > GCash', [
'Use Move funds for an actual service-fund movement. Select the movement type, fund, direction, and amount; enter the reason and evidence reference.',
'Provide Administrator authorization when requested. Confirm that funds were physically received or handed over before recording the movement.',
'Use Hand over to identify the incoming staff member. Count the service cash, check the wallet balance, and record the required notes before confirming.',
'Keep service-fund movements separate from POS sales and ordinary drawer movements.']),
section('Reconcile and close GCash', 'Cashier > GCash > Reconcile & Close', [
'Review unresolved exchanges and the expected balances. Count service cash and check the actual wallet balance in the GCash app.',
'Enter the counted amounts, unresolved carryover count when requested, and closing notes. Review differences before Confirm & Close.',
'Use Review difference when the workflow requires investigation. Provide findings and authorization rather than changing a count to match the expected balance.',
'Record an approved correction with its actual cash or wallet change and explanation. Keep the transaction and supporting evidence traceable.']))

page('Cashier refunds and correction reviews',
section('Record a refund', 'Cashier > Cash Refunds', [
'Enter the Receipt number and select Load sale. For another Cashier\'s sale under the exceptional workflow, obtain the required Administrator approval.',
'Choose the returning quantity for each line and its actual condition. Only Restockable goods return to available stock; packs must meet the displayed sealed-pack requirements.',
'Select the reason and add the required note. Follow the original payment method; complete and verify external refunds separately when prompted.',
'Review the final amounts and any required authorization, then select Record refund. Print or preview the Refund Receipt after success.',
'An exchange requires a separate paid sale. Keep the original sale and refund as recorded evidence.'],
'output/playwright/refund-history/populated-light-1440.png', 'Refund lookup and the saved refund history'),
section('Complete an approved Refund Correction Review', 'Administrator, Cashier, or Inventory Manager > Refund Correction Reviews', [
'The Administrator identifies the Refund ID, approves the exact cash or stock change, records the explanation, and assigns the responsible Cashier or Inventory Manager.',
'Assigned staff open Your pending corrections, check the approved details, and record the actual authorized cash or stock action with the required note.',
'Review completion in the history. A correction adds its own record; it does not rewrite the original sale or refund.']))

page('Cashier shift closing and history',
section('Record drawer cash and close the shift', 'Cashier > Cashier Shift', [
'For an actual cash addition or removal, choose the Cash movement, amount, and reason. Explain Other and obtain Administrator authorization when requested.',
'Before closing, complete or discard all unpaid held sales. Finish the required GCash custody or reconciliation steps separately.',
'Count physical cash by entering the number of each bill and coin in the drawer counter. Review the count, expected cash, and difference.',
'Enter closing notes or a reason when required. A large difference may need later Administrator review; enter the true count rather than adjusting it to the expected value.',
'Select Close shift and confirm. Check the saved shift status before logging out.']),
section('Review your sales activity', 'Cashier > Dashboard Overview and Transaction History', [
'Use Dashboard Overview to review today\'s displayed sales, transaction count, stock alerts, and recent transactions.',
'Open Transaction History to locate the relevant saved activity. Use View receipt or the corresponding receipt link to inspect a completed sale.',
'Read each total with its label and period. Gross sales, refunds, drawer movements, and GCash service funds represent different measures.'],
'output/playwright/cashier-dashboard-typeset/cashier-populated-1440.png', 'Cashier dashboard with sample sales figures and a stock alert'))

page('Cashier Stock Issue reports',
section('Report damaged missing or expired stock', 'Cashier > Report Stock Issue', [
'Open your Cashier Shift first. Scan the product barcode or use Search products, then verify the selected product.',
'Choose the Issue type, Stock form, and Quantity affected. Distinguish Loose Pieces from Sealed Packs using the displayed pack size.',
'Enter an Explanation that identifies what happened and the goods affected. Check the details, then select Submit Report.',
'Confirm the report in My Report History. The Inventory Manager reviews the issue; submitting a report is not the same as approving a stock adjustment.',
'For a customer return already recorded through a refund, use the refund condition and correction process rather than creating a duplicate Stock Issue.']),
section('Correct or follow up on your report', 'Cashier > Report Stock Issue > My Report History', [
'Filter by Pending, Returned, Approved, Rejected, or Cancelled to find the record. Read the Reviewer note when one is present.',
'For a pending report, use its editable fields and Save Changes to correct the issue, or Cancel Report when the report should not proceed.',
'For a returned report, correct the requested details and choose Resubmit for Review.',
'Check the resulting status and contact the Inventory Manager if the decision or physical stock still needs investigation. Do not submit the same issue again merely because it is awaiting review.']),
section('Before leaving the Cashier workspace', 'Cashier', [
'Check for uncertain checkout results, unfinished held sales, and unresolved GCash exchanges.',
'Complete the required handover and closing counts, and verify the saved shift status.',
'Preserve receipt references for any issue handed to the Administrator or Inventory Manager, then log out of shared devices.']))

page('Receipts notifications and help',
section('Find and print a receipt', 'Receipt History in your workspace', [
'Use the available search and filters to locate the correct record. Access depends on your workspace and permissions.',
'Open the receipt and confirm the number, date, items, payment details, and amount. Use the appropriate Sale Receipt, Refund Receipt, or GCash receipt.',
'Select the receipt\'s print action and choose the correct printer and paper settings. Confirm that a reprint is for an existing transaction.',
'If a printer fails, keep the saved transaction and print again from its receipt. Do not repeat checkout to obtain another printout.']),
section('Review Notifications and generate a report', 'Notifications and Reports', [
'Open Notifications, review the message, and follow its link to the underlying record. A notification is a prompt to inspect the record, not proof that a problem was fixed.',
'Use the read or review controls available to your account after checking the item. Escalate unresolved stock, cash, or platform issues to the responsible role.',
'For a report, open Generate Report, choose the report and available date or product filters, then review the displayed period and results.',
'Use Export CSV or Print / Save as PDF when offered. Keep downloaded operational records in authorized storage.']),
section('Respond to common problems', 'All users', [
'Missing page or disabled action: check the active workspace, account permission, shift status, and Register state. Ask the responsible administrator if access is still blocked.',
'Unknown barcode or insufficient stock: verify the product, selling form, code, and physical quantity. Ask the Inventory Manager to correct the underlying record.',
'Connection lost or save result unknown: preserve the current entry and use recovery or history to establish whether it saved before starting another transaction.',
'Store paused for backup or recovery: follow the status message. Resume work only when the system permits it.',
'Forecast unavailable: use stock-rule guidance and physical stock checks. Report the readiness or service issue to the appropriate manager.',
'For unresolved errors, report the page, action, time, and exact message to the responsible administrator. Keep passwords and backup passphrases out of screenshots and support messages.']))

styles = {
 'body': ParagraphStyle('body', fontName='Times-Roman', fontSize=11, leading=14, spaceAfter=5),
 'bullet': ParagraphStyle('bullet', fontName='Times-Roman', fontSize=11, leading=14, leftIndent=15, firstLineIndent=-10, spaceAfter=5),
 'title': ParagraphStyle('title', fontName='Times-Bold', fontSize=17, leading=21, spaceAfter=13),
 'h': ParagraphStyle('h', fontName='Times-Bold', fontSize=13, leading=16, spaceBefore=8, spaceAfter=6, keepWithNext=True),
 'route': ParagraphStyle('route', fontName='Times-Italic', fontSize=10, leading=12, spaceAfter=6, keepWithNext=True),
 'caption': ParagraphStyle('caption', fontName='Times-Italic', fontSize=9.5, leading=12, alignment=TA_CENTER, spaceBefore=5, spaceAfter=10),
 'cover': ParagraphStyle('cover', fontName='Times-Bold', fontSize=26, leading=33, alignment=TA_CENTER, spaceAfter=18),
 'center': ParagraphStyle('center', fontName='Times-Roman', fontSize=13, leading=19, alignment=TA_CENTER, spaceAfter=11),
}

def para(text, style='body'):
    return Paragraph(escape(text), styles[style])

story = [Spacer(1,170), para('RETAILMIND','cover'), para('USER MANUAL','cover'), Spacer(1,10),
         para('Shalom Store','center'), para('Inventory Sales and Demand Forecasting','center'),
         Spacer(1,80),para('Version 1.0','center'),para('31 October 2026','center'), PageBreak()]
story += [para('About this manual','title'),
          para('Use this manual to carry out daily Store tasks in RetailMind. It covers sign-in, platform governance, Store administration, stock management, checkout, GCash services, refunds, and recovery.'),
          para('Select the workspace assigned to your task. Cashiers handle checkout and shifts; Inventory Managers handle stock; Administrators oversee Store operations; the Super Administrator governs platform security and technical recovery.'),
          para('Screenshots show sample records. Follow the live page labels and validation messages when figures, available actions, or screen details differ.'),
          para('Contents','h')]
toc = Table([[para(title),str(n)] for n,(title,_) in enumerate(pages,3)],colWidths=[440,28])
toc.setStyle(TableStyle([('FONTNAME',(1,0),(1,-1),'Times-Roman'),('FONTSIZE',(1,0),(1,-1),11),('ALIGN',(1,0),(1,-1),'RIGHT'),('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),0),('RIGHTPADDING',(0,0),(-1,-1),0),('TOPPADDING',(0,0),(-1,-1),0),('BOTTOMPADDING',(0,0),(-1,-1),2)]))
story.append(toc)
story.append(PageBreak())

for idx,(title,sections) in enumerate(pages):
    story.append(para(title,'title'))
    for s in sections:
        story += [para(s['title'],'h'), para(s['route'],'route')]
        for b in s['bullets']:
            story.append(para('•  '+b,'bullet'))
        if s['image']:
            path = ROOT/s['image']
            iw,ih = ImageReader(str(path)).getSize()
            width = 468
            height = width*ih/iw
            if height>205:
                width*=205/height
                height=205
            story.append(KeepTogether([Spacer(1,7),Image(str(path),width=width,height=height),para(s['caption'],'caption')]))
    if idx < len(pages)-1:
        story.append(PageBreak())

def decorate(c, doc):
    c.setTitle('RetailMind User Manual')
    c.setAuthor('RetailMind')
    c.setSubject('User instructions for Shalom Store workspaces')
    c.setFont('Times-Roman',11)
    c.drawRightString(540,756,str(doc.page))

doc=SimpleDocTemplate(str(OUT),pagesize=(612,792),leftMargin=72,rightMargin=72,topMargin=59,bottomMargin=45)
doc.build(story,onFirstPage=decorate,onLaterPages=decorate)
reader=PdfReader(str(OUT))
assert len(reader.pages)==len(pages)+2,(len(reader.pages),len(pages)+2)
for n,(title,_) in enumerate(pages,2):
    assert title in reader.pages[n].extract_text(), (n+1,title)
assert all(len(p.extract_text())>30 for p in reader.pages)
print(f'Created {OUT} with {len(reader.pages)} pages')
