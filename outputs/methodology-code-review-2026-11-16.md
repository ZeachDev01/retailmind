# Pagtandi sa methodology ug RetailMind implementation

Gisusi niadtong November 16, 2026, Asia/Manila.

**Resulta: kadaghanan sa detalyadong ML methodology tugma, apan dili pa consistent ang tibuok Chapter III.** Ang unang requirements, architecture, data dictionary, ug testing narrative adunay karaang claims. Aduna sab implementation gaps nga dili masulbad pinaagi lang sa pag-ilis sa wording.

Dokumento: `C:/Users/Mike/Documents/Capstone 2 Docu/Capstone 2 - RetailMind revised (Updated).docx`.

Read-only ang pagsusi sa DOCX ug application source. Kini nga report ra ang bag-ong output. Ang section titles ug table labels maoy document locators; wala magtagna og page numbers. Wala gisusi ang mga embedded diagram ug screenshots sa biswal nga paagi.

## Mga kinahanglan ipahiuyon

### 1 Category ug event predictors dili apil sa current model

**Document locations:** 3.1.1 Research Design; 3.2.2 Functional Requirements; 3.3.1 System Architecture; 3.4.1 Development.

Giingon niini nga ang product category, holidays, fiestas, ug back-to-school events mahimong predictors, ug awtomatikong makuha gikan sa product/calendar data. Apan ang 3.4.2.3 Feature Engineering mismo nag-ingon nga wala kini giapil. Mao kini ang tugma sa code.

Ang aktuwal nga 14 fields: `product_id`, `horizon_day`, `lag_1`, `lag_7`, `lag_14`, `lag_28`, `rolling_mean_7`, `rolling_std_7`, `rolling_mean_28`, `rolling_std_28`, `day_of_week`, `day_of_month`, `month`, `is_weekend`.

**Angay usbon sa doc:** ipareho ang unang mga seksyon sa 3.4.2.3. Lahi ang calendar nga gigamit sa pag-ila sa operating days/closures ug ang event indicator nga direktang predictor sa model. Ayaw iangkon nga naa nay holiday/fiesta/back-to-school predictor integration.

Code: [feature_pipeline.py:13](../src/backend/scripts/feature_pipeline.py#L13), [ml_worker.py:332](../src/backend/scripts/ml_worker.py#L332).

### 2 Data dictionary dili kasaligan isip current physical schema

**Document location:** 3.3.5 Entity Relationship Diagram, Table 5 Data Dictionary.

| Naa sa doc | Naa sa current canonical schema |
|---|---|
| `users.full_name` | `first_name`, `last_name`, `display_name` |
| `inventory.quantity` | `quantity_on_hand` |
| `sales_items.sales_item_id`, `price` | `sale_items.sale_item_id`, `unit_price` |
| `demand_forecasts.forecast_period`, `predicted_demand`, `generated_at` | `forecast_origin`, `target_date`, `horizon_day`, `predicted_units`, `model_version`, `created_at` |
| `audit_logs.log_date` | `activity_log.created_at` |
| `fiscal_periods.fiscal_id` | `period_id` |
| `system_settings` | Nagbulag nga `platform_settings`, `store_settings`, ug `ml_settings` |

Wala sab makita isip current tables ang `replenishment_requests`, `reports`, ug `damage_reports` sa canonical schema. Dili kini pasabot nga walay reporting o stock-issue features; lahi ang ilang implementation/storage.

**Angay usbon sa doc:** ibase ang dictionary sa current schema ug migrations. Kon conceptual design ra kini, klaroha ang label; ayaw ipresentar nga mao ang physical database implementation. Ilisi sab ang “Encrypted password” og “Hashed password” alang sa `password_hash`.

Code: [schema.sql:38](../src/backend/database/sql/schema.sql#L38), [schema.sql:498](../src/backend/database/sql/schema.sql#L498), [schema.sql:659](../src/backend/database/sql/schema.sql#L659), [schema.sql:1207](../src/backend/database/sql/schema.sql#L1207), [schema.sql:1567](../src/backend/database/sql/schema.sql#L1567), [schema.sql:1985](../src/backend/database/sql/schema.sql#L1985).

### 3 Roles inconsistent sulod sa methodology

**Document locations:** 3.3.1 System Architecture; Context Diagram description; Data Flow Diagram description; Use Case Diagram description; Beta Testing questionnaire.

Tulo ra ka actors ang narrative: System Administrator, Inventory Manager, Cashier. Apan ang 3.2.2 ug 3.4.5 naghulagway og upat: Super Administrator, Administrator, Inventory Manager, Cashier. Upat sab ang role routing sa code. Nagbulag ang technical platform governance ug Store operations/approval.

**Angay usbon sa doc:** klarohon ang upat ka actors ug ilang katungdanan sa narratives ug questionnaires. Susihon sab ang corresponding diagrams; wala pa sila ma-visual-verify niini nga review.

Code: [RoleWorkspaceRouter.php:9](../src/backend/app/Authorization/RoleWorkspaceRouter.php#L9), [RoleCapabilityPolicy.php](../src/backend/app/Authorization/RoleCapabilityPolicy.php).

### 4 Notification provider ug delivery timing karaan ang paghulagway

**Document locations:** Table 4 Software Requirements, Notification Integration; 3.2.2 Functional Requirements.

Ang doc naglista og EmailJS, Twilio, o Gmail SMTP ug real-time SMS/email. Ang current shared email boundary naggamit og **Brevo API**. Ang low-stock in-app checks naa sa transaction workflows, samtang ang stock email giusa sa **Daily Stock Brief** pinaagi sa scheduled notification run. Nagdepende ang email sa configuration, enabled delivery, ug recipient preferences.

Adunay forecast-spike notification code, apan nagbasa kini sa legacy `stock_predictions`. Ang current worker nagsulat sa `demand_forecasts`; wala makita ang pagsulat niini sa `stock_predictions`. Busa ang presensya sa notification function dili pa pamatuod nga konektado ang significant-demand-change emails sa current forecasting pipeline.

**Angay usbon sa doc:** hinganli ang Brevo API, ilain ang in-app alerts ug daily email brief, ug ayaw iangkon ang real-time SMS o verified forecast-change email integration kon walay supporting end-to-end evidence.

Code: [functions.php:415](../src/backend/includes/functions.php#L415), [run_notifications.php:45](../src/backend/scripts/run_notifications.php#L45), [NotificationService.php:141](../src/backend/app/Services/NotificationService.php#L141), [StockBriefService.php:201](../src/backend/app/Services/StockBriefService.php#L201), [ml_worker.py:357](../src/backend/scripts/ml_worker.py#L357).

### 5 Beta Testing nahuman ug pending dungan sa doc

**Document locations:** 3.4.3.1 Alpha Testing; 3.4.3.2 Beta Testing; 3.4.4 Data Analysis Plan; 3.5 Implementation Plan.

Ang unang testing paragraphs nag-ingon nga nahuman na ang Alpha ug Beta Testing, ug nakolekta/nagamit na ang user feedback. Apan ang Data Analysis Plan naghulat pa sa beta testing, ug ang Implementation Plan nag-ingon nga pending kini as of November 13, 2026.

**Angay usbon sa doc:** ipareho sa aktuwal nga status. Kon pending gihapon, gamita ang future tense sa Beta Testing ug ipresentar ang questionnaires isip evaluation instruments. Kon nahuman na human sa November 13, kinahanglan ang actual dates, respondent counts, responses, ug analysis. Dili mapamatud-an sa source code ang client participation o Scrum ceremonies.

### 6 Colab ug local pipeline dili na eksaktong pareho

**Document locations:** 3.4.2 introduction; 3.4.2.6 Forecasting Integration into RetailMind, claims bahin sa shared pipeline.

Ang current notebook adunay bundled copy sa forecasting scripts. Napakyas ang existing `test_colab_bundles_exact_shared_source`: lahi ang bundled `feature_pipeline.py` ug current local file. Ang failure nagpakita sa mas bag-ong closure-aware feature-window handling sa local implementation.

**Pasabot:** tugma ang core approach, feature names, ug model settings, apan dili husto nga isipon silang identical current implementations. Kini nga mismatch dili awtomatikong nagpasabot nga sayop ang M5 numerical results; ang kompletong public daily dataset ug Store closures adunay lahing conditions.

**Angay buhaton:** i-version ang gigamit nga experimental pipeline, o i-sync ang notebook ug rerun ang relevant checks aron masuportahan ang exact-parity claim. Dili kinahanglan i-relabel ang historical result isip bag-ong independent experiment.

Code: [test_m5_sales_experiment.py:85](../src/backend/tests/test_m5_sales_experiment.py#L85), [m5_random_forest_colab.ipynb](../docs/research/m5_random_forest_colab.ipynb), [feature_pipeline.py:71](../src/backend/scripts/feature_pipeline.py#L71).

### 7 Scheduler verification wala makita isip activation gate

**Document location:** 3.4.2.7 Operational Safeguards, paragraph nga nag-require sa scheduler verification.

Ang `validateActivationReadiness()` nagsusi sa quality gates, observation, reviews, demo isolation, ug artifact compatibility. Ang `activateStoreModel()` nag-require sa local Administrator ug nag-queue sa forecast job. Wala makita nga verified scheduler installation/status usa ka mandatory gate sa activation path. Ang runtime check nagtan-aw sa OS ug writable directories; dili kini scheduler-installation verification.

**Angay usbon sa doc:** ipresentar ang scheduler verification isip deployment procedure nga kinahanglan og lahing evidence, gawas kon tinuyo nga idugang kini isip enforced activation gate. Naa ang scheduler implementation; ang wala napamatud-an mao ang giangkon nga mandatory verification gate.

Code: [StoreModelGovernanceService.php:420](../src/backend/app/Forecasting/StoreModelGovernanceService.php#L420), [StoreModelGovernanceService.php:525](../src/backend/app/Forecasting/StoreModelGovernanceService.php#L525), [StoreModelGovernanceService.php:794](../src/backend/app/Forecasting/StoreModelGovernanceService.php#L794).

### 8 Undefined WAPE adunay operational exception

**Document locations:** 3.4.2.5 Validation and Forecast Evaluation; Table 13 Forecast Error Metrics and Interpretation.

Tugma ang benchmark/shared metric helper: undefined ang WAPE kon zero ang total actual sales. Apan ang prospective-observation calculation nag-ilis sa null ngadto sa `0.0`, ug nagkalkula sa iyang metrics gikan sa product-day forecast/actual pairs imbes sa parehas nga product-horizon totals sa benchmark.

**Angay buhaton:** limitahi ang methodology statement sa benchmark/candidate quantity evaluation, ug idokumento ang lahing observation-progress calculation; o ipahiuyon ang code kon parehas nga metric semantics ang gitinguha. Ayaw isagol ang observation metrics ug benchmark metrics isip parehas nga ebidensiya.

Code: [ml_evaluation.py:42](../src/backend/scripts/ml_evaluation.py#L42), [CandidateEvaluationService.php:126](../src/backend/app/Forecasting/CandidateEvaluationService.php#L126), [CandidateEvaluationService.php:231](../src/backend/app/Forecasting/CandidateEvaluationService.php#L231).

## Mga tugma na

| Methodology claim | Nakita sa implementation |
|---|---|
| PHP web app, Python worker, database jobs ug stored forecasts | Naa ang worker, database history export, artifact validation, ug forecast persistence |
| Global Random Forest, product one-hot encoding | Shared `ColumnTransformer`, `OneHotEncoder`, ug `RandomForestRegressor` |
| 14 predictors; 15 trees; depth 8; seed 42 | Eksaktong naa sa `FEATURE_COLUMNS` ug `MODEL_PARAMETERS` |
| M5 CA_1 FOODS; seeded 20-to-50 sample; training-only eligibility | Naa sa `prepare_history()`; explicit `--products 50` ang 50-product script run |
| Weekly training origins ug three chronological 28-day blocks | Naa sa training/evaluation code; full target window anaa na sa training cutoff |
| Seasonal Naive; MAE/RMSE/WAPE sa 7/14/28-day product totals | Naa sa public benchmark evaluation |
| 140 accumulated usable days ug supported feature/evaluation windows | Naa sa fixed Store training eligibility policy |
| Readiness bands dili automatic training/approval | Naa ang separate readiness, training, quality, observation, ug approval checks |
| Daily nonnegative whole-unit operational forecasts | Naa ang worker rounding sa saved quantities |
| Forecast-based suggestions o clearly identified stock-rule fallback | Naa sa Replenishment Queue; walay automatic purchasing/stock mutation niini |

Supporting code: [feature_pipeline.py:13](../src/backend/scripts/feature_pipeline.py#L13), [feature_pipeline.py:226](../src/backend/scripts/feature_pipeline.py#L226), [m5_sales_experiment.py:23](../src/backend/scripts/m5_sales_experiment.py#L23), [m5_sales_experiment.py:78](../src/backend/scripts/m5_sales_experiment.py#L78), [StoreTrainingEligibilityService.php:13](../src/backend/app/Forecasting/StoreTrainingEligibilityService.php#L13), [forecast_readiness_bands.json](../src/backend/config/forecast_readiness_bands.json), [ReplenishmentQueueService.php:97](../src/backend/app/Forecasting/ReplenishmentQueueService.php#L97).

## Verification nga gipadagan

| Existing check | Resulta |
|---|---|
| `test_feature_pipeline.py` | 4 checks passed, apil closure-aware features |
| `test_m5_sales_experiment.py` | 5 passed, 1 failed: Colab bundled-source parity |
| `store_training_eligibility_test.php` | Passed |
| `forecast_metrics_contract.php` | Passed |
| `store_model_review_approval_contract.php` | Incomplete: mihunong sa Section 11, `SQLSTATE[HY000]: General error: 1 no such table: product_batches` |

Ang approval-test failure usa ka limitasyon sa verification; dili kini igo aron moingon nga napakyas ang live Store workflow. Wala giusab ang fixture o application code tungod kay consistency review ang request.

## Mga wala mapamatud-an niini nga review

Wala gi-rerun ang tibuok 50-product M5 experiment, Google Colab session, live Store database workflow, actual email delivery, printer/scanner hardware, o client beta testing. Ang archived local M5 expansion report adunay parehas nga all-50-product WAPE figures nga gihisgotan sa doc, apan dili kini bag-ong independent result verification: [archived report](../docs/research/m5-expansion-results-20261028.md).

Ang Agile Scrum execution, stakeholder interviews, 1–3-second response-time target, hardware capacity, client deployment, ug actual reduction sa stock-outs kinahanglan og process records o measured operational evidence. Dili sila mapamatud-an sa source code lamang.

**Prayoridad:** ayuhon una ang predictor claims, physical data dictionary, role descriptions, ug Beta Testing tense. Dayon i-resolve ang notebook parity, scheduler-gate wording, notification integration, ug observation-metric exception aron depensable ang methodology.
