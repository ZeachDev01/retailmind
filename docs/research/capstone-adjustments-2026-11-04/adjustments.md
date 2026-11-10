# RetailMind Capstone Adjustment Guide
Prepared for the capstone authors • 4 November 2026

Revise the manuscript so that its requirements, diagrams, tests, results, conclusions, and user manual describe the same RetailMind system. The main correction is to separate history coverage, eligibility for training, model qualification, approval, and usable operational forecasts. Retain the 140 usable day training policy and explain why a new installation can still provide stock-rule buying guidance.

Source manuscript: Capstone 2 - RetailMind revised (RFR) printing.docx. This guide provides the changes to make; it does not replace the manuscript. Apply substantive corrections before renumbering figures and tables or replacing screenshots.

## Order of revision
| Priority | Work to complete | Main locations |
| 1 | Correct activation rules and exception scope | Chapters 1, 3, 4, and 5 |
| 1 | Correct benchmark provenance and interpretation | Training method and forecast results |
| 1 | Update role permissions and backup instructions | Requirements, tests, and user manual |
| 2 | Rebuild diagrams and the data dictionary | Figures 8 to 12 and Table 5 |
| 2 | Separate test plans from observed results | Alpha tests, UAT, deployment evidence |
| 2 | Apply agreed forecasting UI changes | Four forecasting pages and screenshots |
| 3 | Repair references, scales, numbering, and wording | Whole manuscript and appendices |

## How to use the locations
Each adjustment names the manuscript section and an exact search phrase or figure/table label. P and T identifiers are supplemental review anchors: P counts top-level Word paragraphs, including blank paragraphs; T counts tables in document order. They are not printed page numbers. Use Word Find with the quoted phrase to locate the passage reliably after pagination changes.

**Status terms:** Required correction means the manuscript conflicts with current implementation or retained evidence. Agreed UI change means the design is settled but must be implemented and checked before screenshots or results claim completion. Evidence task means the authors must supply dated observations, measurements, or study records.

Technical source references F1 to F8, O1 to O7, and E1 to E5 are listed at the end. They distinguish application behavior, accepted decisions, experiment evidence, and external reference checks.

[[PAGE]]
# Forecasting rules to use throughout
## Separate the five kinds of readiness
**History coverage:** Existing bands are Insufficient below 56 usable days, Low at 56–179, Medium at 180–364, and High at 365 or more. Keep these as secondary descriptions of available history. They do not certify accuracy, trigger approval, or establish that a forecast is usable. [F1]

**Training eligibility:** Each Product needs at least 140 accumulated usable days, a supported recent 28-calendar-day input window, and three supported chronological 28-day evaluation periods backed by earlier training history. The policy is fixed and read-only in the current system. Reaching 140 alone is insufficient. [F1]

**Usable history day:** Count an open Store day with supported observed demand, including genuine zero sales. Closures, stockouts, and unknown observations do not add usable days and do not reset the accumulated total. Supported closures do not automatically invalidate a recent or evaluation window. Calendar span, days with sales, and usable days are different measures. [F1]

**Candidate qualification and approval:** Background training creates a candidate. Qualification checks configured maximum WAPE limits and requires a strict improvement over the weekly Seasonal Naive baseline for all three horizons. Activation also requires at least 28 completed prospective target dates with usable observations and local Administrator operational approval. An explicit technical rejection blocks activation; a separately recorded Super Administrator acceptance is not always required. [F2]

**Usable operational forecasts:** Even an approved model needs current, complete, compatible forecast output for the Product. Missing, stale, suspended, observation-only, incompatible, or superseded output can remain unusable. Training success is different from qualification, approval, and daily generation success. [F3]

## Data and buying guidance
Certified historical daily sales from Shalom Store may count through the controlled history import. Do not say that only sales recorded after installation can count. POS evidence remains authoritative on overlapping dates; omitted dates are unknown. M5 and other public/demo data remain isolated. [F4]

Forecast Quantity estimates future recorded sales in Base Units. Suggested Buy Qty is the purchasing recommendation after considering stock and planning rules. Recommendations do not create orders or change inventory. Direct Stock Receiving records goods actually bought. [F3, O1]

**Use 56 days only as a history milestone.** The decision to retain it in a secondary legend does not validate a new 56-day training route. Likewise, 140 is the current application policy, not a universal scientific guarantee of sufficient data.

[[PAGE]]
# Chapter 1 adjustments
## A01 Explain the initial data collection period
**Location:** Section 1.2, P0056. Find “collecting store sales data for a period of time.” Also correct P0469, P0579, and P0648, which explicitly promise activation at 56 days.

**Required correction:** Replace the vague wait and the 56-day activation promises with the full eligibility and approval sequence. Do not promise activation after a fixed number of calendar days. Suggested replacement:

“At initial deployment, RetailMind collects supported Store sales history and provides stock-rule buying guidance where usable forecasts are unavailable. A Product becomes eligible for Store model training only after accumulating at least 140 usable days and satisfying the recent-input and chronological-evaluation requirements. Model training, qualification, prospective observation, operational approval, and forecast generation remain separate steps. Certified historical sales from the same Store may contribute through the controlled history-import workflow.” [F1–F4]

## A02 Correct the role objectives
**Location:** Section 1.3.2, P0065–P0087. Find “System Administrator.”

Use Super Administrator, Administrator, Inventory Manager, and Cashier consistently. Expand the Super Administrator objective to include technical forecasting configuration, controlled history import, and model retraining. The Administrator handles Store governance and eligible operational model review. The Inventory Manager reads forecasts and recommendations rather than manually launching training or generation. Restrict staff-account assignments to the roles allowed by the active workspace. [O2, F2]

## A03 Include the stock rule fallback in buying guidance
**Location:** P0079, P0113, P0291. Find “based on forecasted product demand.”

State that buying guidance uses a usable forecast when available and stock rules otherwise. Keep expected sales separate from suggested purchase quantity. Use “advisory replenishment recommendations,” not automatic purchasing or required order quantities. Preserve P0094 and P0103 where they already explain these boundaries. [F3, O1]

## A04 Clarify the model inputs and key terms
**Location:** P0050, P0102, P0108–P0115; IPO Tables T001–T003.

Keep the 14 specified predictor columns. Do not imply that local events, prices, promotions, category attributes, or current inventory are active model predictors merely because the application records them. Current stock supports the buying calculation separately. Correct “Retail” to “RetailMind” in the IPO output. Add definitions for Usable History Day, History Coverage, Training Eligibility, Forecast Quantity, Forecast Horizon, and Replenishment Recommendation. Distinguish low stock from a Stockout Day. [F1, F3, F5, O1]

## A05 Support the Store context with field evidence
**Location:** P0044–P0045 and P0092: “400 customer transactions,” “1,200 active products,” and “twice a week.”

Retain these numbers only with an observation period, counting method, and interview/log reference. Reconcile the approved project title and Store classification with the adviser; do not treat code or sample catalog counts as evidence that the Store is a high-volume department store.

[[PAGE]]
# Chapter 2 and technical background adjustments
## A06 Separate related research from RetailMind capabilities
**Location:** P0122–P0126. Find “automated replenishment” and “automated reordering mechanisms.”

It is acceptable to describe automation in a cited study. Rewrite the sentence connecting that study to RetailMind so it states that RetailMind generates advisory buying guidance and staff decide what to buy. Do not transfer another study’s ROI, stockout, accuracy, event-feature, or replenishment-optimization findings to this implementation. P0094 and P0215 already provide a better scope description. [O1, F5]

## A07 Repair the related systems comparison
**Location:** P0135, P0150, P0189, P0214 and Figures 2–5.

Remove the duplicated Zoho description. Avoid blanket claims that Zoho lacks forecasting or that NetSuite is only rule-based without identifying the product, module, edition, date, and primary source checked. Oracle documents historical-demand forecasting and demand planning; Zoho documents analytics forecasting and stock-runout analysis in its product ecosystem. These do not prove every Inventory edition includes the same capability. Compare verified deployment fit, scope, required integrations, and cost evidence instead. [E3–E4]

The SAP and IBM illustrations are sign-in screens, not evidence of inventory or forecasting functions. Replace them with correctly attributed relevant views or caption them accurately. Include source and access date for third-party images. Refresh the comparison only after checking the exact products described.

## A08 Correct the experiment execution environment
**Location:** Tables T005 and T007; P0225, P0370, P0380, P0615–P0616, P0644. Find “Google Colab.”

Replace completed Colab-execution claims with “The reproducible Python experiment was executed locally; a Colab-compatible notebook is provided.” Retained evidence identifies local Python execution. Claim an actual Colab run only after retaining its executed notebook, output, dataset provenance, and package versions. Do not confuse a notebook installation target with the packages used for a recorded run. [F6–F7]

## A09 Make the tools and host requirements specific
**Location:** Hardware Tables T004/T006; Software Tables T005/T007; P0225.

Separate the developer workstation, actual Store PC host, client browsers, and tested peripherals. Replace the generic Xeon/Windows Server requirement with the actual deployment specification and measured capacity, or label it as an unvalidated proposal. List the PHP, database engine, Python, and library versions actually used. Use “MySQL-compatible database” until the installed MySQL/MariaDB version is confirmed. Verify Bootstrap usage rather than listing it by assumption.

Replace “EmailJS, Twilio, or Gmail SMTP” with the actual notification implementation and selected configuration. The code provides in-app notices and email delivery options using PHPMailer SMTP, Brevo, or PHP mail; no SMS integration was established by this review. Network access and credentials affect delivery. Do not promise forecast-spike email without checking its trigger. [O3]

[[PAGE]]
# Chapter 3 forecasting method adjustments
## A10 Describe the asynchronous pipeline
**Location:** P0225, P0290, P0315–P0316, P0367–P0375.

Explain that PHP validates and queues a database job, a CLI Python worker trains/evaluates candidates or generates forecasts outside web requests, and PHP displays saved output. Preserve the largely accurate architecture paragraph P0316. Avoid obsolete Flask-service or request-time training descriptions. [F4–F5]

Document current default scheduling as daily generation at 01:00 and weekly retraining on Sunday at 01:00, Asia/Manila, subject to explicit operator configuration and eligibility. The scheduler uses completed-day evidence and avoids duplicate work. Configuration and source code do not prove an unattended Store installation is running. [F8]

## A11 Make the model recipe reproducible
**Location:** P0247, P0367, P0371, Table T009, P0616.

The 14 pre-encoding predictors are product_id, horizon_day, lag_1, lag_7, lag_14, lag_28, rolling_mean_7, rolling_std_7, rolling_mean_28, rolling_std_28, day_of_week, day_of_month, month, and is_weekend. Product identity is one-hot encoded, so the transformed matrix can have more than 14 columns. The current shared recipe uses 15 trees, maximum depth 8, random seed 42, and feature version 2.0.0. [F5]

Correct the lag description: lag_1 uses the cutoff observation or supported latest trading observation; the normal lag_7, lag_14, and lag_28 calendar anchors are cutoff minus 6, 13, and 27 days. Avoid an off-by-one explanation that puts every lag before the origin. Calendar indicators represent date patterns; they do not establish observed payday effects or weekend surges.

## A12 Explain the chronological experiment precisely
**Location:** P0375, Table T010, P0616.

The initial training period is 1 March 2014 to 28 February 2016. Two subsequent 28-day blocks support validation and sale-day threshold selection; the last block is the reported held-out comparison. The model is refit at each origin using only earlier available targets. Do not describe one unchanged 730-day fit generating every fold. [F6–F7]

| Fold | Forecast origin | Latest training label | Evaluation dates |
| 1 | 28 Feb 2016 | 26 Feb 2016 | 29 Feb to 27 Mar 2016 |
| 2 | 27 Mar 2016 | 25 Mar 2016 | 28 Mar to 24 Apr 2016 |
| 3 | 24 Apr 2016 | 22 Apr 2016 | 25 Apr to 22 May 2016 |

The final window had already been inspected during development. Describe it as a held-out development comparison; avoid calling the expansion a fresh independent final test. Threshold 0.5 is for the secondary sale-day classification task, not a forecast activation rule. [F6–F7]

[[PAGE]]
# Chapter 3 requirements and authority adjustments
## A13 Use the current role boundaries
**Location:** P0286–P0293, P0331, P0347, P0352; Tables T011/T018.

| Workspace | Responsibility to document |
| Super Administrator | Platform governance, model settings, history import, manual retraining, technical review, and permitted model operations |
| Administrator | Store oversight, delegated staff access, registers, fiscal periods, Store settings, forecast generation requests, operational model review, and backups |
| Inventory Manager | Products, receiving, inventory counts and Stock Issue review, forecast and buying-guidance review |
| Cashier | Shift-authorized checkout, permitted receipts and refunds, manual GCash services, and Stock Issue submission |

Administrator and Super Administrator can request Forecast Generation; Inventory Manager consumes results. Only Super Administrator manually requests model retraining. Active workspace permissions apply; a screenshot of a privileged person in another workspace does not prove that workspace owns all their account capabilities. [O2, F2]

## A14 Correct credential and backup requirements
**Location:** P0295–P0298, Table T008, P1029–P1031, Admin questionnaire T012 Security item 5.

Use “password hashes,” not “encrypted passwords.” Keep P0296’s correct wording. Routine Database Backups are encrypted packages, including required uploaded images, encrypted with generated server-held keys. They do not require a new independent 16-character passphrase. Save a recovery-key copy separately; possession of both key and package permits decryption. Losing all copies of the required key makes its packages unrecoverable. [O4]

Either administrator role may restore locally using their own current login password and final replacement confirmation. Restore replaces the Store snapshot and preserves the required integrity, identity, destination-state, safety-copy, write-pause, recovery, verification, and explicit reopening checks. The login password authorizes the action; it is not the encryption secret. Legacy passphrase packages retain their older compatibility path. [O4]

## A15 Correct connectivity and mobile claims
**Location:** P0298; Hardware Table T006; Appendix B Table T022.

Local operations depend on reaching the Store PC and its running services through the configured local network. Internet is needed for external services and remote access, rather than every local checkout. The accepted remote design is owner-only read-only monitoring; do not promise remote staff checkout, receiving, restore, or unrestricted administration. Actual remote setup still needs installation evidence. [O5]

Distinguish responsive mobile pages, receipt preview, and physical printing. A tablet cannot be assumed to print directly to a Store PC USB printer. Document and test the PC-side printing path and ensure reprint does not repeat a sale. Do not claim every USB/Bluetooth printer or listed operating system was validated. [O6]

[[PAGE]]
# Diagrams and data dictionary adjustments
## A16 Redraw the affected diagrams together
**Location:** Figures 8–12; P0315–P0359; Table 5, review anchor T008.

**Figure 8 architecture:** Retain the local Store PC, browser, PHP, database, scheduler, and Python worker structure. Separate Super Administrator and Administrator responsibilities instead of combining their authority. Show that training/generation is queued, output is saved, and staff review guidance. An approved model and usable forecast output are separate states.

**Figure 9 context:** Replace the legacy three-role picture with four workspace actors and correct their inputs/outputs. Cashier does not receive stock. Inventory Manager does not own manual forecast generation. Remove request/order approval flows from the application boundary. [O1–O2]

**Figure 10 DFD:** Remove Replenishment Requests and the Cashier receiving process. Model recommendation review separately from Direct Stock Receiving. Add the job queue, historical evidence/import boundary, candidate evaluation, operational approval, and persisted forecast flow at a legible level of detail. Ensure each data-store name matches the intended physical or conceptual level.

**Figure 11 use cases:** Add technical forecasting governance under Super Administrator, operational model review under Administrator, and direct receiving under Inventory Manager. Keep sales, refunds, shifts, GCash, and Stock Issues within the correct workspace. **Figure 12 ERD:** regenerate from the implemented schema and label any simplified view explicitly. [O2, F2–F4]

## A17 Rebuild the dictionary from the schema
| Manuscript entry | Required correction |
| users.full_name | Use actual first_name, last_name, and display_name fields |
| inventory.quantity | Use quantity_on_hand |
| sales_items and sales_item_id | Use sale_items and sale_item_id; verify unit_price and package fields |
| audit_logs and inventory_transactions | Use current activity_log and stock_movements structures |
| system_settings | Reflect separate platform_settings, store_settings, and ml_settings |
| replenishment_requests | Remove the retired operational workflow |
| Simplified demand_forecasts fields | Use origin, target date, horizon day, predicted units, job, model, readiness, and limitation fields |

Keep users.password_hash but describe it as a hash. Verify whether conceptual reports and damage_reports are actual tables before listing them as physical entities. [O7]

Add the relevant role assignments, registers/shifts, batches, receiving groups/items, counts/adjustments, Stock Issues, receipt/refund/correction, GCash, ML jobs/training/evaluations, history import, and model-governance entities. Copy types, nullability, keys, and relationships from schema plus migrations; do not invent fields. P0359 must reference Figure 12 rather than Figure 11. [O7, F2–F4]

[[PAGE]]
# Alpha testing adjustments
## A18 Keep the plan and results distinct
**Location:** Chapter 3 Alpha Testing, Table T011; Chapter 4 “Alpha Testing Result,” P0610 and Table T018.

Chapter 3 can retain a test plan. Chapter 4 needs observed results. Its current Activity, Expected Outcome, and Duration columns do not establish that tests passed. Add test ID, build/date, environment, tester, input/scenario, expected outcome, observed outcome, status, evidence, and defect/retest reference. Durations alone prove neither correctness nor acceptable response time.

Do not fill observed outcomes from source-code inspection. If a test was not executed, mark it Not run and state the missing evidence. Record measured response times with dataset size and hardware when making performance claims.

## A19 Repair the obsolete test activities
**Location:** Both Alpha tables, T011 and T018.

- Move Stock Receiving from Cashier to Inventory Manager. Add a Cashier access-denial case for that action.
- Replace “Replenishment Requests—create and track requests” with recommendation review. Verify that viewing guidance creates neither an order nor stock movement.
- Separate Administrator Store Settings from Super Administrator Platform Settings and ML Operation.
- Add technical governance coverage, or explicitly state that Store UAT covers three operational roles and technical verification is separate.
- Treat the Inventory Manager’s forecasting task as reviewing states/results; move training/import actions to their authorized technical role. [O1–O2]

## A20 Add the cases needed by the current decisions
| Test case | Expected behavior to verify |
| 139 and 140 usable days | Below 140 is blocked; 140 with a failed window is still blocked |
| Evidence classifications | Supported zero counts; closure/stockout/unknown does not add or reset accumulated days |
| Missing model or output | Reason-specific state; no invented forecast or zero-demand substitution |
| Candidate fails a quality gate | Remains unapproved; historical preview does not bypass the gate |
| Historical rows imported today | Do not count as completed prospective observation |
| Buying guidance basis | Displayed buying quantity and basis agree with the calculation |
| Workspace boundary | Unauthorized generation, retraining, receiving, and remote writes are blocked |
| Backup and restore | Correct local authorization, replacement checks, safety copy, pause, verification, and reopening |

Also retain transaction checks for duplicate checkout retries, grouped receiving/batches, GCash reconciliation, linked refunds/corrections, and immutable original receipts. Use disposable test data and environments for destructive restore scenarios. Tests listed here are revision requirements, not new execution results. [F1–F3, F7, O2, O4]

[[PAGE]]
# Beta evaluation and analysis adjustments
## A21 Correct the Administrator security question
**Location:** Administrator questionnaire T012, Security item 5: “restore a database or retrain a forecasting model.”

Split the combined statement. An Administrator must be blocked from technical model retraining, but authorized local Database Restore is allowed. Suggested replacement items:

“The system blocks technical model retraining from the Administrator workspace.”

“For an assigned local restore test, the system requires my current password, package review, and explicit replacement confirmation before restoring the Store.”

Use a separate technical checklist to verify safety copy, identity protection, paused writes, recovery, and reopening. Do not require respondents to perform a live destructive restore simply to answer a questionnaire. [O2, O4]

## A22 Align the response scale and missing responses
**Location:** P0403, P0415, P0426; P0431; Tables T015/T016.

The forms use Strongly agree to Strongly disagree, but the analysis tables use Excellent to Poor and unrelated “understands functionality” descriptions. Choose one coherent instrument and matching interpretation with adviser approval. A practical option is to preserve the agreement statements and agreement labels throughout. Do not silently relabel agreement as proven system quality.

Add Not tested or Not applicable. Exclude those and missing responses from the valid-response denominator, report valid n, and do not force unperformed tasks into Neutral. Define an item mean as sum of valid ratings divided by valid n. For role/category summaries, state whether items or responses are weighted equally. A zero valid n is Not evaluated, not zero quality.

If mean ranges are required, use a clearly defined adviser-approved scheme and boundary convention. The current unequal ranges need a rationale. Correct “Rage of Weighted Mean” and “Ranoverall.” Do not derive respondent counts, means, or acceptance conclusions from blank forms.

## A23 Name the ISO edition and evaluation limits
**Location:** P0394 and all three role forms.

The eight characteristics listed match ISO/IEC 25010:2011. Identify that edition explicitly if it is the approved study basis. It has been superseded; ISO/IEC 25010:2023 has nine product-quality characteristics. Do not present the eight-characteristic form as the current edition or claim ISO certification. Changing editions requires a deliberate instrument revision rather than a label change. [E1–E2]

## A24 Separate user acceptance from technical verification
Role respondents can assess tasks they performed or observed. Their answers alone cannot establish security strength, maintainability, measured speed, installed scheduling, or physical device compatibility. Supplement the questionnaires with technical tests and measurements. If only Administrator, Inventory Manager, and Cashier participate, state that scope and report Super Administrator verification separately.

[[PAGE]]
# Agreed forecasting UI changes
These are agreed design adjustments. Implement and verify them before describing them as delivered or replacing the manuscript screenshots. Existing historical Demo views remain separate; the additional benchmark-preview button on the live Store page is deferred.

## A25 Demand Forecast
**Location:** Figure 22, P0469; Chapter 4 P0579; manual P1094–P1096.

Suggested subtitle: “Estimated sales for the next 7, 14, and 28 days, based on supported Store history.”

Use actual status for the primary card: Collecting history, Training requirements unmet, Awaiting model approval, Forecast available, or a specific failure/unusable-output state. “Collecting Store Data” must not replace every failure. Show Random Forest as the method; show the approved model version separately only when one exists. Do not claim “Validated via Colab.”

Use a truthful count such as Products with usable forecasts: 0 of [eligible catalog total], with its population defined. Keep the forecast origin meaningful; when no output exists, explain that forecasts have not yet been generated rather than inventing an origin date. Retain all three supported horizons; do not hide 7 days merely because the benchmark favors longer horizons.

Cold-start copy: “No Store forecasts yet. RetailMind is collecting supported history. Training requires at least 140 usable days per Product plus recent-input and evaluation checks. Model qualification, observation, and approval follow separately. Review Replenishment Recommendations for current stock-rule buying guidance.”

## A26 Forecast Data Readiness
**Location:** Figure 22C, P0487; manual P1104–P1106.

Make Training Eligibility primary: “12 / 140 usable days,” with separate recent-input and evaluation checks. At 140 / 140, a failed check must still be visible. Label the measure Usable history days rather than Active Days. Explain that genuine zero-sales days count while closures, stockouts, and unknown observations do not. Keep the 56/180/365 History coverage legend secondary. Avoid automatic confidence, payday, or seasonal-accuracy promises.

## A27 Replenishment Recommendations
**Location:** Figure 22A, P0481; P0575; manual P1064–P1066.

Use Current Stock, Suggested Buy Qty, and a clearly labelled forecast period. Display the actual recommended buying quantity with Basis: Stock Rule or Basis: Demand Forecast. The current main table displays an independent stock-rule quantity; changing its label alone would misrepresent that number. Show expected sales separately. For absent forecasts, display the real exclusion reason rather than universal “Needs 56d history.” A zero suggested buy quantity can mean stock is sufficient; an absent forecast is not zero expected sales. [F3]

## A28 Forecast Exceptions
**Location:** Figure 22B, P0484; P0631; manual P1099–P1101.

Use: “Products without usable forecasts. Review the reason and next step.” Show actual blockers and actions. Quality thresholds belong in candidate evaluation; queue membership is not universally defined by per-product WAPE above 35% or failure against the 7-day baseline. [F2–F3]

[[PAGE]]
# Chapter 4 forecast results adjustments
## A29 Preserve the supported historical comparison
**Location:** P0615–P0624 and comparative results Table T019, currently labelled Table 12.

Keep the reported final-window quantities and scores. Replace “Google Colab Training and Validation” with wording that identifies the retained local Python experiment. Label the population as the selected 50 M5 CA_1 FOODS products and the evaluation as the final historical development holdout, 25 April to 22 May 2016. This is not measured Shalom Store accuracy. [F6–F7]

| Horizon | Actual units | RF MAE | Baseline MAE | RF RMSE | Baseline RMSE |
| 7 days | 689 | 5.926 | 5.220 | 11.921 | 8.858 |
| 14 days | 1,538 | 9.977 | 11.600 | 16.414 | 20.131 |
| 28 days | 3,195 | 18.936 | 23.340 | 32.244 | 41.846 |

| Horizon | RF WAPE | Baseline WAPE | Lower error |
| 7 days | 43.00% | 37.88% | Weekly Seasonal Naive |
| 14 days | 32.44% | 37.71% | Random Forest |
| 28 days | 29.63% | 36.53% | Random Forest |

MAE and RMSE measure per-product horizon totals across the 50 products, not daily error. WAPE is aggregate absolute error divided by actual units, not “accuracy” or a guarantee of stock availability. These retained scores use fractional experiment predictions; do not recompute them from independently rounded daily display values.

## A30 Report candidate qualification separately
**Location:** Add to the results discussion after P0624; align P0640 and P0645.

Newer retained evidence records an actual asynchronous isolated candidate run on 3 November 2026, rechecked on 4 November. Its rounded quantities, pooled across three chronological evaluation blocks, produced different qualification scores:

| Horizon | Candidate WAPE | Baseline WAPE | Configured maximum | Outcome |
| 7 days | 44.33% | 39.81% | 35% | Failed |
| 14 days | 40.86% | 41.11% | 35% | Failed |
| 28 days | 40.64% | 41.97% | 35% | Failed |

The training job succeeded, but the candidate failed qualification and remained unapproved. Historical prediction imports contributed 0 of 28 prospective observation days. Do not replace the earlier table with these numbers: aggregation, rounding, and purpose differ. A failed candidate is a valid finding about the safeguard, not a reason to relax limits or claim live deployment. [F7]

[[PAGE]]
# Results interpretation and Chapter 5 adjustments
## A31 Keep classification separate from inventory outcomes
**Location:** Table T020 and P0628; conclusion P0644.

The retained threshold-0.5 classification counts and F1 values are supported: RF precision 63.12%, recall 93.14%, F1 0.7525; weekly baseline F1 0.6851; always-sale F1 0.7365. There are 1,400 product-day cases. Keep the table, but label it a secondary sale-day classification analysis. [F6–F7]

Remove P0628’s claim that a false negative directly triggers a shelf stockout. It means a recorded sale occurred on a day classified as no sale. It does not establish shelf availability, unmet demand, or stockout reduction. Keep P0623’s caution consistent with the rest of the chapter. Quantity errors remain the relevant forecast measures.

## A32 Correct the meaning of 22 out of 50
**Location:** P0630–P0632.

The count is supported as a 28-day absolute-error comparison: RF improved error for 28 products and did not improve it for 22. It does not prove those 22 were erratic, exceeded 35% validation WAPE, or failed a 7-day comparison. Suggested replacement:

“In the selected final test window, Random Forest did not improve 28-day absolute error for 22 of 50 products. This illustrates variation across products and supports continued human review. The count does not determine which Store products enter Forecast Exceptions.” [F6–F7]

## A33 Rewrite the safeguards conclusion
**Location:** P0645. Replace “ensures ... reliable inventory buying guidance.”

“RetailMind separates history coverage, training eligibility, candidate qualification, prospective observation, operational approval, and forecast usability. When a usable forecast is unavailable, the system provides identified stock-rule buying guidance. These controls limit inappropriate use of predictions, but they do not establish predictive accuracy or operational benefits on Shalom Store outcomes.” [F1–F3]

## A34 Align the recommendations with the system
**Location:** P0648–P0650.

Replace the recommendation to cross 56 days and activate forecasts with collection of supported history toward 140 usable days and completion of the remaining eligibility, qualification, observation, and approval steps. Include controlled import of authentic Store history where available. Do not treat logging more POS sales alone as proof of complete observations.

Keep all three horizons in the system. Describe the 14/28-day benchmark advantage as specific to this experiment, rather than prescribing a new Store purchasing schedule. Replace universal 35% exception-review wording with actual exclusion reasons. Keep category, prices, promotions, and local events as future feature research; sufficient multi-year history is a research proposal, not an existing feature switch. Preserve P0640’s caution that Store predictive usefulness and installed unattended operation remain unproven. [F5–F8]

[[PAGE]]
# User manual and operational narrative adjustments
## A35 Replace the backup procedure
**Location:** User Manual, Administrator Database Backup, P1029–P1031. Find “passphrase of at least 16 characters.”

Suggested replacement instructions:

- Create and download the encrypted Database Backup package.
- Download a recovery-key copy when authorized and keep it separately from the package.
- For a local restore, sign in as Administrator or Super Administrator, select the package, complete any required key import and account-conflict review, and verify your current login password.
- Review the destination snapshot and explicit replacement warning before confirming. Restore replaces newer Store state with the selected snapshot; it is not a selective receipt repair.
- Follow verification and recovery guidance, then explicitly reopen the Store when checks are complete.

Do not describe the login password as the package encryption key. Keep the older passphrase instruction only within clearly labelled legacy-package guidance. Keep keys private; package plus key permits decryption. [O4]

## A36 Add the missing model operation guidance
**Location:** User Manual P1094–P1106 and the missing Super Administrator/model review coverage.

Explain how Inventory Manager reads training blockers, history coverage, forecast period, buying basis, and exception next steps. Add Administrator guidance for requesting generation and reviewing an eligible model’s operational approval. Add Super Administrator guidance for controlled Store history import, training/configuration, technical review, and job diagnostics. A user should be able to distinguish “job succeeded” from “model approved.” [F1–F4, O2]

## A37 Remove remaining procurement and role confusion
**Location:** Chapter 4 narrative P0509–P0600; manual P1064–P1091; Appendix B T022.

Use Replenishment Recommendations and Direct Stock Receiving. Keep Supplier Pullouts as the implemented batch-removal workflow; retiring supplier administration does not remove that distinct stock-control function. “Incoming supplier deliveries” should not imply a purchase-order or supplier-master prerequisite. Cashier reports a Stock Issue; Inventory Manager reviews it. Pending submission does not immediately deduct stock. [O1–O2]

## A38 Correct financial and receipt explanations
**Location:** Products caption P0457; T022; P0591 and P0596.

Selling price minus current unit cost is a unit margin estimate, not realized period Gross Profit. Gross Profit depends on Net Sales and corresponding sold-product costs, with returns and cost-completeness status considered; it is not net income. Preserve linked refunds/corrections and original-receipt retention. Reprint must not repeat checkout. Retain package/Base Unit distinctions in receiving, stock, sales, and forecasts. [O1, O7]

[[PAGE]]
# Evidence needed before final submission
## A39 Separate the three kinds of evidence
**Public benchmark:** Retain source subset, product selection, original dates, split definitions, code revision, feature/model recipe, runtime versions, predictions, and metric definitions. Benchmark scores do not establish Shalom Store accuracy.

**Isolated system verification:** Retain queue/worker logs, test database boundary, candidate outcomes, historical preview labels, unchanged stock, and observation-count checks. A successful isolated test does not establish installed unattended Store operation. [F7]

**Store deployment and acceptance:** Retain the actual host specification, configuration, dated startup/signed-out restart test, scheduler/worker evidence, Store user tasks, device/printing checks, and signed or otherwise attributable acceptance results. The retained 3 November target-acceptance report marks the operational target not accepted; do not overwrite that finding with a success claim without new evidence. [F8]

## A40 Supply the study records
**Location:** P0044–P0045, P0230, P0246–P0249, P0272–P0273, P0366–P0368, P0378, P0499, P0642–P0643.

Attach or cite dated interviews/observations for the pre-system process and Store volumes. Retain sprint/review records for claimed Agile Scrum work. Distinguish scheduled, completed, and pending activities in the implementation plan. Do not infer consultation, user acceptance, deployment completion, or reduced stockouts from screenshots and code.

Chapter 4 currently supplies blank role questionnaires rather than a complete UAT results analysis. Add the actual respondent counts by role, evaluation dates, tasks performed, consent/approval records as required by the school, valid response counts, item/category summaries, comments, defects, and retests. Do not invent respondents, scores, signatures, or favorable outcomes.

## A41 Record current installation facts
Record the actual Store PC CPU, RAM, storage, OS, PHP/database/Python versions, browser/device sizes, peripherals, network setup, and dataset size used in acceptance. Separate minimum requirements from tested configuration. Retain the configured forecasting schedule and current limits; defaults are not evidence that the scheduled task is registered or running.

## A42 Limit claims to measured outcomes
Stockout reduction, improved profitability, faster work, forecasting usefulness, mobile printing, scalability, and security need suitable measurements or technical evidence. Until those exist, describe implemented support or intended benefits rather than proven improvement. Preserve limitations about stockout-censored demand, incomplete costs, incomplete observations, and benchmark transferability.

The agreed UI changes also need desktop/mobile and keyboard checks, readable reason messages, accessible status labels, and a truthful 140-day progress display. Do not create a countdown-to-activation promise or replace operational Store data with M5 examples for the defense.

[[PAGE]]
# Editorial corrections and completion checklist
## A43 Repair numbering after substantive edits
**Locations:** P0134, P0359, P0376–P0393, P0480–P0500, P0546–P0609, P0619–P0626.

- Change duplicated Chapter 2 “2.1 Related System” to the correct next section number.
- Reconcile duplicated 3.4.2 training/testing headings and 3.4.2.2 forecast/beta headings.
- Remove duplicated 3.6 Implementation Plan headings; restore a sequential structure.
- Fix Chapter 4’s “1.12 Stock Reporting” and repeated/misordered subsection numbers.
- Renumber Figure 22A/B/C in reading order after the other prototype figures; fix duplicate Figures 29, 30, and 34.
- Renumber reused Tables 12/13 and the skipped Table 8; repair every in-text reference.
- Regenerate the contents, figure list, table list, and captions together. Use Word captions and cross-references where practical.

## A44 Repair the bibliography and third party claims
**Location:** P0653–P0696; especially P0696’s Zavaleta-Zarate reference.

The cited inventory paper belongs to Logistics, not Systems. The publisher record is “Optimizing Inventory in Convenience Stores to Maximize ROI Using Random Forest and Genetic Algorithms,” Logistics 10(3), 64, DOI 10.3390/logistics10030064. Verify and copy its complete author names and metadata from the publisher. The manuscript’s systems10030064 DOI points to a different subject. [E5]

Reconcile the Sekhar/Veluru year/author duplication sharing a ResearchGate identifier. Check whether Makridakis 2020 cited in the prose has a corresponding reference distinct from the 2022 M5 paper. Add the chosen ISO edition and proper M5 dataset provenance. Audit every in-text citation against the bibliography and read the source before retaining its claims. Do not claim a full bibliography audit has already been completed.

## A45 Apply consistent names and final proofreading
**Location:** Whole manuscript and forms, including T002, P0093, P0431, T016, P0757–P0758.

Fix “Retail” to RetailMind, “supports supports,” “Ranoverall,” and “Rage of Weighted Mean.” Standardize Administrator, Super Administrator, Inventory Manager, Cashier, Store, and Base Units. Check the approved title, dates, institution/name spelling, and appendix sequence against school records. Do not alter signed approval forms without the appropriate author/adviser process.

## Final checks
Confirm every 56-day reference describes history coverage rather than activation; every 140-day reference includes the other checks; every exception description covers missing usable forecasts; all tables preserve metric populations; and every screenshot matches the implemented version. Re-capture Figures 22, 22A, 22B, and 22C after the UI work. Reconcile Chapter 3 plans with Chapter 4 actual results and Chapter 5 conclusions. Check that the manual follows current backup and role rules. Complete the guide’s evidence tasks before making completed-deployment or effectiveness claims.

[[PAGE]]
# Technical evidence index
Paths below are relative to C:\xampp\htdocs\retailmind unless an absolute path is shown. Current implementation and newer superseding decisions take precedence over older conflicting prose. Keep the referenced version with the revised manuscript.

## Forecasting sources
**F1 Training and history rules**
src/backend/app/Forecasting/StoreTrainingEligibilityService.php — constants, policy, evidence counts, recent window, chronological coverage.
src/backend/app/Forecasting/DemandHistoryService.php — getReadinessSummary and history bands.
src/frontend/components/report/training_eligibility_policy.php — current explanatory policy text.

**F2 Candidate qualification and approval**
src/backend/app/Forecasting/CandidateEvaluationService.php — per-horizon limits and baseline checks.
src/backend/app/Forecasting/StoreModelGovernanceService.php — observation, technical rejection, operational approval, and usability status.
docs/adr/0022-local-administrator-forecast-model-approval.md.

**F3 Forecast usability and purchasing**
src/backend/app/Forecasting/ReplenishmentQueueService.php — forecast usability, independent stock-rule quantity, recommended quantity, exclusions.
src/backend/app/Forecasting/DemandForecastService.php — exception product selection.
src/frontend/components/inventory_management/replenishment_queue.php and src/frontend/components/report/forecast_exceptions.php — current displayed values and scope.

**F4 Data isolation and job integration**
docs/adr/0005-single-random-forest-forecasting-pipeline.md.
src/backend/app/Services/ForecastHistoryImportService.php.
src/backend/app/Services/MlJobService.php; src/backend/scripts/ml_worker.py; src/backend/scripts/run_scheduler.php.

**F5 Feature contract and experiment implementation**
src/backend/scripts/feature_pipeline.py — FEATURE_COLUMNS, MODEL_PARAMETERS, origin-aware feature construction and encoding.
src/backend/scripts/ml_evaluation.py — metric formulas and weekly baseline.
The repository experiment script m5_sales_experiment.py and retained experiment metadata describe the public-data comparison.

**F6 Historical research results**
docs/research/m5-expansion-results-20261028.md — original comparisons, interpretation, local execution, and inspected-final-window limitation.

**F7 Retained November benchmark verification**
docs/verification/m5-421-training-20261103.md — rechecked 4 November; actual candidate run, failed gates, historical comparison, and observation boundary.
C:\Users\Mike\AppData\Local\retailmind-demos\m5-421-20261103\experiment-50 — metrics.json, scores.csv, predictions, and source provenance. Retain run-specific versions rather than copying notebook installation targets.

**F8 Scheduling and target acceptance**
src/backend/app/Forecasting/ForecastingSchedulerService.php.
src/backend/scripts/install_forecasting_scheduler.ps1.
docs/verification/forecasting-target-acceptance-20261103.md and forecasting-integrated-validation-20261103.md.

[[PAGE]]
# Operational and external evidence index
## Operational sources
**O1 Domain language and receiving**
GLOSSARY.md; docs/adr/0011-retire-development-procurement.md. These define recommendation, Forecast Quantity, receiving, stockout, cost, and financial distinctions.

**O2 Workspace authority**
src/backend/app/Authorization/RoleCapabilityPolicy.php; docs/adr/0001-single-store-administrator-boundaries.md. Check local and remote contexts separately.

**O3 Notification implementation**
src/backend/includes/functions.php — email delivery configuration and transports.
src/backend/app/Services/NotificationService.php — notification recipients and conditions. Verify the selected live configuration before claiming delivery.

**O4 Current backup and restore decisions**
docs/adr/0023-password-authorized-backup-restoration.md and docs/adr/0024-administrator-self-authorized-restore.md. ADR 0024 supersedes older separate technical-approval/key-custody boundaries. Also inspect current backup restore and recovery-key forms under src/frontend/components/backup/ and the backup services.

**O5 Local and remote access**
docs/adr/0015-local-windows-host-with-private-remote-access.md; docs/adr/0019-owner-only-remote-monitoring-through-cloudflare.md. ADR 0019 revises the earlier remote-staff design.

**O6 Physical printing**
docs/adr/0018-tablet-receipts-through-store-pc.md. Pair the design with actual device acceptance evidence before claiming hardware success.

**O7 Schema and migrations**
src/backend/database/sql/schema.sql and src/backend/database/migrations/. Use the effective schema version for dictionary and ERD work, with keys and field names verified rather than inferred from page labels.

## External reference checks
**E1 ISO 2011 product quality model**
https://www.iso.org/standard/35733.html
Eight product-quality characteristics; explicitly identify the historical edition if it remains the approved study basis.

**E2 ISO 2023 product quality model**
https://committee.iso.org/cms/live/live/en/sites/isoorg/contents/data/standard/07/81/78176.html
Nine product-quality characteristics; the current questionnaire cannot be relabelled as this edition without revision.

**E3 Oracle NetSuite demand planning**
https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2286970.html

**E4 Zoho product scope checks**
https://www.zoho.com/en-fr/inventory/kb/reports/stock-runout-forecast.html
https://www.zoho.com/analytics/help/chart/forecasting.html
Distinguish Inventory reports, Analytics capabilities, and optional integrations.

**E5 Correct inventory research publisher record**
https://www.mdpi.com/2305-6290/10/3/64
DOI 10.3390/logistics10030064. External records checked 4 November 2026.
