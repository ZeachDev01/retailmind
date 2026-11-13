# RetailMind Capstone Text Ready to Paste
Mga kapuli nga paragraph ug table alang sa revised capstone • 4 November 2026

Kini nga dokumento adunay English passages nga mahimo nimong kopyahon diretso ngadto sa capstone. Matag block naay “Asa ibutang” ug “Buhaton” nga nag-ingon unsang paragraph, table, o caption ang ilisan o dugangan. Ang original manuscript wala giusab.

Kopyaha lamang ang sulod ubos sa **Text to paste**. Ayaw apila ang block number, “Asa ibutang,” “Buhaton,” ug editor notes. Ang English content gisulat isip manuscript text; ang Cebuano labels giya lamang.

Ang [FILL IN ...] gamiton lamang sa actual respondent data, hardware records, dates, ug test outcomes nga kinahanglan gikan sa inyong records. Ilisi kini sa verified information sa dili pa final printing. Ayaw paghimo og scores, signatures, o passed tests nga wala pa mahitabo.

Ang forecasting rules naggamit sa 140 accumulated usable days, uban sa recent-input ug chronological-evaluation checks. Ang 56/180/365 bands history coverage lamang. Ang benchmark results ug Store activation dili pareho.

## Asa magsugod
| Bahin sa capstone | Mga block |
| Chapter 1 | 01 to 07 |
| Chapter 2 and technical tools | 08 to 12 |
| Chapter 3 requirements and diagrams | 13 to 19 |
| Chapter 3 forecasting and testing | 20 to 28 |
| Prototype descriptions | 29 to 32 |
| Chapter 4 results and interpretation | 33 to 40 |
| Chapter 5 | 41 to 43 |
| User manual and Appendix B | 44 to 48 |
| References and field evidence | 49 to 52 |
| Selected data dictionary | Appendix at the end |

Ang captions dinhi mosaysay sa current behavior. Ang bag-ong badges, progress bars, ug reason-specific UI copy nga wala pa ma-implement adunay lahi nga **proposed interface** block. Ayaw kini ipresentar isip completed result hangtod ma-implement ug ma-check.

Ang diagram descriptions ready to paste, apan ang existing architecture, context, DFD, use-case, ug ERD images kinahanglan gihapon i-update aron motakdo sa text. Ang data dictionary appendix adunay exact selected fields; dili kini gi-label nga complete database schema.

[[PAGE]]
# Chapter 1 project context and purpose
## 01 Forecasting model description
@Asa ibutang: 1.1 Project Context. Pangitaa “The proposed web-based system integrates a Random Forest Regressor” ug ang sunod nga paragraph nga “The Random Forest Regressor was selected”. Source P0050 to P0051.
@Buhaton: ILISI ang duha ka paragraph sa text sa ubos.
@Text to paste
RetailMind uses a global Random Forest Regressor to estimate recorded Product sales for the next 28 days and summarize the estimates over 7-, 14-, and 28-day horizons. The model combines predictions from multiple decision trees and uses Product identity, forecast step, recent sales lags, rolling sales statistics, and target-date indicators. The implemented feature set contains 14 predictor columns before encoding. Product categories, prices, promotions, local-event indicators, and current stock quantities are not active predictors in this feature set.

The model was selected to examine nonlinear relationships among historical sales and date-related variables across multiple Products. Its suitability is evaluated against a weekly Seasonal Naive baseline using chronological data. Retraining creates a candidate model; it does not automatically improve forecast accuracy or authorize operational use. Forecasts remain estimates of recorded sales, and replenishment recommendations remain advisory. Authorized Store personnel retain responsibility for purchasing and receiving goods.

## 02 Purpose and initial data collection
@Asa ibutang: 1.2 Purpose and Description. Pangitaa ang paragraph nga nagsugod “RetailMind: An AI-Driven Inventory Management” ug adunay “collecting store sales data for a period of time”. Source P0056.
@Buhaton: ILISI ang tibuok paragraph. Ayaw ilisi ang approved project title sa cover.
@Text to paste
RetailMind supports inventory monitoring, sales recording, and advisory replenishment planning for Shalom Store. During initial deployment, the application collects supported Store history and provides stock-rule buying guidance when usable forecasts are unavailable. Each Product requires at least 140 accumulated usable days, a supported recent 28-calendar-day input window, and three chronological 28-day evaluation periods backed by earlier training history before it is eligible for Store model training. Genuine observed zero sales count as usable history; closures, stockouts, and unknown observations do not add usable days or reset the accumulated total.

Training produces a candidate that must satisfy the configured quality and baseline checks. Operational forecasting additionally requires completed prospective observation, local Administrator approval, a compatible Store model, and current usable output. Certified historical daily sales from Shalom Store may contribute through the controlled history-import workflow. Public benchmark data remain isolated from Store operations. Forecasts and suggested buying quantities support human decisions and do not automatically create orders or change inventory.

[[PAGE]]
# Chapter 1 objectives
## 03 Role objectives
@Asa ibutang: 1.3.2 Specific Objectives. Ilisi ang role list gikan “Super Administrator” hangtod katapusan sa Cashier list, sa dili pa “To implement and integrate a Random Forest Regressor”. Source P0065 to P0087.
@Buhaton: ILISI ang daang role list. Ibilin ang general objective ug ubang specific objectives.
@Text to paste
**Super Administrator**
- Manage technical platform configuration, privileged access, platform maintenance, and technical audit review.
- Configure forecasting operations, import certified Store history, request model retraining, and review candidate evaluation and diagnostic records.
- Perform permitted technical model operations while preserving Store approval, data-isolation, and recovery controls.

**Administrator**
- Manage delegated Store staff accounts and their permitted operational roles.
- Oversee Store Settings, Registers, Fiscal Periods, operational audit records, reports, Cashier Shifts, and operational exceptions.
- Request Forecast Generation and review eligible Store models for operational approval, replacement, or rollback within authorized boundaries.
- Create and download encrypted Database Backups and perform authorized local restoration using current-password verification and explicit replacement confirmation.

**Inventory Manager**
- Maintain Product details, barcodes, categories, pricing, packaging, and inventory-planning values.
- Import valid Product records through the permitted CSV workflow.
- Record bought goods through Direct Stock Receiving and review Receiving History and batch details.
- Record physical counts, review Stock Issues, and perform authorized inventory-control actions.
- Review Demand Forecasts, Training Eligibility, History Coverage, Forecast Exceptions, and Replenishment Recommendations without manually initiating model retraining or Forecast Generation.

**Cashier**
- Open and close authorized Cashier Shifts and process barcode-assisted customer sales with receipts.
- Review permitted transaction and receipt history and record eligible refunds through the authorized workflow.
- Submit Stock Issues for Inventory Manager review without directly approving inventory adjustments or receiving stock.
- Record authorized manual GCash services, reference numbers, fees, and service-session reconciliation. External wallet transfers are performed separately from RetailMind.

## 04 Forecasting and evaluation objectives
@Asa ibutang: Sunod sa role list, ilisi ang three objectives nga “To implement and integrate”, “To evaluate and validate”, ug “To deploy”. Source P0088 to P0090.
@Text to paste
- To integrate a Random Forest forecasting pipeline that evaluates eligible Store history, generates recorded-sales estimates, and identifies whether buying guidance is based on usable forecasts or stock rules.
- To evaluate forecasting error against a weekly Seasonal Naive baseline and assess application behavior through functional tests, technical verification, and role-based user acceptance activities.
- To prepare and verify the local Store installation using dated deployment, device, scheduling, and acceptance evidence, while distinguishing completed verification from requirements that remain pending.

[[PAGE]]
# Chapter 1 scope and conceptual framework
## 05 Scope and limitations
@Asa ibutang: 1.4 Scope and Limitations. Ilisi ang duha ka paragraph nga nagsugod “The system supports supports” ug “The Random Forest pipeline”. Source P0093 to P0094.
@Text to paste
RetailMind provides four workspaces: Super Administrator, Administrator, Inventory Manager, and Cashier. Its operational scope includes Products, batch inventory, Direct Stock Receiving, physical counts, Stock Issue review, Cashier Shifts, checkout, Receipt History, authorized refunds and corrections, manual GCash services, reports, notifications, and audit records. Recommendations guide buying without creating purchase requests or purchase orders. Accepted goods enter inventory through Direct Stock Receiving. Cost-based financial reporting depends on the completeness and accuracy of the recorded purchase costs.

The forecasting pipeline estimates recorded sales through a 28-day horizon and summarizes eligible output over 7, 14, and 28 days. The current 14-column feature set excludes Product categories, prices, promotions, local-event indicators, and stock quantities as predictors. History Coverage bands describe the amount of usable history and do not activate forecasts. Store training requires at least 140 accumulated usable days plus supported recent inputs and chronological evaluation coverage. Candidate qualification, prospective observation, operational approval, and current forecast usability are separate requirements. Stock-rule guidance remains available when usable forecasts are absent.

@Asa ibutang: Ilisi P0095, ang paragraph nga nagsugod “The project is limited”.
@Text to paste
The project is limited to Shalom Store. Forecast accuracy depends on supported, representative observations and may be affected by changes in purchasing behavior, shortages, disruptions, and incomplete evidence. Recorded sales do not directly measure demand lost during stockouts. Local operations depend on the Store PC, running application and database services, and configured local network. Internet access is needed for external services and remote monitoring. The accepted remote-access design is limited to owner read-only monitoring. Finalized sales remain historical records; authorized refunds and corrections use linked records. Physical printing, installed scheduling, and operational benefits require separate verification under the actual deployment conditions.

## 06 Conceptual framework narrative
@Asa ibutang: 1.5 Conceptual Framework, ilisi ang Input, Process, ug Output explanatory paragraphs. Source P0101 to P0103. Ilisi usab “Retail” sa Figure 1 output og “RetailMind”.
@Text to paste
The Input phase includes supported Store sales observations, Product information, stock records, account information, and Store Calendar evidence. Forecast features use Product identity, origin-relative sales history, forecast step, and target-date indicators. Stock and account records also support inventory decisions and authorized application workflows.

The Process phase includes authentication, transaction validation, stock updates, evidence classification, Training Eligibility checks, chronological model evaluation, and asynchronous Forecast Generation. Candidate qualification, prospective observation, and operational approval govern model use. The system calculates advisory buying quantities from usable forecasts or stock rules.

The Output phase provides inventory information, receipts, reports, notifications, forecast estimates where usable, and labelled buying guidance. Store personnel decide what to buy and separately record goods actually received.

[[PAGE]]
# Chapter 1 definition of terms
## 07 Revised and additional definitions
@Asa ibutang: 1.6 Definition of Terms. ILISI ang existing Demand Forecasting, Inventory Monitoring, Replenishment, ug Stock-out definitions. IDUGANG ang uban nga terms sa ubos. Ayaw duplicate sa daang list.
@Text to paste
**Demand Forecasting.** The estimation of future recorded Product sales from supported historical observations. In RetailMind, the forecast does not directly measure customer demand lost while stock was unavailable.

**Forecast Quantity.** The estimated number of a Product’s Base Units expected to sell during a stated future period. It is distinct from the quantity recommended for purchase.

**Forecast Horizon.** The future period covered by an estimate. RetailMind provides summaries for 7, 14, and 28 days from the forecast origin.

**Usable History Day.** An open Store day for which Product demand is supported by recorded evidence, including genuine zero sales. Closures, stockouts, and unknown observations do not count as additional usable days.

**History Coverage.** A description of accumulated usable history. The system uses Insufficient below 56 days, Low at 56–179 days, Medium at 180–364 days, and High at 365 or more days. These labels do not independently establish forecast accuracy or operational approval.

**Training Eligibility.** The Product-level history conditions required for Store model training: at least 140 accumulated usable days, a supported recent 28-calendar-day input window, and three supported chronological 28-day evaluation periods backed by earlier history.

**Candidate Model.** A trained model awaiting or undergoing qualification and review. Completing training does not mean the model is approved for operational forecasting.

**Prospective Observation.** Evaluation of predictions recorded before their target dates against subsequently observed Store outcomes. Historical prediction imports do not establish prospective performance.

**Replenishment Recommendation.** Advisory guidance about which Products may need additional stock and how much to buy. RetailMind uses usable forecasts where available and stock rules otherwise; the recommendation does not place an order or change stock.

**Stockout Day.** A trading day when a Product was unavailable during part or all of the day. Recorded sales remain valid, but do not reveal all demand that could have occurred with stock available.

**Inventory Monitoring.** The review of current stock information alongside available forecast estimates to support inventory decisions. RetailMind presents these data for staff review but does not automatically classify stock as overstock.

**Base Unit.** The individual physical unit used to track Product quantities, such as a piece, sachet, can, or bottle. Sales packs and receiving packages are converted according to their recorded contents.

[[PAGE]]
# Chapter 2 literature connections
## 08 Replace claims of automatic purchasing
@Asa ibutang: 2.1 Related Literature, sulod sa P0122 to P0125. TANGTANGA ang RetailMind connection sentences nga nagsugod “These advancements are directly reflected”, “These findings strongly support the design”, o “These findings are directly applicable”, lakip ilang unverified RetailMind outcome claims. Ibilin ang verified research summaries. IDUGANG ang paragraph sa ubos USA KA BESES human sa P0125; ayaw i-repeat sa matag study.
@Text to paste
These findings provide context for using forecasting as decision support in RetailMind. The implementation estimates recorded Product sales and presents advisory Replenishment Recommendations. It does not automatically purchase goods, create purchase orders, or guarantee optimal stock. Store personnel review the guidance and record actual purchases through Direct Stock Receiving. Claims about reduced stockouts, profitability, or operating efficiency require evaluation using Shalom Store outcomes.

## 09 Replace the connection to event features
@Asa ibutang: P0126, paragraph bahin sa Zavaleta-Zarate et al. ILISI gikan “This study supports the use” hangtod katapusan sa paragraph.
@Text to paste
The study provides a related example of evaluating Random Forest forecasts within an inventory-planning setting. RetailMind uses a narrower current feature set consisting of Product identity, forecast step, sales lags, rolling sales statistics, and date indicators. Local events, weather, prices, and promotions are not active predictors in this implementation. Forecasting and replenishment findings from the cited study are therefore considered related evidence rather than measured RetailMind outcomes.

## 10 Replace the model comparison rationale
@Asa ibutang: P0132 to P0133, ang duha ka paragraph nga nagsugod “Comparative evaluations” ug “Similarly, empirical retail forecasting studies”.
@Buhaton: ILISI ang duha ka paragraph. Kini nga version dili mo-claim nga RFR pirmeng molabaw sa ubang methods.
@Text to paste
Retail forecasting research motivates evaluating models that combine historical sales with date-related predictors. The M5 competition provides a public multi-item setting for studying such methods (Makridakis et al., 2022). Its findings do not establish that a particular Random Forest configuration will be the best method for every Store, Product, or forecast horizon.

RetailMind therefore compares its Random Forest Regressor with a weekly Seasonal Naive baseline using chronological evaluation. The Random Forest model shares information across Products and can represent nonlinear predictor relationships, while the baseline repeats the latest supported weekly pattern. Their relative errors are measured separately for the 7-, 14-, and 28-day totals. Model selection and operational qualification depend on retained evaluation evidence rather than the assumption that a more complex model is always better.

[[PAGE]]
# Chapter 2 related systems and synthesis
## 11 Correct the related systems descriptions
@Asa ibutang: Zoho description P0135 ug duplicate P0150. ILISI ang unang description sa first paragraph sa ubos ug TANGTANGA ang duplicate. Sa NetSuite description P0189, gamita ang ikaduhang paragraph.
@Text to paste
Zoho Inventory supports inventory records, sales and purchase workflows, stock monitoring, and reports. Zoho’s wider product ecosystem also includes analytical forecasting capabilities, while Inventory documentation describes reports that support stock-runout analysis. The availability of predictive functions depends on the specific product and integration. The comparison with RetailMind therefore concerns workflow fit, configuration, and the selected deployment rather than a blanket claim that Zoho has no forecasting capability (Zoho Corporation, n.d.-a, n.d.-b).

Oracle NetSuite Demand Planning supports demand estimates based on historical trends or projected demand and uses demand and supply plans to guide replenishment. Its documented projection options include linear regression, moving average, seasonal average, and sales forecast. It can generate proposed supply actions through its broader inventory workflow. RetailMind has a narrower single-Store scope: it displays advisory buying guidance and records actual goods through Direct Stock Receiving rather than implementing NetSuite’s purchase-order planning workflow (Oracle, n.d.).

## 12 Revised synthesis and technical architecture
@Asa ibutang: 2.3 Synthesis, ILISI P0214 to P0215. Dayon sa 2.4 Technical Background, ILISI P0225 sa last paragraph niini nga block.
@Text to paste
The reviewed systems demonstrate that inventory recording, reporting, replenishment support, and forecasting can be implemented through different product scopes and deployment models. Their suitability for Shalom Store must be assessed against verified features, configuration requirements, operating conditions, and cost evidence. The comparison does not establish that the other systems lack predictive capabilities or that RetailMind has superior accuracy.

RetailMind combines a shared Store record for sales and stock with role-specific workflows and advisory buying guidance. Its forecasting method is evaluated against a weekly Seasonal Naive baseline. Supported history, candidate qualification, prospective observation, and operational approval govern model use. Staff retain responsibility for purchasing, receiving, and inventory review.

RetailMind separates the web application from background forecasting work. PHP handles authenticated requests, inventory and sales workflows, and database interactions. A database queue records forecasting and training jobs, which a CLI Python worker processes outside interactive web requests. The application reads saved forecasts and evaluation records for display. The retained public-data experiment was executed using local Python; a Colab-compatible notebook is available, but completed Google Colab execution is not established by the retained run evidence.

[[PAGE]]
# Technical requirements tables
## 13 Replace unsupported tools and host requirements
@Asa ibutang: 2.4 Software Tools ug 3.2.1 Software Requirements, Tables 2 ug 4. ILISI ang conflicting rows o gamita kini nga consolidated table. Ibilin ang confirmed editor ug browser rows kon dili duplicate.
@Text to paste
| Component | Role in RetailMind |
| PHP and Apache | Serve the web application, validate requests, enforce workspace access, and record operational transactions. |
| MySQL compatible database | Store Products, inventory, sales, receipts, user access, forecasting jobs, and saved results. The deployed engine and version are recorded during installation verification. |
| HTML CSS and JavaScript | Provide browser-based pages, validation, navigation, tables, and responsive interactions. |
| Python | Process queued forecasting and training jobs and run the reproducible experiment. |
| scikit-learn | Implement Random Forest regression, encoding, and the shared model pipeline. |
| pandas and NumPy | Prepare daily observations, construct features, and calculate experimental results. |
| Local Python experiment | Execute the retained public M5 training and comparison runs using recorded package versions and source provenance. |
| Colab compatible notebook | Provide an alternative notebook execution format; its availability does not establish a completed Colab run. |
| In app and email notifications | Display operational notices and deliver configured email through supported transports. External delivery requires valid configuration and network access. |
| Git and source editor | Maintain project revisions and support development. |

@Asa ibutang: 3.2.1 Hardware Requirements, Table 3. ILISI ang Xeon/server ug mandatory broadband rows niini nga deployment table. Actual specs sa record block 52 pun-on gikan sa tested installation.
@Text to paste
| Device or service | Requirement and verification scope |
| Store PC host | Run the application server, database, and configured forecasting processes. Record the actual CPU, RAM, storage, OS, and service versions used for acceptance. |
| Desktop browser client | Reach the Store host and complete authorized tasks in a tested supported browser. |
| Smartphone or tablet | Use responsive pages through the configured Store connection. Physical printing requires verification of the selected printing path. |
| Barcode scanner | Enter supported Product codes in the tested barcode fields. Record the actual connected device. |
| Receipt or label printer | Print through the configured browser or Store PC printing workflow. Compatibility is limited to tested hardware and settings. |
| Local network | Connect staff devices to the running Store host. |
| Internet connection | Support external services and authorized remote monitoring; an internet outage is distinct from loss of access to the local Store host. |

[[PAGE]]
# Chapter 3 functional requirements
## 14 Replace the functional requirements narrative
@Asa ibutang: 3.2.2 Functional Requirements. ILISI P0286 to P0293 sa sunod nga paragraphs.
@Text to paste
RetailMind shall provide four workspaces: Super Administrator, Administrator, Inventory Manager, and Cashier. The application shall authenticate users and enforce the capabilities of the active workspace. The Super Administrator shall manage technical platform governance and forecasting configuration. The Administrator shall manage permitted Store staff access, Store Settings, Registers, Fiscal Periods, operational oversight, and eligible model approval.

The Inventory Manager shall maintain Products, categories, barcodes, pricing, packaging, and inventory-planning values. The workspace shall support valid Product CSV imports, Direct Stock Receiving, Receiving History, batch information, physical counts, and Stock Issue review. Stock quantities shall change through authorized recorded transactions rather than an untraceable overwrite of history.

The Cashier shall process sales through an authorized Cashier Shift, issue and reprint permitted receipts, access transaction history, submit Stock Issues, and record authorized manual GCash services. Finalized sales shall remain available as historical records. Eligible refunds and corrections shall be stored separately and linked to the original transaction. Stock Issue submission shall not itself approve or apply an inventory deduction.

The forecasting pipeline shall use a Random Forest Regressor to estimate recorded sales through a 28-day horizon and provide 7-, 14-, and 28-day summaries where usable output exists. Training Eligibility shall require at least 140 accumulated usable days, supported recent inputs, and the required chronological evaluation coverage. History Coverage bands shall remain separate from candidate qualification, prospective observation, operational approval, and forecast usability.

The Administrator or Super Administrator may request Forecast Generation. Only the Super Administrator may manually request model retraining or configure technical forecasting controls. The Inventory Manager shall review available forecasts, readiness information, exceptions, and buying guidance without initiating restricted model operations.

Replenishment Recommendations shall identify whether the suggested buying quantity is based on a usable Demand Forecast or stock rules. Expected sales and suggested purchase quantities shall remain distinct. Recommendations shall not create purchase requests, purchase orders, or stock movements. Staff shall record goods actually received through Direct Stock Receiving.

The system shall provide permitted operational reports and retain audit records for relevant account, stock, sales, review, and forecasting actions. In-app and email notifications shall operate according to the implemented conditions and selected delivery configuration. Closed or locked Fiscal Period restrictions shall preserve the applicable protected records.

[[PAGE]]
# Chapter 3 nonfunctional requirements
## 15 Security backup and connectivity
@Asa ibutang: 3.2.3 Non-Functional Requirements. ILISI P0295 to P0298.
@Text to paste
RetailMind shall provide readable, responsive pages for authorized Store tasks. Authentication shall use password hashes, authenticated sessions, and role-based access checks. Relevant operational actions shall be traceable through audit records. User acceptance observations shall supplement, rather than replace, technical security and reliability checks.

The application shall handle inventory, sales, and forecasting information according to the active workspace. Forecasts shall remain decision-support estimates. Missing or unusable forecasts shall not be interpreted as zero expected sales. Background forecasting work shall be processed outside interactive web requests, and failures shall provide staff-safe status information while retaining technical diagnostic evidence.

Routine Database Backups shall be encrypted packages containing the required Store records and uploaded files. Generated server-held keys shall encrypt new packages, and a separately retained recovery-key copy shall support recovery or migration. The operator’s login password shall authorize restoration and key-management actions; it shall not serve as the package encryption secret. Existing legacy passphrase packages shall retain their applicable compatibility procedure.

An authorized Administrator or Super Administrator may perform local Database Restore using current-password verification and explicit replacement confirmation. Restoration shall validate the package and destination, resolve identity conflicts, create a verified destination safety copy, preserve the required privileged-access protections, and pause Store writes. Verification and explicit Store Reopening shall follow replacement. Database Restore shall replace the selected Store snapshot rather than selectively repair individual receipts.

Local operation shall depend on the Store PC, running application and database services, and configured local connectivity. Internet access shall support external services and the accepted owner-only read-only remote monitoring design. Remote access shall not authorize checkout, receiving, backup restoration, or unrestricted Store administration. Compatibility, physical printing, response time, data capacity, and unattended scheduling shall be verified using the actual installation and test conditions.

## 16 Architecture description
@Asa ibutang: 3.3.1 System Architecture, ubos sa Figure 8. ILISI P0315 to P0316.
@Text to paste
RetailMind uses browser-based presentation, application, and data layers. The Store PC hosts the PHP application and central MySQL-compatible database. The application authenticates users, enforces workspace permissions, validates requests, and records inventory, receiving, sales, account, and audit information.

PHP queues forecasting work as database jobs. A CLI Python worker processes eligible training and generation jobs and saves their outputs. The web application reads those persisted records for display. Candidate training, evaluation, observation, and operational approval are distinct from daily Forecast Generation. The architecture does not require request-time model training or a separate Flask prediction server for the current pipeline.

[[PAGE]]
# Chapter 3 diagram descriptions
## 17 Context and data flow descriptions
@Asa ibutang: Ubos sa Figure 9 Context Diagram, ILISI P0331. Ang old three-role image kinahanglan i-redraw nga four roles.
@Text to paste
The context diagram represents RetailMind as a single-Store system used through four workspaces. The Super Administrator provides technical platform and forecasting controls. The Administrator manages Store oversight and eligible operational reviews. The Inventory Manager maintains Products, records received goods, reviews inventory controls, and consults buying guidance. The Cashier records authorized sales, shifts, receipts, manual GCash services, and Stock Issues. The system returns permitted records, status information, reports, and advisory forecasts or stock-rule guidance. Purchasing remains a human decision outside automated inventory execution.

@Asa ibutang: Ubos sa Figure 10 Data Flow Diagram, ILISI P0347. TANGTANGA sa image ang Replenishment Requests ug Cashier Receive Stock flows.
@Text to paste
The data flow diagram separates operational transactions from background forecasting. Authenticated users submit actions within their workspace permissions. Sales and receiving update the relevant stock and transaction records, while receipts and audit data preserve the associated history. Stock Issues and physical counts follow their review workflows. Forecasting reads supported Store evidence, evaluates Training Eligibility, and queues background work. Candidate evaluation and approval govern model use, and saved current forecasts support advisory buying calculations. Recommendation review does not create an order or stock movement; Direct Stock Receiving records goods actually obtained.

## 18 Use case description
@Asa ibutang: Ubos sa Figure 11 Use Case Diagram, ILISI P0352. I-update ang image aron motakdo niini nga authority.
@Text to paste
The use case diagram distinguishes technical governance, Store oversight, inventory work, and cashier operations. The Super Administrator manages forecasting configuration, controlled history import, manual retraining, and technical review. The Administrator manages permitted Store staff, settings, registers, Fiscal Periods, operational model review, and backups. The Inventory Manager maintains Products, receives stock, reviews counts and Stock Issues, and reads forecasts and recommendations. The Cashier performs authorized shift, sales, receipt, refund, manual GCash, and Stock Issue tasks. Capability checks apply to the active workspace, including accounts that have more than one assigned role.

## 19 ERD and dictionary introduction
@Asa ibutang: Ubos sa Figure 12 Entity Relationship Diagram, ILISI P0359. Ibutang usab kini nga scope paragraph sa dili pa ang data dictionary.
@Text to paste
The Entity Relationship Diagram presents the selected implemented entities that support account access, Products, inventory, receiving, sales, receipts, reviews, and forecasting. Users and role assignments govern permitted workspaces. Product-linked stock, batch, and receiving records preserve inventory history, while sales and sale items connect checkout to the relevant receipts and refunds. Forecast jobs, model evaluations, and daily forecast rows preserve generation and evaluation evidence. The accompanying dictionary lists selected core fields from the implemented schema; it is a focused documentation view rather than a complete listing of every database field. Retired purchase-request workflows are excluded from the operational model.

[[PAGE]]
# Chapter 3 model training method
## 20 Replace the training pipeline introduction
@Asa ibutang: 3.4.2 Machine Learning Training Pipeline, ILISI P0370 to P0371 ug Table 6 feature descriptions sa sunod nga text ug table.
@Text to paste
The proponents evaluated the Random Forest Regressor using a reproducible local Python experiment before Store model activation. The retained benchmark uses 50 selected Products from the M5 CA_1 FOODS subset. Its initial training window covers 1 March 2014 to 28 February 2016, comprising 730 daily dates. The public-data experiment is isolated from Shalom Store transactions, stock, and operational model approval. A Colab-compatible notebook is available, but the retained results establish local execution rather than a verified Google Colab run.

The shared feature contract contains 14 predictor columns before encoding. Product identity is one-hot encoded, so the final transformed matrix can contain additional columns. The Random Forest recipe uses 15 trees, maximum depth 8, random seed 42, and feature version 2.0.0. The model estimates daily recorded sales for forecast steps 1 to 28; the daily predictions are aggregated into the 7-, 14-, and 28-day summaries.

| Feature group | Predictor columns | Count | Meaning |
| Identity and forecast step | product_id, horizon_day | 2 | Identify the Product and future day being predicted. |
| Sales lags | lag_1, lag_7, lag_14, lag_28 | 4 | Represent supported recent observations anchored to the forecast origin and first forecast day. |
| Rolling statistics | rolling_mean_7, rolling_std_7, rolling_mean_28, rolling_std_28 | 4 | Summarize recent sales level and variation. |
| Date indicators | day_of_week, day_of_month, month, is_weekend | 4 | Represent the target date without adding a dedicated payday, event, or promotion variable. |

For a normal observed cutoff, lag_1 uses the cutoff observation; lag_7, lag_14, and lag_28 use cutoff minus 6, 13, and 27 calendar days. Supported closure handling preserves the distinction between closed dates and observed zero sales. Unsupported stockout or unknown gaps are not silently filled as genuine zero demand. Prices, promotions, category attributes, current stock, and local-event variables are not active predictors in this feature contract.

The retained run metadata provide the dataset selection, source provenance, script revision, split definitions, model settings, and package versions. Installed notebook package targets are not treated as evidence of the versions used for a particular completed experiment.

[[PAGE]]
# Chapter 3 chronological evaluation
## 21 Replace the chronological split explanation
@Asa ibutang: P0375, paragraph nga nagsugod “To prevent temporal data leakage”. ILISI ang tibuok paragraph ug idugang ang fold table.
@Text to paste
The experiment preserves chronological order. At each forecast origin, the Random Forest is fitted using only targets available before that origin. Earlier completed blocks may enter the training data for later folds; one unchanged 730-day fit is not used for every comparison. The first two 28-day blocks support validation and selection of the secondary sale-day threshold, which is 0.5 in the retained experiment. The final 28-day block supplies the reported held-out development comparison.

| Fold | Forecast origin | Latest training label | Evaluation dates |
| 1 validation | 28 February 2016 | 26 February 2016 | 29 February to 27 March 2016 |
| 2 validation | 27 March 2016 | 25 March 2016 | 28 March to 24 April 2016 |
| 3 test | 24 April 2016 | 22 April 2016 | 25 April to 22 May 2016 |

The weekly Seasonal Naive baseline repeats the latest supported weekly sales pattern over the forecast horizon. Both methods are compared using the same Product population and evaluation dates. The final test window was already inspected during model development and expansion. Its results therefore remain a held-out development comparison rather than a fresh independent assessment of the expanded model.

## 22 Replace the forecast accuracy testing description
@Asa ibutang: 3.4.2.2 Forecast Accuracy Testing, ILISI P0380. Gamita kini bisan mausab ang subsection number human sa renumbering.
@Text to paste
Forecast accuracy is evaluated using Mean Absolute Error, Root Mean Squared Error, and Weighted Absolute Percentage Error for per-Product 7-, 14-, and 28-day unit totals. Lower values indicate smaller quantity errors. A separate any-sale-day classification analysis reports precision, recall, and F1 at the validation-selected threshold. Classification measures do not replace quantity errors or establish stockout prevention.

For Store model training, a Product requires at least 140 accumulated usable days, a supported recent 28-calendar-day input window, and three supported chronological 28-day evaluation periods backed by earlier history. History Coverage bands remain separate descriptions of usable history. Candidate qualification requires complete metrics, configured per-horizon maximum WAPE limits, and strict improvement over the weekly baseline at all three horizons. Operational use additionally requires completed prospective observation, local Administrator approval, compatible model evidence, and current usable forecast output.

[[PAGE]]
# Chapter 3 evaluation activities and test plan
## 23 Replace forecast evaluation activities
@Asa ibutang: Table 7 Forecast Accuracy Evaluation Activities and Expected Outcomes, review table T010. ILISI ang tibuok table. Kini usa ka plan, dili actual result.
@Text to paste
| Activity | Verification | Expected output |
| Prepare observations and features | Validate original dates, availability cutoffs, and the 14-column feature contract. | Supported training and prediction rows with retained provenance. |
| Fit and compare models | Use chronological folds and the weekly Seasonal Naive baseline. | Model and baseline predictions for matching Products and dates. |
| Measure quantity error | Calculate per-Product horizon MAE, RMSE, and aggregate WAPE. | Separate 7-, 14-, and 28-day quantity scores with population and units. |
| Analyze sale days | Select threshold on validation and evaluate the retained test cases. | Precision, recall, and F1 reported as secondary classification measures. |
| Evaluate a Store candidate | Apply configured limits and baseline comparisons at all horizons. | Passed, failed, or blocked qualification with reasons. |
| Verify observation and approval | Check prospective target evidence and operational review. | Historical imports do not fabricate observation progress or approval. |

## 24 Replace the Alpha Testing plan
@Asa ibutang: Chapter 3 Alpha Testing Table, review T011. ILISI ang old three-role plan; wala kini fabricated durations.
@Text to paste
| Workspace or area | Test activity | Expected behavior |
| Super Administrator | Import certified Store history and request candidate training. | Only authorized actions are accepted; candidate results remain separate from approval. |
| Administrator | Manage delegated staff and Store settings. | Allowed changes are saved and restricted role assignments are blocked. |
| Administrator | Review a model and request generation. | Approval gates and generation status are enforced and visible. |
| Administrator backup | Test local backup and authorized restore in an isolated environment. | Password verification, replacement checks, safety copy, and reopening controls apply. |
| Inventory Manager | Maintain Products and validate CSV input. | Valid entries are accepted; invalid entries do not corrupt records. |
| Inventory Manager | Receive goods and review batches. | Accepted quantities update stock with traceable receiving records. |
| Inventory Manager | Review counts and Stock Issues. | Stock changes only through the applicable authorized decision. |
| Inventory Manager | Review forecast and stock-rule guidance. | Expected sales, suggested buying quantity, basis, and unavailable state remain distinct. |
| Cashier | Complete and retry an authorized checkout. | Correct stock and receipt are recorded without duplicating a successful sale. |
| Cashier | Review receipts, refunds, shifts, and manual GCash. | Authorized records and reconciliation are preserved. |
| Access control | Attempt receiving, generation, or retraining from a restricted workspace. | Unauthorized actions are blocked. |

[[PAGE]]
# Chapter 3 user acceptance instrument
## 25 Replace the Beta Testing introduction
@Asa ibutang: 3.4.2.2 Beta Testing, source P0394. ILISI ang paragraph, dayon i-renumber ang duplicate subsection headings.
@Text to paste
User acceptance testing covers tasks performed or directly observed by the Administrator, Inventory Manager, and Cashier. Each respondent completes the questionnaire for the workspace tested. The instrument uses the eight product-quality characteristics of ISO/IEC 25010:2011 as its stated historical study framework: functional suitability, performance efficiency, compatibility, usability, reliability, security, maintainability, and portability. This does not claim conformity certification or use of the revised 2023 product-quality model.

The questionnaires collect role-based acceptance observations. Technical testing separately addresses permission enforcement, data integrity, forecasting gates, recovery behavior, installed scheduling, and device compatibility. Super Administrator functions are verified through a separate technical checklist. Respondents are not required to rate actions they did not perform or observe.

## 26 Replace the instructions on all three forms
@Asa ibutang: Admin, Inventory Manager, ug Cashier questionnaire instructions, P0403, P0415, P0426. ILISI ang instruction ug idugang og N/A column sa matag form. Tangtanga ang stray sentence nga nagsugod og period sa P0427.
@Text to paste
Rate only the tasks you performed or directly observed in the tested workspace. Select one response for each statement: 5 = Strongly agree; 4 = Agree; 3 = Neutral; 2 = Disagree; 1 = Strongly disagree. Select N/A = Not tested or not applicable when the task was not performed or observed. N/A and missing responses are excluded from the valid-response count. For hardware, correction, and update statements, rate only the scenario actually tested.

## 27 Correct Administrator Security item 5
@Asa ibutang: Administrator questionnaire, Security item 5, nga nag-ingon “restore a database or retrain a forecasting model”. ILISI sa first statement. IDUGANG ang second statement isip separate applicable restore item, dili i-combine sa retraining.
@Text to paste
The system blocks technical model retraining from the Administrator workspace.

For the assigned isolated local restore scenario, the system requires my current login password, package review, and explicit replacement confirmation before replacing the Store snapshot.

@Editor note: Ang restore item dapat N/A kon wala gi-test. Ayaw i-require ang live destructive restore aron lang matubag ang form. Ibilin ang uban nga already-correct role questions.

[[PAGE]]
# Chapter 3 data analysis
## 28 Replace the scale and analysis text
@Asa ibutang: 3.4.3 Data Analysis Plan, P0431, ug Tables T015 to T016. ILISI ang old Excellent to Poor scale ug unequal interpretation ranges niini. Kini proposed consistent scheme; ipa-align sa adviser-approved instrument sa dili pa gamiton.
@Text to paste
Responses are analyzed using the same five-point agreement scale stated in the questionnaires. Each item mean is the sum of its valid numerical ratings divided by the number of valid responses. Not tested, not applicable, and missing responses are excluded rather than assigned a neutral or zero rating. An item with no valid responses is reported as Not evaluated.

| Rating | Response label |
| 5 | Strongly agree |
| 4 | Agree |
| 3 | Neutral |
| 2 | Disagree |
| 1 | Strongly disagree |

For descriptive summaries, the following equal-width interpretation scheme is applied to the unrounded mean. Results are displayed to two decimal places, while interval assignment uses the underlying value. These summaries describe respondent agreement and do not certify technical system quality.

| Unrounded mean | Interpretation |
| 4.20 < mean <= 5.00 | Strongly agree |
| 3.40 < mean <= 4.20 | Agree |
| 2.60 < mean <= 3.40 | Neutral |
| 1.80 < mean <= 2.60 | Disagree |
| 1.00 <= mean <= 1.80 | Strongly disagree |

Category summaries are calculated as the arithmetic mean of the evaluated item means within the same quality characteristic and role, giving each evaluated item equal weight. The number of evaluated items and valid responses per item are reported. An overall role summary, when used, is the arithmetic mean of that role’s evaluated category means. Different role populations are reported separately rather than treated as interchangeable samples.

User comments are grouped by the task or issue described and linked to the corresponding correction or retest where available. Questionnaire results are interpreted alongside observed task results, technical checks, and deployment evidence. Acceptance ratings alone do not establish forecasting accuracy, security strength, measured response time, or operational stockout reduction.

[[PAGE]]
# Prototype forecasting captions
## 29 Demand Forecast and Replenishment captions
@Asa ibutang: 3.4.4 Description of the Prototype. ILISI Figure 22 caption description P0469 sa first paragraph. ILISI Figure 22A description P0481 sa second paragraph. Ayaw i-claim nga implemented na ang proposed new badges.
@Text to paste
The Demand Forecast page presents saved forecast information and the status of the underlying generation and model records. At initial Store deployment, expected sales can remain unavailable because supported history, model qualification, observation, approval, or current usable output is incomplete. Training requires at least 140 accumulated usable days per Product plus the recent-input and chronological-evaluation checks. Reaching that count does not automatically generate or approve a forecast. Stock-rule buying guidance remains separate from unavailable AI estimates.

The Replenishment Recommendations page distinguishes current stock, stock-rule guidance, and expected sales where usable forecast output exists. Stock-rule quantities can be present while AI expected sales are unavailable. Forecast Quantity estimates future recorded sales, whereas the suggested buying quantity accounts for the applicable stock and planning rules. Reviewing the page does not place an order or change stock.

## 30 Exceptions and Data Readiness captions
@Asa ibutang: Figure 22B ug Figure 22C descriptions, P0484 ug P0487. ILISI gamit ang first ug second paragraphs sa ubos.
@Text to paste
Forecast Exceptions lists Products without usable operational forecasts and supports review of the exclusion reason and next action. Possible blockers include insufficient supported history, missing or unapproved models, observation-only status, and stale, incomplete, incompatible, suspended, or superseded output. The page is not defined solely by a per-Product 35% WAPE threshold or a 7-day baseline comparison.

Forecast Data Readiness presents evidence relevant to Product history and training. The 140 usable day requirement, supported recent input window, and chronological evaluation coverage determine Training Eligibility. The separate history bands describe usable history as Insufficient below 56 days, Low at 56–179, Medium at 180–364, and High at 365 or more. These bands do not independently establish high confidence, approval, or available forecasts.

## 31 Product and receiving captions
@Asa ibutang: Figure 18 Products description P0457 ug Figure 21 Direct Stock Receiving description P0466. ILISI ang applicable descriptions.
@Text to paste
The Product catalog presents identifying details, recorded cost and selling prices, packaging, stock, planning values, and status. The displayed unit price difference is a unit margin estimate; it is distinct from realized Gross Profit for a reporting period and depends on the recorded cost.

Direct Stock Receiving allows the Inventory Manager to record bought goods using Product lookup or barcode entry, accepted quantities, package details, costs, and applicable batch information. It does not require an application-generated purchase request or purchase order.

[[PAGE]]
# Proposed forecasting interface text
## 32 Insert the agreed interface clarification proposal
@Asa ibutang: Human sa four forecasting prototype descriptions sa 3.4.4, IDUGANG isip “Proposed Forecasting Interface Clarifications”. Kini design proposal samtang pending pa ang UI implementation; ayaw ibutang sa Chapter 4 isip completed result.
@Text to paste
The proposed interface clarifications distinguish history collection from model and generation failures. The Demand Forecast page will use state-specific explanations, identify Random Forest as the method, and display an active model version only when an approved model exists. The Data Readiness page will make the 140 usable day training requirement and remaining checks primary, with History Coverage bands as secondary information. Replenishment Recommendations will show the actual suggested buying quantity with its matching basis, separately from expected sales. Forecast Exceptions will explain why a Product has no usable forecast and identify the next useful action.

The progress display will measure accumulated usable days rather than days with positive sales or days since installation. A completed day counter will not hide a failed recent-input or evaluation check. The additional benchmark-preview button on the live Store page is deferred; existing explicitly isolated historical Demo views do not constitute operational Store forecasts.

| Interface location | Proposed wording |
| Demand Forecast subtitle | Estimated sales for the next 7, 14, and 28 days, based on supported Store history. |
| Cold-start heading | No Store forecasts yet |
| Cold-start explanation | RetailMind is collecting supported history. Training requires at least 140 usable days per Product plus recent-input and evaluation checks. Qualification, observation, and approval follow separately. Review Replenishment Recommendations for stock-rule buying guidance. |
| Training progress | 12 / 140 usable days |
| History legend label | History coverage |
| Recommendation headings | Current Stock; Suggested Buy Qty; Expected Sales with the stated forecast period |
| Buying basis | Basis: Stock Rule or Basis: Demand Forecast, matched to the displayed buying quantity |
| Exceptions subtitle | Products without usable forecasts. Review the reason and next step. |

When forecasts are absent, the displayed reason will reflect the actual blocker rather than always stating that more history is needed. Supported zero-sales days can add usable history. Closures, stockouts, and unknown observations do not add to the total. None of these messages will promise activation after a fixed calendar countdown or treat unavailable estimates as zero demand.

[[PAGE]]
# Chapter 4 operational descriptions
## 33 Replace the module overview
@Asa ibutang: Chapter 4 opening overview P0507 ug Admin description P0509. ILISI sa first paragraph. Sa P0530, P0543, P0551 gamita ang second paragraph aron klaro ang Administrator versus Super Administrator.
@Text to paste
RetailMind provides four workspaces with separate responsibilities. The Super Administrator handles technical platform and forecasting governance. The Administrator manages permitted Store oversight, staff access, settings, reviews, and backups. The Inventory Manager maintains Products, records Direct Stock Receiving, and reviews inventory controls and buying guidance. The Cashier performs authorized shift, sales, receipt, refund, manual GCash, and Stock Issue activities. Public benchmark results, isolated application verification, and genuine Store acceptance are reported as different forms of evidence.

The Administrator manages permitted Store Settings, Fiscal Periods, staff access, and Operational Audit review. Technical platform configuration and manual model retraining belong to the Super Administrator workspace. Access depends on the active workspace and the action’s authorization requirements rather than the informal label “system admin.”

## 34 Replace the initial forecast explanation
@Asa ibutang: 4.1.7 Demand Forecasting, ILISI P0579 nga adunay “minimum 56-day history threshold”.
@Text to paste
Unavailable expected sales at initial Store deployment do not, by themselves, indicate a forecasting failure. RetailMind first requires supported Store evidence and model authorization. Each Product must meet the 140 accumulated usable day policy, supported recent inputs, and chronological evaluation coverage before training eligibility is satisfied. Training success creates a candidate; qualification, prospective observation, operational approval, and usable current output remain separate requirements. History Coverage bands are evidence descriptions rather than automatic activation thresholds. During this period, stock-rule buying guidance uses recorded stock and planning values without inventing AI forecasts.

## 35 Replace the obsolete replenishment and receiving description
@Asa ibutang: 4.1.7 opening P0575 ug 4.1.10 receiving P0596, kon i-consolidate ninyo ang description. TANGTANGA ang old references sa creating or tracking Replenishment Requests sa testing tables.
@Text to paste
The Inventory Manager reviews available 7-, 14-, and 28-day sales estimates and advisory buying quantities. A usable forecast can support the purchasing calculation; stock rules provide guidance when forecast-backed calculation is unavailable. Forecast estimates and buying quantities remain different measures. The recommendation does not create a purchase request or purchase order. Bought goods are recorded through Direct Stock Receiving, which preserves accepted quantities, costs, receiving groups, and applicable batch and packaging details.

[[PAGE]]
# Chapter 4 testing results format
## 36 Replace the Alpha Testing Result introduction and table
@Asa ibutang: 4.2.1 Alpha Testing Result, P0610 ug Table T018. Gamita kini nga intro ug table imbis nga Expected Outcome plus Duration lamang. Pun-i ang actual fields human sa tests.
@Text to paste
Alpha testing results distinguish the expected behavior from the observed outcome for each executed scenario. Each record identifies the build, test environment, date, tester, input, outcome, and retained evidence. A planned test or expected outcome is not counted as a passed test. Tests without an executed scenario and supporting record are reported as Not run. Defects are linked to their correction and retest rather than removed from the result history.

Test build: [FILL IN BUILD OR COMMIT]. Test environment: [FILL IN HOST AND DATABASE]. Test period: [FILL IN DATES].

| Test ID | Scenario and expected behavior | Observed outcome | Status and evidence |
| AT01 | Restricted workspace attempts an unauthorized action; access is blocked. | [FILL IN] | [FILL IN] |
| AT02 | Inventory Manager receives accepted goods; stock and receiving history agree. | [FILL IN] | [FILL IN] |
| AT03 | Cashier retries a completed checkout; no duplicate sale is created. | [FILL IN] | [FILL IN] |
| AT04 | A pending Stock Issue is submitted; no approved deduction occurs before review. | [FILL IN] | [FILL IN] |
| AT05 | A Product has 140 usable days but a failed window; training eligibility stays blocked. | [FILL IN] | [FILL IN] |
| AT06 | A candidate fails quality checks; training success does not approve it. | [FILL IN] | [FILL IN] |
| AT07 | Historical predictions are imported; prospective observation progress is not fabricated. | [FILL IN] | [FILL IN] |
| AT08 | Buying guidance is reviewed; quantity, basis, and forecast period remain distinct. | [FILL IN] | [FILL IN] |
| AT09 | An isolated authorized restore is performed; replacement and recovery protections apply. | [FILL IN] | [FILL IN] |

@Editor note: Kon wala pay actual Alpha records, ayaw pun-i og “Passed” gikan lang sa expected outcome. Existing retained technical verification mahimong i-cite sa sakto nga build ug environment; dili kini automatic Store UAT.

[[PAGE]]
# Chapter 4 user acceptance results
## 37 Add the Store UAT result summary
@Asa ibutang: Chapter 4, IDUGANG isip separate User Acceptance Testing Results subsection. Ang manuscript adunay blank questionnaires apan kulang ang actual respondent-result summary.
@Text to paste
User acceptance testing was conducted on [FILL IN DATES] with [FILL IN NUMBER] Administrators, [FILL IN NUMBER] Inventory Managers, and [FILL IN NUMBER] Cashiers. Respondents evaluated tasks performed or directly observed in their assigned workspace. The evaluated build was [FILL IN BUILD], using [FILL IN DEVICES AND ENVIRONMENT]. N/A and missing responses were excluded from valid-response counts. The role and category results are reported with the number of evaluated items and valid responses. Reported issues and retest outcomes are summarized in [FILL IN EVIDENCE REFERENCE].

| Characteristic | Administrator mean | Inventory Manager mean | Cashier mean |
| Functional suitability | [FILL IN] | [FILL IN] | [FILL IN] |
| Performance efficiency | [FILL IN] | [FILL IN] | [FILL IN] |
| Compatibility | [FILL IN] | [FILL IN] | [FILL IN] |
| Usability | [FILL IN] | [FILL IN] | [FILL IN] |
| Reliability | [FILL IN] | [FILL IN] | [FILL IN] |
| Security | [FILL IN] | [FILL IN] | [FILL IN] |
| Maintainability | [FILL IN] | [FILL IN] | [FILL IN] |
| Portability | [FILL IN] | [FILL IN] | [FILL IN] |

The item-level valid-response counts and excluded N/A or missing responses are provided in [FILL IN RESPONSE RECORD REFERENCE]. Category means follow the stated analysis method and are not interpreted as certification of technical quality. Characteristics with no valid evidence are reported as Not evaluated.

The principal user-reported issues were [FILL IN VERIFIED ISSUES]. Corrections and retests were recorded under [FILL IN REFERENCES]. The acceptance finding is limited to the tested roles, tasks, build, and environment; it does not establish untested installation behavior or predictive benefit on Store outcomes.

[[PAGE]]
# Chapter 4 quantity forecasting results
## 38 Replace the benchmark results introduction and table
@Asa ibutang: Forecast Accuracy Testing Results heading P0615, intro P0616, ug Table T019 nga currently Table 12. Heading gamita “Historical Forecast Accuracy Results”. ILISI ang intro ug table, apil note P0621 to P0623.
@Text to paste
The retained local Python experiment evaluated 50 selected M5 CA_1 FOODS Products. Initial training covered 1 March 2014 to 28 February 2016. The model was refitted chronologically, and the reported final development holdout covers 25 April to 22 May 2016. Random Forest predictions were compared with a weekly Seasonal Naive baseline for the same Products and dates. These public-data scores do not establish predictive accuracy at Shalom Store.

| Horizon | Actual units | RF MAE | Baseline MAE | RF RMSE | Baseline RMSE |
| 7 days | 689 | 5.926 | 5.220 | 11.921 | 8.858 |
| 14 days | 1,538 | 9.977 | 11.600 | 16.414 | 20.131 |
| 28 days | 3,195 | 18.936 | 23.340 | 32.244 | 41.846 |

| Horizon | Random Forest WAPE | Baseline WAPE | Lower error |
| 7 days | 43.00% | 37.88% | Seasonal Naive |
| 14 days | 32.44% | 37.71% | Random Forest |
| 28 days | 29.63% | 36.53% | Random Forest |

MAE and RMSE measure errors in per-Product horizon unit totals across the 50 Products. They are not daily error measures. WAPE is aggregate absolute error divided by total actual units, displayed as a percentage. It is an error measure rather than an accuracy percentage. The reported metrics use fractional experiment predictions; independently rounded daily display values should not be used to reproduce this table.

The weekly baseline recorded lower error at 7 days, while Random Forest recorded lower error at 14 and 28 days. The 28-day WAPE was 29.63% for Random Forest and 36.53% for the baseline. This result supports a limited comparative finding for the selected population and period. It does not establish that Random Forest is superior for every Product, that 7-day forecasts should be removed from the system, or that the model is approved for Store use. The final window had already been inspected during development and expansion, which limits its independence as a final assessment.

[[PAGE]]
# Chapter 4 classification and product variation
## 39 Replace classification interpretation and product analysis
@Asa ibutang: Table T020 ug P0628, dayon P0630. ILISI ang table ug interpretation sa text sa ubos. Ang F1 values preserved; ang stockout-causation claim gitangtang.
@Text to paste
The secondary any-sale-day classification analysis uses a threshold of 0.5 selected through validation. It evaluates 1,400 Product-day cases in the retained test block. A positive actual case means that recorded sales occurred; the task does not establish whether the Product was continuously available on the shelf.

| Method | TP | FP | FN | TN | Precision | Recall | F1 |
| Random Forest | 760 | 444 | 56 | 140 | 63.12% | 93.14% | 0.7525 |
| Weekly baseline | 544 | 228 | 272 | 356 | 70.47% | 66.67% | 0.6851 |
| Always sale | 816 | 584 | 0 | 0 | 58.29% | 100.00% | 0.7365 |

Random Forest recorded 56 false negatives, compared with 272 for the weekly baseline. A false negative means that the classification predicted no sale while a sale was recorded. It does not directly establish a stockout, lost demand, or an inventory shortage. The always-sale comparison also demonstrates that positive-class prevalence can produce a relatively high F1 score. Classification is therefore reported separately from quantity forecasting errors and purchasing usefulness.

At the 28-day horizon, Random Forest improved absolute error over the baseline for 28 of 50 Products and did not improve it for the remaining 22. This count describes the selected final-window comparison. It does not prove that the 22 Products were erratic, exceeded 35% validation WAPE, or failed the 7-day comparison. Aggregate improvement can conceal weaker Product-level estimates and supports continued human review.

Forecast Exceptions has a different operational purpose: it lists Products without usable current forecasts and explains the relevant exclusion. Quality thresholds belong to candidate evaluation and do not universally define membership in that page. Missing or unapproved models, incomplete or stale forecasts, observation-only output, suspension, incompatible artifacts, and insufficient supported inputs can all affect usability.

[[PAGE]]
# Chapter 4 candidate qualification and limitations
## 40 Replace the safeguards discussion
@Asa ibutang: P0624 ug P0631 to P0632. ILISI ang old 35 percent exceptions ug activation safeguards explanation. IDUGANG ang candidate qualification table isip separate result, dili ipuli sa historical benchmark table.
@Text to paste
RetailMind distinguishes historical model comparison from operational candidate qualification. Store Training Eligibility requires at least 140 accumulated usable days, a supported recent 28-calendar-day input window, and three supported chronological 28-day evaluation periods backed by earlier training history. History Coverage bands describe the available evidence and do not automatically qualify or activate a model.

The retained isolated training run on 3 November 2026, rechecked on 4 November, completed successfully as a training job but produced a failed candidate. Qualification used rounded quantities pooled across three chronological evaluation blocks, rather than the fractional final-holdout comparison reported earlier.

| Horizon | Candidate WAPE | Baseline WAPE | Configured maximum | Result |
| 7 days | 44.33% | 39.81% | 35% | Failed |
| 14 days | 40.86% | 41.11% | 35% | Failed |
| 28 days | 40.64% | 41.97% | 35% | Failed |

All three candidate errors exceeded their configured maximum, and the 7-day result also failed to improve on the weekly baseline. The candidate remained unapproved. Historical prediction imports contributed zero of the required 28 prospective observation days. The historical benchmark table and candidate qualification table measure different aggregations for different purposes and must not be combined into a single accuracy claim.

Operational use requires successful qualification, completed prospective observation, local Administrator approval, compatible Store model evidence, and usable current forecast output. An explicit technical rejection blocks activation; the absence of a separately recorded Super Administrator acceptance is not, by itself, a universal activation requirement. The Inventory Manager consults the resulting guidance without bypassing these controls.

Recording stock, prices, promotions, and calendar information does not mean that all of those values are active model predictors. The current model estimates recorded sales and cannot directly recover all demand lost during stockouts. Public M5 observations and models remain isolated from Shalom Store operations. The retained target-acceptance evidence dated 3 November reports unattended installation as not accepted, and genuine Store predictive usefulness remains to be demonstrated. These limits distinguish implemented safeguards from proven operational benefits.

[[PAGE]]
# Chapter 5 summary and conclusion
## 41 Replace the Summary
@Asa ibutang: 5.1 Summary, ILISI P0639 to P0640.
@Text to paste
RetailMind integrates Product and inventory records, Direct Stock Receiving, cashier transactions, receipts, authorized refunds and corrections, manual GCash services, reporting, and forecasting support for Shalom Store. Four workspaces separate technical governance, Store oversight, inventory operations, and cashier activities. Buying guidance uses usable forecast output when available and stock rules otherwise, while actual purchasing remains a human decision.

The forecasting pipeline uses 14 pre-encoding predictors and a global Random Forest Regressor. Store training requires at least 140 accumulated usable days per Product and the required recent-input and chronological-evaluation checks. Candidate qualification, prospective observation, local Administrator approval, and usable daily output are separate requirements. History Coverage bands do not automatically activate forecasts.

The retained public M5 comparison showed lower Random Forest errors at 14 and 28 days and lower weekly-baseline error at 7 days. The newer isolated candidate training job succeeded but failed its configured qualification limits and remained unapproved. Public-data comparisons, isolated application tests, installed Store acceptance, and genuine Store predictive usefulness are separate forms of evidence. The retained deployment evidence does not establish accepted unattended forecasting operation or demonstrated local predictive benefit.

## 42 Replace the Conclusion
@Asa ibutang: 5.2 Conclusion, ILISI P0642 to P0645.
@Text to paste
RetailMind provides connected, role-specific workflows for recording Store transactions and reviewing inventory decisions. Its implemented functions support barcode-assisted checkout, traceable stock changes, receiving, receipt retention, and authorized operational review. The extent of realized efficiency or business improvement must be established through the documented Store evaluation rather than inferred from the presence of these functions.

The retained local M5 experiment produced mixed horizon results. Random Forest WAPE was 43.00%, 32.44%, and 29.63% for the 7-, 14-, and 28-day totals, compared with 37.88%, 37.71%, and 36.53% for the weekly baseline. The secondary classification F1 of 0.7525 concerns sale-day identification and does not establish quantity accuracy, stockout prevention, or Store readiness.

The system separates evidence collection, training eligibility, qualification, observation, approval, and forecast usability. A successful training job does not automatically authorize a model, as shown by the failed isolated candidate qualification. Stock-rule guidance supports review while usable forecasts are unavailable. These controls limit inappropriate use of predictions; they do not guarantee reliable Store forecasts or prove reduced shortages, waste, or cost. Continued Store evidence and installation acceptance are required before stronger operational conclusions can be drawn.

[[PAGE]]
# Chapter 5 recommendations
## 43 Replace the Recommendations
@Asa ibutang: 5.3 Recommendation, ILISI P0647 to P0650.
@Text to paste
**Maintain supported Store evidence.** Record checkout, receiving, Stock Issues, counts, and operating-day information consistently. Distinguish supported zero sales from closures, stockouts, and unknown observations. Where authentic historical daily Store sales are available, use the controlled import workflow with retained provenance. Public benchmark observations must remain isolated.

**Complete the training and approval sequence.** Accumulate at least 140 usable days per Product and satisfy recent-input and chronological-evaluation requirements before training. Retain the configured per-horizon quality and baseline checks, prospective observation, and local Administrator operational review. Do not lower thresholds, fabricate observation progress, or treat training success as approval merely to obtain demonstration output.

**Use buying guidance with its stated basis.** Review expected sales separately from the suggested purchasing quantity and current stock. Consult stock-rule guidance when usable forecasts are absent and record actual goods through Direct Stock Receiving. Review the real exclusion reason for Products without usable forecasts rather than assuming every exclusion is caused by insufficient history or high WAPE.

**Retain all supported horizons and evaluate local usefulness.** The public benchmark’s 14- and 28-day advantage is specific to the selected population and evaluation period. Continue evaluating 7-, 14-, and 28-day estimates against simple baselines and genuine Store outcomes. Report Product-level variation, persistent overprediction or underprediction, and the limitations of recorded sales during stockouts.

**Complete installation and acceptance evidence.** Verify the actual Store PC configuration, scheduled background operation, signed-out restart behavior, supported devices, printing path, and role-based user tasks. Report unexecuted tests as pending and retain defects and retest outcomes. Acceptance questionnaires should be interpreted alongside technical and deployment evidence.

**Clarify the forecasting interface.** Prioritize the 140 usable day policy and remaining eligibility checks, keep History Coverage secondary, and display state-specific reasons for unavailable forecasts. Match each suggested buying quantity to its actual basis. Keep benchmark previews explicitly separate from Store operational forecasts.

**Investigate future features through validation.** Product categories, price changes, promotions, and local events may be considered in future experiments when their data are sufficiently supported and available at the forecast origin. Any feature expansion requires an updated feature contract, chronological testing, baseline comparison, and review. Additional history alone does not establish that a new feature will improve forecasts.

[[PAGE]]
# User manual replacement instructions
## 44 Administrator Database Backup
@Asa ibutang: User Manual, Administrator Database Backup, ILISI P1030 to P1031. Kini para sa current automatically encrypted packages.
@Text to paste
- Open Database Backup and create the encrypted package.
- Download the package and store it securely.
- Download an authorized recovery-key copy and keep it separately from the package. A person holding both can decrypt the contents.
- Do not use your login password as the backup encryption secret. Changing the login password does not change the package’s generated encryption key.
- For an older passphrase-encrypted package, follow the labelled legacy-package procedure and provide its original passphrase when required.

## 45 Add the local restore procedure
@Asa ibutang: Human sa Administrator Database Backup instructions, IDUGANG ang heading “Local Database Restore”. Kini para sa Administrator ug Super Administrator; dili remote operation.
@Text to paste
1. Sign in locally using an authorized Administrator or Super Administrator workspace.
2. Select the encrypted package to review. Import the required source recovery key through the authorized process if the destination does not already hold it.
3. Review the package, destination state, and any account-identity conflicts. Resolve conflicts explicitly rather than assuming matching account IDs identify the same person.
4. Verify your current login password when requested. The password authorizes the action; it is not the package encryption key.
5. Review the snapshot and replacement warning. Restoring an older snapshot replaces newer Store state; it is not a selective transaction edit.
6. Confirm replacement only after the required review. The workflow performs the applicable integrity, safety-copy, identity, authority, and write-pause checks.
7. Follow verification or recovery instructions. Resume Store operations only through explicit Store Reopening after the required checks.

## 46 Replace the Inventory Manager forecast instructions
@Asa ibutang: Inventory Manager Demand Forecast, Forecast Exceptions, ug Data Readiness, source P1094 to P1106. Ibutang ang applicable bullets ubos sa tulo ka headings.
@Text to paste
- On Demand Forecast, review the stated period, model/output status, and available estimates. An unavailable estimate does not mean zero expected sales.
- On Forecast Exceptions, review why a Product has no usable forecast and follow the indicated next action or refer technical blockers to the authorized administrator.
- On Data Readiness, distinguish accumulated usable days, recent-input support, and evaluation coverage. Reaching 140 days alone does not satisfy every requirement.
- Treat History Coverage bands as supporting information, not an activation countdown.
- Review suggested buying quantities and their basis separately from expected sales. Record goods actually bought through Stock Receiving.

[[PAGE]]
# Additional user guidance and Appendix B
## 47 Add model operation responsibilities to the manual
@Asa ibutang: User Manual, IDUGANG ang separate Administrator Model Review ug Super Administrator ML Operation headings. Dili kini Inventory Manager controls.
@Text to paste
**Administrator Model Review**
- Review the candidate’s evaluation, completed observation evidence, and operational readiness before an eligible approval or replacement decision.
- Request Forecast Generation when authorized and check whether the job was queued, completed, failed, or blocked.
- Do not treat a succeeded training job as an approved model or a historical preview as a current Store forecast.

**Super Administrator ML Operation**
- Configure technical forecasting controls and inspect scheduler, worker, and job diagnostics.
- Import only certified historical sales from the same Store through the controlled history-import workflow.
- Request candidate retraining and review qualification results and the relevant model evidence.
- Keep public benchmark data and artifacts within their explicitly isolated Demo boundary.
- Preserve the required observation and operational approval checks; technical activity does not automatically authorize Store use.

## 48 Replace Appendix B scope and limitation cells
@Asa ibutang: Appendix B Pre-Proposal Statement, table T022. ILISI lamang ang Scope of the Study ug Limitation of the Study cells, dili ang signed title or approval fields.
@Text to paste
**Scope of the Study**
RetailMind provides a single-Store web application with Super Administrator, Administrator, Inventory Manager, and Cashier workspaces. Its scope includes Products, inventory and batches, Direct Stock Receiving, physical counts, Stock Issues, checkout and shifts, receipts, authorized refunds and corrections, manual GCash services, reports, notifications, and audit records. A Random Forest pipeline provides 7-, 14-, and 28-day recorded-sales estimates where usable output exists. Stock rules provide advisory buying guidance otherwise. Store training requires at least 140 accumulated usable days plus supported recent inputs and chronological evaluation coverage, followed by separate model qualification, prospective observation, and operational approval.

**Limitation of the Study**
The application depends on the Store host and configured local services and network. Internet access supports external services and owner read-only remote monitoring. Physical printing and unattended forecasting require acceptance on the actual installation. The current model excludes category, price, promotion, and local-event predictors and does not directly measure demand lost during stockouts. Public benchmark results do not establish Shalom Store accuracy. Manual GCash services record staff-entered exchanges rather than execute external wallet transfers. Purchasing remains outside automatic application execution, and finalized sales are preserved through linked refund and correction workflows.

[[PAGE]]
# Reference replacements and evidence templates
## 49 Correct the reference entries
@Asa ibutang: References. ILISI ang Zavaleta-Zarate entry P0696. IDUGANG ang ISO, Oracle, ug Zoho references kon gamiton ang related replacement passages. I-align ang final formatting sa inyong school style.
@Text to paste
Zavaleta-Zarate, K., Escobal-Vera, J., & Zarate-Perez, E. (2026). Optimizing inventory in convenience stores to maximize ROI using Random Forest and genetic algorithms. Logistics, 10(3), Article 64. https://doi.org/10.3390/logistics10030064

International Organization for Standardization & International Electrotechnical Commission. (2011). Systems and software engineering—Systems and software Quality Requirements and Evaluation (SQuaRE)—System and software quality models (ISO/IEC Standard No. 25010:2011). https://www.iso.org/standard/35733.html

Oracle. (n.d.). Demand planning. NetSuite Applications Suite. Retrieved November 4, 2026, from https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2286970.html

Zoho Corporation. (n.d.-a). Forecasting. Zoho Analytics Help. Retrieved November 4, 2026, from https://www.zoho.com/analytics/help/chart/forecasting.html

Zoho Corporation. (n.d.-b). Which report helps me forecast how fast I will run out of stock? Zoho Inventory Knowledge Base. Retrieved November 4, 2026, from https://www.zoho.com/en-fr/inventory/kb/reports/stock-runout-forecast.html

## 50 Replace unsupported Store numbers with sourced context
@Asa ibutang: P0044 to P0045 ug P0092. GAMITA lamang human mapun-an ang actual field evidence. Dili code o demo catalog ang source niini nga counts.
@Text to paste
Shalom Store in Tangub City handles approximately [FILL IN VERIFIED DAILY TRANSACTIONS] customer transactions per day and maintains approximately [FILL IN VERIFIED ACTIVE PRODUCTS] active Products during [FILL IN OBSERVATION PERIOD]. Inventory is usually replenished [FILL IN VERIFIED FREQUENCY]. These figures were established through [FILL IN INTERVIEW OR RECORD METHOD AND DATE]. The range of merchandise and transaction activity provide the operational context for evaluating the Store’s inventory and recording requirements.

## 51 Deployment schedule statement
@Asa ibutang: Implementation Plan paragraph P0499. ILISI ang fixed completed-schedule claim kon wala pa ma-reconcile ang dates.
@Text to paste
The implementation schedule distinguishes planned activities from completed and pending work. Development and review records cover [FILL IN VERIFIED PERIOD]. Completed deployment tasks are supported by [FILL IN DATED EVIDENCE], while outstanding installation, scheduling, device, or acceptance tasks remain identified separately. The schedule is a record of project progress rather than proof that every planned activity was completed on its original target date.

[[PAGE]]
# Deployment record and final placement notes
## 52 Actual test environment record
@Asa ibutang: 3.2.1 Hardware and Software Requirements o Chapter 4 Testing Environment, IDUGANG kini nga factual record human mapun-an. Dili kini arbitrary minimum specification.
@Text to paste
| Item | Verified test or deployment value |
| Host and location | [FILL IN] |
| CPU RAM and storage | [FILL IN] |
| Operating system | [FILL IN] |
| PHP Apache and database versions | [FILL IN] |
| Python and forecasting library versions | [FILL IN] |
| Application build or commit | [FILL IN] |
| Database and approximate test data size | [FILL IN] |
| Browsers and screen sizes | [FILL IN] |
| Scanner and printer configuration | [FILL IN] |
| Forecasting schedule and installed runner | [FILL IN] |
| Test dates and evidence reference | [FILL IN] |

## Editor notes nga dili apilon sa manuscript
Human sa copy-paste, i-renumber ang duplicated Chapter 2 related-systems heading, 3.4.2 subsections, 3.6 headings, Chapter 4 sections, figures, ug tables. Tangtanga ang “1.12 Stock Reporting” nga naa sa Chapter 4. I-update ang all cross-references, contents, list of figures, ug list of tables. Ang ERD paragraph dapat Figure 12, dili Figure 11, hangtod ma-final ang numbering.

Ang original Figure 22A, 22B, ug 22C captions gipang-reference dinhi aron dali makit-an. Ibutang sila sa final reading order ug ilisi ang final figure numbers sa capstone. Ang screenshot nga adunay old 56-day activation, universal 35 percent exceptions, o old passphrase instructions dili mapulihan pinaagi sa caption lamang.

Ang SAP ug IBM sign-in screenshots dili ebidensiya sa ilang inventory features. Ilisi sa relevant, properly sourced illustrations o usba ang caption sa actual gipakita. Ang architecture, context, DFD, use-case, ug ERD illustrations kinahanglan motakdo sa revised roles ug workflows. Ang exact approved title, signatory names, ug signed school forms kinahanglan gikan sa inyong approved records.

Padayon ang bibliography checks sa remaining references: ang Sekhar ug Veluru entries adunay conflicting author/year information nga nag-share og ResearchGate identifier. Ang Makridakis 2020 in-text citation kinahanglan adunay sakto nga corresponding source kon ipadayon. Wala kini pun-i og invented metadata.

Ang mosunod nga appendix mahimong ikopya isip **Selected Core Data Dictionary** human sa ERD narrative. Ang field names ug types gikan sa repository schema; selected fields lamang kini aron dili magpakaaron-ingnon nga complete physical dictionary. Kung required sa adviser ang exhaustive dictionary, dugangi gikan sa exact effective deployed schema ug migrations.
