# RetailMind: Store PC functionality checklist

Kini ang manual nga checklist sa dili pa gamiton ang RetailMind sa tinuod nga operasyon. Sundon ang pagkasunod: setup → accounts ug Store configuration → inventory → cashier → Administrator review → recovery → devices ug network → final handover.

**Status: wala pa ni gi-execute sa deployment PC.** Ang matag checkbox kay pending; dili kini certification nga pasado na ang system. Ipares kini sa [installation guide](../guides/WINDOWS_LOCALHOST_INSTALL.md) ug [deployment checklist](../DEPLOYMENT_CHECKLIST.md).

## 1. Test record ug paggamit

| Impormasyon | Isulat dinhi |
| --- | --- |
| Store / address | |
| Tester / tag-iya nga mo-approve | |
| Petsa ug oras (`Asia/Manila`) | |
| Store PC / Windows version | |
| Git commit / release nga gi-install | |
| Browser ug version sa PC | |
| Phone / tablet / browser version | |
| Scanner / printer / paper width | |
| Localhost URL | |
| LAN IP ug URL sa Store PC | |
| Tunnel provider / public URL | |
| Backup destination / kinsa nagkupot sa Recovery Key | Ayaw isulat ang key o password dinhi. |

Sa matag row: buhata ang test, ikumpara ang resulta, dayon tseki lamang kon pasado. Kon fail o blocked, isulat ang ID sa failure log sa ubos. Kon daghang scenario ang naa sa usa ka row, tanan kinahanglan pasado. `N/A` kinahanglan adunay rason ug pagsugot sa tag-iya; dili kini `PASS`.

**Test copy una.** Ang sale, refund, receiving, GCash record, fiscal closure, reset ug restore makausab sa data. Buhata ang destructive ug historical-data tests sa bulag nga test installation/database nga walay koneksiyon sa live Store data, backup folder, email recipients o tinuod nga wallet. Ayaw paghimo og tinuod nga GCash transfer para lang sulayan ang record forms. Ayaw ibutang ang secrets sa screenshots.

Sa actual Store PC, gamita ang test installation una; human niini, iandam ang limpyo o giuyonan nga migrated Store data, usba ngadto sa live configuration, ug buhata ang final checks sa seksiyon 16. Dili gamiton ang reset o pag-import sa `schema.sql` isip shortcut sa paglimpyo sa operating Store.

### Mga account nga andamon

| Account | Gamit |
| --- | --- |
| Super Administrator | Lokal nga platform setup, recovery ug technical forecasting |
| Administrator | Ordinaryong account sa tag-iya; Store operations ug staff management |
| Inventory Manager A ug B | Inventory work ug pagsusi nga pribado ang drafts |
| Cashier A ug B | Checkout, shift ownership, handover ug history isolation |
| Multi-role test account | Inventory Manager + Cashier; usbon ang active Workspace |

Ayaw ipaambit ang usa ka login sa tanan. Ang tagsa-tagsa ka account importante aron masubay kinsay naghimo sa transaction.

### Gamay nga test data

Andama sa Inventory Manager ang mosunod; gamita ang lahi nga barcode ug klarong `TEST` nga ngalan.

| Product / data | Tumong |
| --- | --- |
| `TEST-A`, selling price PHP 25, cost PHP 15, receive 100 pieces, valid expiry kon required | Simple nga stock ug money calculation |
| `TEST-B`, selling price PHP 40, cost PHP 20, receive 50 pieces | Daghang item sa cart |
| Product nga walay stock; low-stock product; near-expiry ug expired batch | Validation ug alerts |
| Product nga adunay duha ka batch ug lain-laing expiry | Batch selection ug saleable stock |
| Sales Pack product: 1 sealed pack = 12 pieces; pack barcode ug piece barcode lahi | Pack receiving, opening, selling ug counts |
| Prepared oil pouch products: 250 mL / PHP 20 ug 500 mL / PHP 40 | Sakto nga pouch size, price ug stock unit |
| Valid ug invalid product CSV; valid historical-sales file alang sa test copy | Imports ug rejected input |
| Usa ka active promotion ug usa ka expired/inactive promotion | Discount eligibility |

Para sa simple money test, walay promotion o laing adjustment: `TEST-A × 2 = PHP 50`; cash received `PHP 100`; change `PHP 50`; stock `100 → 98`. Kon ang configured pricing adunay dugang nga charge, dokumentohi una ang expected calculation ug gamita kini sa comparison. Ayaw gamita ang stock baseline nga nausab na sa laing test.

## 2. Installation ug local services

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | SET-01 | Sundon ang installation guide; susihon PHP, extensions ug dependencies. | Walay missing requirement o startup error. |
| [ ] | SET-02 | Susihon ang database ug migrations sa target installation. | Sakto nga database; walay pending migration; walay live database nga na-overwrite. |
| [ ] | SET-03 | Ablihan ang recorded localhost URL. | Login ug assets mo-load; walay PHP error o broken link. |
| [ ] | SET-04 | Susihon petsa, oras ug timezone sa PC ug app. | Sakto sa `Asia/Manila`; pareho ang transaction ug report dates. |
| [ ] | SET-05 | I-save ang valid product photo ug paghimo og test backup. | Writable ang required storage; walay permission error. |
| [ ] | SET-06 | Susihon private backup destination ug disk space. | Backup gawas sa web root; igo ang space ug ma-access sa scheduled-task account. |
| [ ] | SET-07 | Reboot sa PC ug ablihan pag-usab ang app. | Apache ug database mobalik; app magamit pinaagi sa gitakdang startup arrangement. |
| [ ] | SET-08 | Susihon installer nga scheduled tasks ug ilang manual run/log. | ML Scheduler, Daily Notifications ug Weekly Backup adunay klarong outcome; walay tago nga failure. |

## 3. Shared features: balikon sa matag role

Gamita ang bulag nga browser profile/session sa matag account. Irekord ang role sa ID, pananglitan `AUTH-01/cashier`.

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | AUTH-01 | Login sa matag usa sa upat ka role. | Sakto nga landing page, ngalan ug active Workspace. |
| [ ] | AUTH-02 | Sayop nga password, blank fields ug invalid login. | Klarong error; walay unauthorized access o technical secrets nga makita. |
| [ ] | AUTH-03 | First login sa bag-ong account; mandatory password change. | Dili makalaktaw; bag-ong password molihok human logout; daang password dili na. |
| [ ] | AUTH-04 | Voluntary password change; sayop nga current password ug weak/mismatched password. | Invalid input dili ma-save; valid change molihok. |
| [ ] | AUTH-05 | Profile, picture upload/crop ug invalid file. | Authorized fields ma-save; invalid file dili dawaton; sakto gihapon human reload. |
| [ ] | AUTH-06 | Logout, browser Back ug direct protected-page URL. | Dili magamit ang protected data/actions nga walay valid session. |
| [ ] | AUTH-07 | Pasagdan ang session hangtod sa configured inactivity timeout. | Warning/expiry sumala sa policy; protected action mangayo pag-usab og login. |
| [ ] | AUTH-08 | Multi-role account: balhin Inventory Manager ↔ Cashier. | Ang active Workspace ra ang gamiton; dili magkombinar ang permissions. |
| [ ] | AUTH-09 | Notifications: open, filter, mark read ug follow link. | Sakto nga recipient ug destination; unread count ma-update; walay data sa laing account nga dili authorized. |
| [ ] | AUTH-10 | Notification preferences sa account nga adunay Preferences. | In-app/email ug event choices magpabilin human reload. |
| [ ] | AUTH-11 | Sidebar, breadcrumbs, product/page search, pagination ug filters. | Sakto nga page/record; dili mawala ang filters inig next page. |
| [ ] | AUTH-12 | Keyboard Tab/Enter/Escape, modal open/close ug visible focus. | Maabot ang actions; walay trapped focus o natabonan nga required control. |
| [ ] | AUTH-13 | Light/dark appearance kon available; zoom ug small screen. | Mabasa ang text ug amounts; walay importanteng action nga dili maabot. |
| [ ] | AUTH-14 | Help/walkthrough sa Administrator ug Inventory Manager. | Sakto sa Workspace ang giya; ma-close ug mabalikan. |
| [ ] | AUTH-15 | Double-click Submit ug refresh human successful save sa test record. | Walay duplicate record o duplicate money/stock effect. |

## 4. Super Administrator: lokal ra

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | SUP-01 | Control Center: cards, attention items ug links. | Sakto ang status; matag link moadto sa angay nga detail/page. |
| [ ] | SUP-02 | Users & Access: paghimo ug pag-update og ordinaryong Administrator. | Ma-save ang valid account; sakto ang role ug first-login process. |
| [ ] | SUP-03 | Duplicate username/email, invalid fields ug dili angay nga role assignment. | Dili dawaton; walay partial account o accidental privilege. |
| [ ] | SUP-04 | Sulayi ang account protection sa katapusang kinahanglan nga privileged account. | Dili maputol ang required administrative access. |
| [ ] | SUP-05 | Platform Settings: dormancy thresholds, invalid values ug save/reload. | Valid policy magpabilin; invalid values dili ma-save. |
| [ ] | SUP-06 | Dormancy sa disposable old/inactive test account pinaagi sa supported runner. | Warning/disable sumala sa policy; protected accounts dili aksidenteng mawala. Ayaw usba ang oras sa live PC. |
| [ ] | SUP-07 | Email settings: valid provider config ug controlled test recipient. | Madawat ang test email; enabled status sakto; secret dili makita sa logs/UI. |
| [ ] | SUP-08 | Invalid email config o failed delivery. | Klarong failure; dili i-report nga sent ang wala nadeliver; dili mohunong ang lokal nga sale. |
| [ ] | SUP-09 | System Health: database, storage, jobs ug logs nga gipakita. | Sakto ang healthy/failed status ug useful nga next action. |
| [ ] | SUP-10 | Protected Audit Records: Category → Module → Action, dates ug detail drawer. | Sakto ang narrowed filters, actor ug event; pag-view walay mutation. |
| [ ] | SUP-11 | Platform Settings: tunnel setup/removal instructions ug status. | Sakto ang provider/hostname; dili ilhon nga public online tungod lang kay running ang process. |
| [ ] | SUP-12 | Scheduled Backup settings ug key/recovery tools. | Ikompleto ang seksiyon 12; ordinaryong Administrator dili makausab sa platform schedule. |
| [ ] | SUP-13 | Reset Data sa disposable copy: review/cancel, invalid confirmation, dayon valid chosen reset. | Cancel/invalid walay deletion; valid reset limitado sa giingong scope ug adunay audit evidence. |
| [ ] | SUP-14 | Import history, Data Readiness, Model Training ug analytics. | Ikompleto ang technical forecasting tests sa seksiyon 11. |

## 5. Administrator: Store setup ug oversight

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | ADM-01 | Store Overview: periods, cards, charts, attention ug drill-downs. | Matugma sa seeded test records; empty state dili fake zero/success o crash. |
| [ ] | ADM-02 | User Manager: paghimo sa Cashier, Inventory Manager ug multi-role test account. | Sakto ang roles, username ug first-login instructions. |
| [ ] | ADM-03 | Update/deactivate/reactivate sa test staff ug login pag-usab. | Sakto ang saved state ug access; dili maka-govern sa Super Administrator. |
| [ ] | ADM-04 | Registers: add/update, duplicate o invalid input, availability ug paper width. | Sakto ang persisted register; active shift dili madaot pinaagi sa unsafe change. |
| [ ] | ADM-05 | Store Configuration: Store name/contact/receipt fields; save/reload. | Sakto ang data ug bag-ong receipt; invalid input dili ma-save. |
| [ ] | ADM-06 | Receipt logo: valid upload, invalid file ug remove. | Sakto ang preview/print; invalid file walay partial update; required identity magpabilin. |
| [ ] | ADM-07 | Attention thresholds: valid, negative ug invalid values. | Valid thresholds persist; rejected values adunay klarong error. |
| [ ] | ADM-08 | Store Calendar: open day, closure, holiday ug exceptional day sa test dates. | Sakto ang calendar, staff availability ug effect sa operational/forecast views. |
| [ ] | ADM-09 | Fiscal Periods: create/generate schedule ug invalid/overlapping dates. | Valid contiguous periods ra; walay overlap o partial generated schedule. |
| [ ] | ADM-10 | Open period; sulayi close nga adunay unmet prerequisites sa test copy. | Sakto ang active period; gi-block ang premature closure ug giingon unsay kuwang. |
| [ ] | ADM-11 | Complete prerequisites; close/lock ug sulayi ang prohibited write sa period. | Sakto ang state; dili makasulat sa closed/locked scope nga supak sa policy. |
| [ ] | ADM-12 | Scheduled-period amendment/deletion ug automatic-rollover toggle sa test copy. | Valid changes ra; rollover dili molaktaw sa blockers o maghimo og wala gischedule nga period. |
| [ ] | ADM-13 | Promotions: create, activate/deactivate; dates, scope ug minimum quantity. | Valid promotion sakto; inactive/expired/ineligible dili magamit sa checkout. |
| [ ] | ADM-14 | Cashier Shifts: date/cashier filters, drawer detail ug variance. | Matugma sa cashier count, movements, sales ug refunds. |
| [ ] | ADM-15 | GCash Overview: live custody ug filtered historical activity. | Date filter dili mousab sa kasamtangang live cash/wallet balance. |
| [ ] | ADM-16 | GCash closing difference review: cash ug wallet differences, explanation ug finish. | Kinahanglan mareview ang duha; original variance/history magpabilin; review dili mag-imbento og returned funds. |
| [ ] | ADM-17 | Stock Issue Oversight: filters, detail ug correction trail. | Store-wide read-only view; dili adunay inventory posting action. |
| [ ] | ADM-18 | Refund Correction Reviews: assign cash ug stock correction sa angay nga staff. | Klarong linked work; original sale ug refund dili mausab; completed review dili pasabot nga executed na tanan. |
| [ ] | ADM-19 | Manual backup/recovery-key actions ug schedule visibility. | Backup ug key tests sa seksiyon 12 pasar; schedule controls protected. |
| [ ] | ADM-20 | Operational Audit: filter ug tan-awa ang sample saves/actions. | Sakto ang actor, module, time ug result; platform-protected details dili mo-leak. |
| [ ] | ADM-21 | Receiving History ug Receipt History: lookup, filters, detail ug print. | Sakto ang records ug totals; reprint dili maghimo og bag-ong transaction. |
| [ ] | ADM-22 | Model Review & Approval, forecast ug exceptions. | Ikompleto ang operational forecasting tests sa seksiyon 11. |

## 6. Inventory Manager: products ug receiving

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | INV-01 | Products: create/update product, category, barcode, cost, price ug photo. | Sakto ang saved product ug search result; walay duplicate identity. |
| [ ] | INV-02 | Duplicate barcode, blank required field, invalid price ug invalid photo. | Dili dawaton; daang valid values ug stock magpabilin. |
| [ ] | INV-03 | Product search, filters, sorting, paging ug detail/batches. | Sakto ug consistent ang result/count bisan daghang page. |
| [ ] | INV-04 | CSV import: valid file; duplicate/missing columns, invalid row ug wrong file. | Klarong accepted/rejected rows; walay silent duplicate o unexplained partial import. |
| [ ] | INV-05 | Generate barcode labels ug print gamit ang actual label setup. | Mabasa ang code/text; ma-scan balik ngadto sa sakto nga product/form. |
| [ ] | INV-06 | Receive `TEST-A` 100 pieces ug `TEST-B` 50 pieces. | Sakto ang batch, quantity, cost ug receiving record; mahimo bisan optional ang supplier/receipt details. |
| [ ] | INV-07 | Receive by package: 2 cases × 12 pieces; susihon displayed unit cost. | 24 Base Units; cost conversion ug totals sakto. |
| [ ] | INV-08 | Negative/zero quantity, invalid date/cost ug unavailable product sa receiving. | Rejected; walay stock movement o orphaned receiving record. |
| [ ] | INV-09 | Double-submit/retry ang parehas nga receiving human response loss sa test copy. | Usa ra ka committed receipt ug stock addition; dili doble. |
| [ ] | INV-10 | Receiving History: filter, detail, quantities, costs ug print kon available. | Pareho sa posted receiving; dili mausab ang stock tungod sa pag-view/print. |
| [ ] | INV-11 | Stock Overview: in-stock, low, out-of-stock, near-expiry ug expired details. | Sakto ang product/batch counts; expired stock dili apil sa saleable quantity. |
| [ ] | INV-12 | Recent Stock Movements ug Stock Movement tab: filters, drawer ug full report. | Sakto ang receiving/correction trail ug stated period; report dili kutob sa visible page. |
| [ ] | INV-13 | Stock Movement sa bag-ong product nga kulang ang history. | Klarong limited-history state; walay invented fast/slow/dead conclusion. |
| [ ] | INV-14 | Replenishment Recommendations: search/filter, detail ug report/PDF. | Sakto ang units, stock ug recommended quantities; export mabasa ug kompleto. |
| [ ] | INV-15 | Demand Forecast ug available report links. | Authorized views mo-load; walay technical model-configuration controls. |

## 7. Inventory Manager: counts, stock issues, pullouts ug recovery sa forms

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | CTL-01 | Inventory Counts: create count, enter actual quantities, submit ug authorized approval. | Recorded vs counted variance sakto; stock correction sa approval ra ug usa ra ka beses. |
| [ ] | CTL-02 | Count nga walay variance, invalid/negative quantity ug repeated approval. | Walay fabricated movement; invalid/repeated action dili makadoble sa stock effect. |
| [ ] | CTL-03 | Sales Pack: receive sealed packs ug loose pieces; ablihi ang usa ka pack. | 1 pack mahimong 12 loose pieces; total Base Units dili mausab. |
| [ ] | CTL-04 | Count correction sa sealed ug loose forms; stock changed during count. | Sakto ang form/total reconciliation; stale quantities dili silent overwrite. |
| [ ] | CTL-05 | Stock Issue: dawata ang report sa Cashier; review ug valid correction. | Sakto ang status ug stock effect; actor/reason nasubay. |
| [ ] | CTL-06 | Reject/cancel issue kon available; over-quantity ug duplicate correction. | Walay dili angay o doble nga deduction; klarong rejection. |
| [ ] | CTL-07 | Supplier Pullouts: eligible batch, quantity ug source details. | Selected batch ra ang ma-deduct; sakto ang remaining stock ug history. |
| [ ] | CTL-08 | Ineligible batch, over-pullout ug repeated submit. | Blocked; walay negative stock o duplicate movement. |
| [ ] | CTL-09 | Assigned Refund Correction Review: execute stock correction; laing manager mosulay. | Assigned/authorized actor ra; usa ka linked stock effect; original refund intact. |
| [ ] | CTL-10 | Enter unsaved values sa Product, Receiving, Count, Stock Issue ug Pullout; reload/restart browser. | Sa supported secure context, explicit Resume/Discard; sakto nga draft ug walay automatic posting. |
| [ ] | CTL-11 | Manager B login sa browser nga adunay draft ni Manager A. | Dili niya makita o ma-resume ang private draft ni A. |
| [ ] | CTL-12 | Validation failure, expired session ug pre-send network loss sa form. | Klarong failed state; unsent values dili basta mawala; explicit revalidation/retry. |
| [ ] | CTL-13 | Lose response human committed receiving/count/issue; reconnect ug retry. | I-verify sa history: usa ra ka operation ug stock effect; bag-ong unsent edits dili mawala. |
| [ ] | CTL-14 | Plain HTTP LAN context o browser nga walay durable storage support. | Preservation limitation klarong ipakita; dili i-claim nga safely saved ang dili mapreserve. |

## 8. Cashier: shift, checkout ug receipts

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | POS-01 | Sulayi checkout nga walay open shift; dayon open shift sa available Register. | Kinahanglan ang valid open shift; sakto ang starting count ug ownership. |
| [ ] | POS-02 | Cashier B mosulay sa occupied Register o shift ni A. | Walay unauthorized use o duha ka conflicting active shift. |
| [ ] | POS-03 | Barcode scan, name search, consecutive scans ug unknown barcode. | Sakto nga item/quantity; unknown code dili maghimo og product o sale. |
| [ ] | POS-04 | Add/remove item, edit quantity, clear cart ug cancel checkout. | Sakto ang cart totals; walay sale o stock deduction sa cancel. |
| [ ] | POS-05 | `TEST-A × 2`, total PHP 50, pay PHP 100. | Change PHP 50; usa ka sale/receipt; stock 100 → 98 sa limpyo nga baseline. |
| [ ] | POS-06 | Cart nga `TEST-A × 2 + TEST-B × 1`. | PHP 90 before adjustments; sakto ang per-line amount ug stock deduction. |
| [ ] | POS-07 | Kulang nga bayad, zero/negative quantity ug out-of-stock/expired-only product. | Rejected; walay partial sale/payment/stock write. |
| [ ] | POS-08 | Piece barcode ug pack barcode sa Sales Pack product. | Sakto nga form, price ug deduction; dili ibaligya ang wala nga sealed/loose stock. |
| [ ] | POS-09 | 250 mL ug 500 mL prepared oil pouch. | PHP 20/PHP 40 ug sakto nga pouch count; dili masayop og volume o stock unit. |
| [ ] | POS-10 | Eligible promotion; dayon expired, wrong-scope ug below-minimum cart. | Usa ra ka permitted promotion; totals sakto; ineligible dili magamit. |
| [ ] | POS-11 | Hold sale, navigate away, resume ug cancel held sale. | Sakto nga restored cart; holding dili pa completed sale o duplicate stock deduction. |
| [ ] | POS-12 | Balikon ang matag non-cash payment method nga actual available sa checkout. | Required reference/details gi-validate; dili madugang sa cash drawer ang non-cash payment. |
| [ ] | POS-13 | Double-click Pay, refresh receipt ug retry human response loss sa test copy. | Usa ra ka sale, payment ug stock effect; i-verify sa Receipt History. |
| [ ] | POS-14 | Duha ka Cashier mobayad sa katapusang available unit halos dungan. | Usa ra ang successful kon usa ra ang stock; walay negative stock. |
| [ ] | POS-15 | Sale Receipt: totals, paid/change, cashier/register, Store identity ug logo. | Pareho sa sale; walay missing o cut-off nga importanteng data. |
| [ ] | POS-16 | Print/reprint sa actual thermal printer; cancel print dialog ug retry. | Sakto ang paper width, barcode/text ug margins; reprint dili bag-ong sale. Browser device ra ang magbuot sa printer. |
| [ ] | POS-17 | Record drawer money-in ug money-out nga adunay source/destination ug reason. | Usa ka movement; expected cash ug audit trail sakto. |
| [ ] | POS-18 | Invalid amount, blank source/destination ug repeated drawer submit. | Blocked o recognized retry; walay duplicate money effect. |
| [ ] | POS-19 | Lock/break ug resume/unlock kon available. | Locked shift dili makapost sa prohibited cash operations. |
| [ ] | POS-20 | Close shift: denomination count, expected cash, shortage/overage ug recent shifts. | Starting cash + cash sales/additions − cash refunds/removals = expected cash; counted difference sakto. |
| [ ] | POS-21 | Sulayi checkout/refund/movement gamit ang closed shift o stale second tab. | Rejected; dili mausab ang closed shift. |
| [ ] | POS-22 | Dashboard Overview ug Transaction History: dates, categories ug pagination. | Kaugalingong cashier activity ra; cash ug non-cash figures sakto. |
| [ ] | POS-23 | Receipt History: pangitaa ang receipt ni Cashier B ug reprint. | Authorized Store-wide lookup molihok; pagtan-aw dili awtomatikong refund permission. |
| [ ] | POS-24 | Report Stock Issue: scan/select product, quantity, reason ug submit. | Masubay ngadto sa Inventory Manager review; walay duplicate report sa retry. |

## 9. Cashier: refunds ug correction work

Gamita ang lahi nga test sale sa matag branch aron dili magkasagol ang remaining refundable quantities. Ablihi ang kaugalingong unlocked shift ug andama ang cash.

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | REF-01 | Partial refund sa kaugalingong cash sale; markahi `Restockable`. | Sakto ang refund amount; drawer minus cash refund; returned units madugang sa saleable stock. |
| [ ] | REF-02 | Refund nga `Damaged` ang returned goods. | Cash refund recorded; dili idugang sa saleable stock ang damaged units. |
| [ ] | REF-03 | Blank disposition/reason, zero/over-quantity ug over-refund amount. | Rejected; remaining refundable quantity ug amount dili malapas. |
| [ ] | REF-04 | Balik refund sa fully refunded item; double-submit ug response-loss retry. | Walay duplicate refund, drawer deduction o restock. |
| [ ] | REF-05 | Refund sa sale ni Cashier B: walay approval, wrong approval, dayon valid required supervisor exception. | Dili malaktawan ang approval/reason; sakto ang original cashier ug actual refund issuer. |
| [ ] | REF-06 | Refund sa non-cash sale gamit ang original method ug confirmed external reference. | Required confirmation/reference; dili mokunhod ang product cash drawer. |
| [ ] | REF-07 | Receipt ug history human partial/full refund; date/category filters. | Original sale magpabilin; cumulative refunded status ug refund receipt sakto. |
| [ ] | REF-08 | Administrator assigns cash correction; assigned Cashier executes sa valid own shift. | Usa ka linked drawer correction; dili usbon o tangtangon ang original refund. |
| [ ] | REF-09 | Laing Cashier o closed/locked shift mosulay sa assigned correction. | Blocked; walay money effect. |
| [ ] | REF-10 | Human sa cash ug stock correction, Administrator moreview sa linked results. | Matag task adunay klarong state; review completion dili motago sa outstanding task. |

## 10. GCash Service: bulag sa product-sale drawer

Mga test record ra kini. Ang pag-record sa RetailMind dili ebidensiya nga nakompleto ang actual wallet transfer. Gamita ang test references ug controlled balances; ang actual financial verification sa wallet kinahanglan tag-iya ang modumala.

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | GCS-01 | Open service session gamit ang required open shift ug opening service cash/wallet. | Sakto nga controlling Cashier; bulag sa product drawer. |
| [ ] | GCS-02 | Laing Cashier mosulay og controlling actions sa active service session. | Sakto ang custody restrictions; walay silent takeover. |
| [ ] | GCS-03 | Cash-in preview ug completed test record. | Principal, fee, money direction ug cash/wallet effects matugma sa preview. |
| [ ] | GCS-04 | Cash-out preview ug completed test record. | Sakto ang fee ug inverse cash/wallet movement; walay product stock effect. |
| [ ] | GCS-05 | Matag available fee-payment option; minimum/maximum ug invalid principal. | Configured rules ug rounding sakto; invalid input blocked. |
| [ ] | GCS-06 | Duplicate/invalid reference, missing required evidence ug insufficient available funds. | Klarong validation; walay duplicate o unauthorized balance effect. |
| [ ] | GCS-07 | Unfinished/needs-checking exchange ug follow-up list. | Outstanding responsibility makita; dili ipakita nga completed o hatagan completed-service receipt. |
| [ ] | GCS-08 | Resolve exchange pinaagi sa valid completion o failed-exchange return. | Sakto ang returned cash/wallet/fee ug remaining obligation; original history intact. |
| [ ] | GCS-09 | Transfer follow-up responsibility ug explicit acceptance sa incoming Cashier. | Bag-ong responsible person nasubay; original recorder ug money history dili mausab. |
| [ ] | GCS-10 | Service Fund Movements: add/withdraw cash ug wallet, source/destination ug receipt kon available. | Usa ka funding movement; dili customer cash-in/cash-out; sakto ang balances. |
| [ ] | GCS-11 | Product-drawer transfer ngadto sa service fund kon supported sa chosen movement. | Ikompara ang duha ka money histories; dili doble ug dili mawala ang matching drawer effect. |
| [ ] | GCS-12 | Linked correction nga nanginahanglan Administrator approval; wrong approval ug valid approval. | Unauthorized blocked; valid delta ug reason linked sa original exchange. |
| [ ] | GCS-13 | Handover/session closure: count service cash ug verify wallet. | Sakto ang counted vs expected; POS shift closure lamang dili automatic custody transfer. |
| [ ] | GCS-14 | Session nga adunay difference ug unfinished exchange; close/reopen sunod nga workflow. | Outstanding follow-up dili mawala; closing difference ug reviewer makita. |
| [ ] | GCS-15 | Completed service receipt: preview, print ug reprint. | Principal, fee, reference ug totals sakto; walay bag-ong exchange tungod sa print. |
| [ ] | GCS-16 | Cashier history/Service Fund Movements ug Administrator All money history. | Cashier scope sakto; Administrator Store-wide history ug live custody consistent. |

## 11. Reports ug forecasting

### Report catalog

Sa matag report: pili og range nga adunay known records ug range nga walay data; test filters, paging, preview, available export/print, ug totals. Ikompara sa source sale/refund/receiving/movement. Ang export kinahanglan moapil sa tanang matching rows, dili visible page ra. Markahi ang format nga wala gyod gitanyag nga `N/A`, adunay rason.

| OK | ID | Report | Authorized role nga sulayan |
| --- | --- | --- | --- |
| [ ] | RPT-01 | Daily, Weekly and Monthly Sales | Administrator ug Inventory Manager |
| [ ] | RPT-02 | Sales by Product | Administrator ug Inventory Manager |
| [ ] | RPT-03 | Sales by Category | Administrator ug Inventory Manager |
| [ ] | RPT-04 | Sales by Cashier | Administrator; denied sa Inventory Manager |
| [ ] | RPT-05 | Refund History | Administrator; denied sa Inventory Manager |
| [ ] | RPT-06 | Fast-Moving Products | Administrator ug Inventory Manager |
| [ ] | RPT-07 | Slow-Moving Products | Administrator ug Inventory Manager |
| [ ] | RPT-08 | Low-Stock and Out-of-Stock Products | Administrator ug Inventory Manager |
| [ ] | RPT-09 | Inventory Valuation | Administrator ug Inventory Manager |
| [ ] | RPT-10 | Stock Movement History | Administrator ug Inventory Manager |
| [ ] | RPT-11 | Damaged, Missing and Expired Items | Administrator ug Inventory Manager |
| [ ] | RPT-12 | Forecast Versus Actual Demand | Administrator ug Inventory Manager |
| [ ] | RPT-13 | Expiration Report | Administrator ug Inventory Manager |
| [ ] | RPT-14 | Physical-Count Variance Report | Administrator ug Inventory Manager |
| [ ] | RPT-15 | Gross Profit Report | Administrator ra; sales/cost/refund calculation dili i-label nga net profit |
| [ ] | RPT-16 | GCash Transactions | Administrator ra; date, Cashier, search ug exchange-type filters; CSV totals sakto |

### Forecast lifecycle

Ayaw pugsa ang production nga bag-o pa ug walay historical data nga mopakita og trained forecast. Ang `collecting`/cold-start state mahimong husto; ang full training/approval lifecycle sulayan sa bulag nga copy nga adunay valid history, dili fabricated sales sa live Store.

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | FCT-01 | Fresh Store: ablihi Data Readiness, Demand Forecast ug Replenishment. | Klarong kulang pa ang history; walay invented prediction o false training-failure alert. |
| [ ] | FCT-02 | Super Administrator imports valid sales history sa test copy; invalid file/duplicate rows usab. | Sakto ang validation, accepted dates/products ug attribution; walay unexplained duplicate history. |
| [ ] | FCT-03 | Data Readiness human import: product eligibility ug unavailable/stockout/closed days. | Counts ug readiness motakdo sa known input; dili himuong zero-demand sales ang wala nga observation. |
| [ ] | FCT-04 | Model Training/settings sa supported Python environment; launch valid job. | Klarong queued/running/completed o actionable failure; walay fabricated success. |
| [ ] | FCT-05 | Missing worker/Python o failed job sa test copy. | Klarong unavailable/failed state; POS ug inventory dili mohunong. |
| [ ] | FCT-06 | Administrator reviews candidate, evidence, approve/replace ug rollback. | Klaro unsay candidate ug active model; authorized transition ra; previous evidence magpabilin. |
| [ ] | FCT-07 | Demand Forecast: product/category, dates/horizon, notices ug replenishment detail. | Sakto ang units, dates ug active-model state; quantity lahi sa sales frequency. |
| [ ] | FCT-08 | Forecast Analytics ug Forecast Exceptions; filters ug drill-downs. | Metrics ug exception reasons makita ug consistent sa job/history. |
| [ ] | FCT-09 | ML scheduled task manual run ug next unattended run sa test copy. | Nasubay ang actual run/outcome; dili magkinahanglan nga adunay browser nga open. |
| [ ] | FCT-10 | Inventory Manager/Cashier mosulay sa technical settings/import/training/approval. | Walay unauthorized model mutation; Inventory Manager view-only sa permitted forecast pages. |

## 12. Backups, Recovery Keys ug restore

**Ang actual restore, key deletion/promotion ug recovery failure drills sa isolated test copy ra.** Ayaw i-delete ang usa ra ka key nga nag-abli sa real backup. Andama ang backup ug separate key copy sa dili pa testing; ayaw ibutang ang package ug key sa usa ra ka lost-device risk.

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | BAK-01 | Administrator ug Super Administrator: manual encrypted backup, download ug history. | Valid package; sakto ang type/status/time; file dili empty ug naa sa private destination. |
| [ ] | BAK-02 | Human download: Recovery Key recommendation; dismiss, wrong password, dayon valid export. | Dili i-acknowledge ang dismiss/failed export; downloaded key fingerprint motakdo sa backup. |
| [ ] | BAK-03 | Super Administrator saves weekly day/time/destination; invalid web-root/relative/unwritable destination. | Valid setting ra; ordinaryong Administrator read-only sa schedule. |
| [ ] | BAK-04 | Due scheduled run sa test copy pinaagi sa scheduled task nga walay browser login. | Usa ka validated package ug history; next/last-run status sakto. |
| [ ] | BAK-05 | Missed schedule/catch-up ug busy concurrent backup/restore sa test copy. | Klarong catch-up/skip/retry; walay conflicting o duplicate capture. |
| [ ] | BAK-06 | Scheduled retention human bag-ong successful package; failed run usab. | Valid new copy una sa removal; manual/safety copies dili apil; failed run dili motangtang sa good copy. |
| [ ] | BAK-07 | Failed backup tungod sa controlled destination error sa test copy. | Klarong failure/attention/log; walay success claim o pagkawala sa previous package. |
| [ ] | BAK-08 | Backup Keyring: list, export by fingerprint, import duplicate ug laing test key. | Walay key material sa UI/logs; additive/idempotent import; existing primary dili mausab nga walay explicit promotion. |
| [ ] | BAK-09 | Promote imported test key; open old ug new packages. | Daang primary archived; duha ka generation sa backup recoverable. |
| [ ] | BAK-10 | Delete disposable imported key: cancel, wrong password ug valid confirmation; primary deletion usab. | Cancel/invalid walay deletion; current primary protected; warning klaro unsay packages nga maapektuhan. |
| [ ] | BAK-11 | Restore review: valid current package; wrong/missing key ug damaged package. | Valid summary una sa confirmation; invalid package blocked sa dili pa data replacement. |
| [ ] | BAK-12 | Known supported older package ug unsupported/newer structure. | Klarong current/upgradable/unsupported result; unsupported walay destructive write. |
| [ ] | BAK-13 | Identity reconciliation: same person ug different people scenarios. | Explicit mapping; destination protected access authoritative; attribution dili ma-merge sa laing tawo. |
| [ ] | BAK-14 | Confirm restore sa isolated replacement installation, apil uploaded product/receipt images. | Safety-copy/recovery flow molihok; records, images ug totals restored; destination migrations dili ma-rollback. |
| [ ] | BAK-15 | Human restore: reopen Store ug login; check products, receipt, reports ug audit provenance. | Magamit ang system; expected restored balances/history; protected destination access intact. |
| [ ] | BAK-16 | Restore interruption/failure drill sa disposable copy. | Dili i-report nga reopened ang incomplete restore; documented recovery path ug safety copy magamit. |
| [ ] | BAK-17 | Recovery-account route/tool nga gitanyag sa installation guide, sa test copy. | Valid proof ra ang makarecover; invalid proof blocked; dili ma-lock out ang required administrative access. |

## 13. Role restrictions ug direct-link tests

Dili igo nga wala sa sidebar ang page. Kuhaa ang target URL gikan sa authorized session, dayon ablihi gamit ang restricted account sa bulag nga browser. Para sa write endpoints, kinahanglan walay data change bisan kon gi-deny ang request; ang teknikal nga tester mahimong mosusi niini sa test copy.

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | ACL-01 | Cashier opens Users, Store/Platform Settings, Inventory mutation ug Reset Data direct links. | Denied; walay leaked protected data o unauthorized write. |
| [ ] | ACL-02 | Inventory Manager opens Users, Register management, Platform Settings, Reset Data ug model approval. | Denied sa prohibited operations. |
| [ ] | ACL-03 | Inventory Manager directly requests Sales by Cashier, Refund History, Gross Profit ug GCash Transactions reports/exports. | Denied, apil download endpoints; dili igo nga gitago lang ang report option. |
| [ ] | ACL-04 | Administrator directly requests platform governance/reset/schedule mutation/technical-model configuration. | Denied; Store operations ra ang gitugot sa active Workspace. |
| [ ] | ACL-05 | Super Administrator mosulay sa POS ug direct operational inventory write nga wala niya nga Workspace. | Dili makalaktaw pinaagi sa platform privilege; active Workspace policy gisunod. |
| [ ] | ACL-06 | Multi-role account sa Inventory Workspace mo-open og Cashier-only write; dayon switch properly. | Denied una; tugotan lang kon sakto na ang active Workspace ug prerequisites. |
| [ ] | ACL-07 | Cashier B opens private history/detail/correction ni A nga dili authorized. | Private scope protected; Store-wide Receipt History dili mahimong approval bypass. |
| [ ] | ACL-08 | Disabled account o role nga gitangtang: login ug existing-session action. | Current access policy enforced; walay stale privilege nga makapost. |
| [ ] | ACL-09 | Technical tester: missing/invalid form security token ug tampered record IDs sa test copy. | Rejected; walay unauthorized money, stock, settings o account change. |
| [ ] | ACL-10 | Probe known sensitive `.env`, `.git`, backend/storage, SQL, backup ug model-artifact paths sa lokal ug public origins. | Walay readable private contents o directory listing; record status without saving secrets. |

## 14. Localhost + LAN IP + remote tunnel: dungan

`localhost` sa laing phone/PC nagtumong sa maong device, dili sa Store PC. Ang laing Store device mogamit sa **LAN IP sa Store PC**. Internet-off test: putla ang internet/WAN samtang magpabilin ang lokal nga router/Wi-Fi/LAN; kon patyon usab ang router, dili na kini valid LAN-fallback test.

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | NET-01 | Tunnel off: ablihi localhost sa Store PC ug LAN URL sa laing device. | Duha magamit; configured firewall/private-network access sakto. |
| [ ] | NET-02 | Start tunnel; dungan ablihi localhost, LAN ug public HTTPS URL. | Tulo magamit; walay forced redirect sa lokal ngadto sa public domain. |
| [ ] | NET-03 | Gikan sa public link gamit phone nga mobile data: login Administrator, Inventory Manager ug Cashier. | Matag role makaabot sa kaugalingong Workspace; dili LAN-only nga false remote test. |
| [ ] | NET-04 | Balika ang applicable tests sa seksiyon 3 ug 5–11 pinaagi sa public link. | Pareho nga permitted functionality: saves, stock/money effects, reports, receipts ug role restrictions. Irekord tagsa-tagsa ang test IDs/origin. |
| [ ] | NET-05 | Public login/direct link sa `super_admin`; multi-role switcher kon applicable. | Super Administrator blocked ug dili mapilian; ordinaryong Workspace dili ma-block. |
| [ ] | NET-06 | Authorized remote record, dayon tan-awa sa local session; local record ngadto sa remote. | Usa ra ka database/history; makita ang parehas nga committed record human refresh. |
| [ ] | NET-07 | Stop tunnel gikan sa authorized local session samtang open ang localhost ug LAN. | Remote access maputol; Apache/database magpabilin; local checkout, receiving ug receipt print molihok. |
| [ ] | NET-08 | Internet/WAN off samtang tunnel ug lokal nga network nagdagan. | Remote unavailable; localhost ug LAN checkout/receiving/printing magpadayon; walay dependency sa public assets para sa critical action. |
| [ ] | NET-09 | Internet reconnect ug tunnel recovery. | Public route mobalik ug sakto ang status; offline-period records intact; walay duplicate retried transaction. |
| [ ] | NET-10 | Reboot nga configured automatic tunnel startup. | Apache/database ug tunnel mobalik; local ug mobile-data public probe parehong pasar. |
| [ ] | NET-11 | Public URL copy/QR/status sa matag role. | QR ug copied URL sakto; unauthorized staff dili makausab sa tunnel config o stop/start. |
| [ ] | NET-12 | Kon custom domain na: configured DNS/HTTPS ug installed permanent tunnel host. | Valid certificate ug exact hostname; actual app access pasar; saved credentials lamang dili isipon nga activated na. |
| [ ] | NET-13 | Login/session, assets, uploads, downloads ug form submits sa local HTTP/LAN ug public HTTPS. | Walay mixed-content, wrong-origin redirect o cookie login loop; settings dili moputol sa usa ka access path. |
| [ ] | NET-14 | LAN IP human reboot ug router reconnect; bookmarks sa staff devices. | Sakto gihapon ang address o gi-update ang setup; dokumentado ang stable-IP/DHCP reservation arrangement. |
| [ ] | NET-15 | Tunnel process running apan public route dili maabot sa controlled test. | Dili false `Online`; local app magpadayon; failure makita sa status/log. |

Origin completion record: isulat ang covered IDs, dili usa lang ka general nga tsek.

| Role | Localhost tested IDs / result | Physical LAN-device IDs / result | Public mobile-data IDs / result |
| --- | --- | --- | --- |
| Super Administrator | | | Denial test `NET-05` ra; walay operational access |
| Administrator | | | |
| Inventory Manager | | | |
| Cashier | | | |

## 15. Actual hardware, interruptions ug full Store-day rehearsal

| OK | ID | Buhaton | Expected result |
| --- | --- | --- | --- |
| [ ] | DEV-01 | Actual USB/Bluetooth scanner sa matag device nga gamiton: 10 consecutive scans. | Sakto ang code/quantity; walay missed/double scan; unknown code walay unwanted record. |
| [ ] | DEV-02 | Scanner samtang naa ang focus sa laing text/number field. | Dili aksidenteng mo-submit, mo-advance o mousab sa unrelated form value. |
| [ ] | DEV-03 | Actual thermal printer: sale, refund ug completed GCash receipt. | Sakto nga printer/paper, readable text ug totals; cancel/reprint walay duplicate transaction. |
| [ ] | DEV-04 | Tablet/phone: portrait ↔ landscape, on-screen keyboard ug long form/dialog. | Cart ug unsaved values magpabilin; Save/Pay/Cancel maabot; walay clipped amount. |
| [ ] | DEV-05 | Matag actual browser/device nga gamiton, apil Safari kon gamiton sa Store. | Core workflow pasar sa maong browser; desktop/emulation result dili ipuli sa physical acceptance. |
| [ ] | DEV-06 | Duha o labaw pa ka device: checkout, receiving ug Administrator review dungan. | Sakto ug responsive ang records/balances; walay cross-user draft/session leak. |
| [ ] | DEV-07 | Browser close/restart mid-cart ug mid-inventory form sa test copy. | Sakto ang documented hold/draft behavior; walay automatic sale o stock posting. |
| [ ] | DEV-08 | Controlled service/network interruption sa test copy human submit; reconcile history una sa retry. | Klaro kon saved o unsaved; usa ra ka transaction effect; recoverable ang app. |
| [ ] | DEV-09 | Reboot/UPS recovery drill sa test copy sa agreed maintenance window. | Walay uncontrolled live-data loss; services mobalik ug last committed records masusi. |
| [ ] | DAY-01 | Administrator opens fiscal period/Store day → Manager receives stock → Cashier opens shift. | Available ang sakto nga Register/products ug operational prerequisites. |
| [ ] | DAY-02 | Cash sale → non-cash sale → partial refund → Stock Issue → Manager correction. | Matag role/history sakto; stock, drawer ug source documents magkatugma. |
| [ ] | DAY-03 | GCash service → funding movement → handover → closing difference review. | Service cash/wallet ug follow-up bulag ug masubay gikan sa product drawer. |
| [ ] | DAY-04 | Cashier closes shift → Administrator checks reports/audit → manual backup ug key export. | Closing figures matugma; package/key saved; walay unresolved test discrepancy. |

## 16. Final acceptance sa live Store configuration

Human sa test-copy rehearsal, buhata kini sa final installation. Ayaw paghimo og dummy financial records nga mahimong masagol sa live accounting. Kon kinahanglan actual test transaction, ang tag-iya motugot ug modumala sa sakto nga recording/correction, dili silent database deletion.

- [ ] Ang installed release ug database sakto; test history/products wala masagol sa final Store data.
- [ ] Ang real Store name, receipt identity, logo, pricing, Registers, calendar ug fiscal period sakto.
- [ ] Ang real accounts ug Workspace assignments sakto; mandatory passwords nausab; wala gigamit ang shared default login.
- [ ] Localhost, physical LAN-device login ug mobile-data tunnel login pasar; Super Administrator denied sa public link.
- [ ] Actual scanner ug printer pasar sa matag device nga gamiton; ang staff kabalo unsang printer ang pilion.
- [ ] Sakto ang Store PC LAN address/bookmarks; kabalo ang staff mobalhin sa LAN URL kon walay internet.
- [ ] Backup destination ug scheduled-task account access sakto sa live config; adunay fresh validated backup.
- [ ] Recovery Key na-export ug nakupot off-PC; ang tag-iya kabalo asa ang package ug lahi nga key copy.
- [ ] Successful restore rehearsal sa replacement/test copy adunay evidence; wala ra gisaligan ang download success.
- [ ] Scheduled tasks, email recipients ug provider accounts sakto sa live Store; walay test recipient/secret nga nabilin.
- [ ] Forecasting actual state documented: collecting, ready o active; optional unavailable features giuyonan ug wala gitago isip pasar.
- [ ] Walay unresolved failure sa stock, money, permissions, local fallback, hardware o recovery.
- [ ] Ang open failures/N/A ug ilang epekto nabasa ug giuyonan sa tag-iya; required functionality dili i-waive isip `PASS`.
- [ ] Technician ug tag-iya naka-run sa usa ka complete rehearsal; staff nakahibalo sa login, checkout, shift close ug issue escalation.

| Final decision | Isulat |
| --- | --- |
| `READY` / `NOT READY` | |
| Mga blocked ID / agreed limitations | |
| Technician / date | |
| Tag-iya / date | |
| Backup ug restore evidence reference | |

## Failure log

Usa ka row matag failure. Human fix, balika ang failed test ug dependent workflow; ayaw papasa ang original evidence.

| Test ID + origin | Account/Workspace + device | Steps ug expected result | Actual result / safe screenshot | Severity / epekto | Fix reference | Retest date / result |
| --- | --- | --- | --- | --- | --- | --- |
| | | | | | | |
| | | | | | | |
| | | | | | | |

**Hunong sa deployment** kon adunay wrong/duplicate money o stock effect, unauthorized access, failed local fallback, dili magamit nga required hardware, o wala pay napamatud-ang backup/restore. Ang UI-only issue irekord gihapon ug timbangon ang actual epekto.

## Optional developer checks

Kon installed ang Node.js/npm sa Store PC, gikan sa project root:

```powershell
npm run check
npm run check:contracts
```

Irekord ang exact result; ayaw ilhon nga passed ang suite nga adunay known/baseline failures. Dili kini kapuli sa manual money/stock reconciliation, physical devices o restore rehearsal. Database-mutating automated tests sa isolated test database ra.

## Coverage basis

Kini gi-map sa current [Workspace Map](../WORKSPACE_MAP.md), `src/frontend/components/sidebar.php`, `RoleCapabilityPolicy.php`, ug `ReportAccessService.php`; dili lang sa karaang screenshots/manual. Apil ang shared account/profile/preferences/help/notifications, upat ka Workspace, report catalog, hardware ug network/recovery behavior.

Mga supporting reference:

- [Tunnel workspace policy](../adr/0030-tunnel-sessions-have-full-workspace-parity.md) ug [provider/domain activation](../adr/0031-free-ngrok-demo-with-cloudflare-domain-upgrade.md).
- [Scheduled Backups ug Recovery Keys](../specs/scheduled-backups-and-recovery-keys.md).
- [Role/device acceptance ug browser-draft limitations](role-device-acceptance.md).
- [Current deployment checklist](../DEPLOYMENT_CHECKLIST.md).

Kon adunay bag-ong menu o action sa gi-install nga release, idugang ang iyang successful action, invalid-input case ug restricted-role case sa dili pa final sign-off. Ang checklist usa ka manual acceptance plan; dili pamatuod nga nasulayan na ang tanang posibleng kombinasyon.
