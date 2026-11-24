---
version: 1
slug: "forecast-workspace"
primary_target: "src/frontend/components/report/predictions.php"
related_targets: ["src/frontend/components/report/data_readiness.php", "src/frontend/components/report/forecast_exceptions.php", "src/frontend/components/report/forecast_analytics.php", "src/frontend/components/report/forecast_replay.php", "src/frontend/components/administrator/model_review.php", "src/frontend/components/inventory_management/product_investigation.php", "src/frontend/components/inventory_management/replenishment_queue.php", "src/frontend/components/system_administrator/ml_settings.php", "src/frontend/components/system_administrator/training_configuration.php", "src/frontend/components/system_administrator/forecast_import.php", "src/frontend/assets/css/forecast-workspace.css"]
---

# Forecast workspace

Mode: Operate. Confirmed with the user on 2026-11-03 through grill-with-docs: simplify all related forecast, replenishment and model-management screens around expected sales, stock on hand and required actions. Preserve calculations, actions and role permissions. Use charts and short labels, with supporting explanations in accessible tooltips or disclosures.

## Direction

Retain RetailMind's navy shell, action blue, theme-aware neutral surfaces and system typography. Show one compact coverage/status strip and concise issue rows. Product forecasts and dates precede technical performance. Remove duplicate replenishment navigation. Compare on-hand stock with seven-day expected sales for the first five usable products matching the search; the full table retains 7/14/28-day totals. This is a sample of the displayed products, not a ranking or catalog aggregate.

Product investigation shows the saved 28-day daily forecast with exact values in a disclosure. Analytics opens on actual-versus-predicted sales; model checks and prediction logs are secondary. Replay retains its experimental warning and uses separate paired bars and exact values. Missing evidence produces an empty state, never invented sample scores or zero sales. Historical/demo data does not become current approved Store guidance through presentation.

Reuse the replenishment queue's help interaction across the workspace. Help opens on hover, focus and touch, dismisses with Escape, and stays within the viewport outside table scroll clipping. Native disclosures retain keyboard and JavaScript-free access. Settings errors open the parameter disclosure automatically. Approval, rejection, pause, resume, rollback and training remain authorized server actions.

Existing glossary language applies: Forecast Quantity describes expected sales; Replenishment Recommendation describes suggested buying quantities. WAPE remains error, not accuracy. No new domain terms or ADR are needed for this reversible presentation change.

## Verification

Focused browser coverage uses a disposable database: Administrator, Inventory Manager and Super Administrator; empty and usable forecasts; search; default disclosure state; stock and demand totals; daily charts; settings help; historical replay; 320/390/640/1440px layouts and both main-page themes. Model-review component checks cover approval, rejection availability, pause/resume, rollback, local-only authorization and CSRF. Chart checks cover a common scale, zero demand, missing forecasts, escaped names and the five-product limit. Shared tooltip checks cover Escape, reopening and viewport placement. PHP syntax and diff whitespace checks are required.

Broader legacy checks have fixture blockers: forecast_page_stock_contract.php omits ReplenishmentQueueService; forecast_decision_browser_test.js references the removed branches table. These are outside the UI change. Full theme-account suite was not rerun; its chart assertions now accept honest empty evidence states.

## Super Administrator refinement — 2026-11-04

All six Forecasting navigation pages and historical replay keep the existing Operate mode and visual system. Navigation now matches Model Training and Forecast Analytics page titles; imports are titled Import Sales History. Readiness and exceptions use compact status rows. Candidate metadata, training policy, model recipe, parameters and run history use native disclosures. Training and import consequences and experimental warnings remain visible. All routes, actions, calculations and authorization remain intact; duplicate navigation and decorative import button icons were removed.

Verification: forecast landing browser checks cover all six Super Administrator routes in light/dark themes at 320, 390, 640 and 1440px, plus historical replay and shared Administrator/Inventory Manager paths. Import browser checks cover validation, preview, duplicates, settings, role denial and stock/receipt preservation. Forecast navigation contract and changed PHP syntax checks pass. Screenshots are saved under `tmp/manual/superadmin-forecasting/` for local visual review. Full forecasting/model-training and public-data replay suites were not rerun; this change does not alter their calculations.

## Role-first workflow - 2026-11-04

The user confirmed role-specific work and a clear next action, short instructions with expandable explanations, visible essential warnings, and simple English. Replace the persistent five-step navigation with a collapsed "View workflow" disclosure. It describes responsibility, not completion: the Super Administrator prepares data and training, the Administrator reviews models and Store forecasts, and the Inventory Manager reviews expected sales and buying guidance. Each workflow step identifies its owner. Scheduled recurring work is distinct from occasional setup and approval.

Status summaries lead with the real state and next action. Supporting status explanations, model identities, readiness checks and secondary destinations use native disclosures. Preserve historical/demo warnings, paused/unapproved guidance, import consequences, validation and authorization. Existing domain definitions apply; no glossary term or ADR is needed for this reversible presentation change.

### Implementation and validation

The shared workflow now starts with the signed-in role's work and keeps the owner-labelled steps inside a collapsed native disclosure. Status panels show the real state, next action and permitted destination first; supporting reasons and secondary tools remain expandable. Readiness details and model evidence are secondary, approved models lead to current forecasts, and healthy forecasts no longer ask for corrective work. Forecast checks open automatically for critical issues. Historical/demo, observation, paused-guidance and import-consequence warnings stay visible. Existing navy/blue tokens and shared controls are reused; no visual-system change or new domain term was needed.

Passed: `forecast_landing_browser_test.js` with `RUN_DB_TESTS=1` against a disposable database (three roles; 320/390/640/1440px; all six Super Administrator routes in light/dark; workflow keyboard operation, collapsed state and owners; schedule saving; product charts and replay), `forecast_import_browser_test.js` (upload, preview, import, duplicates, role denial and stock/receipt preservation), `administrator_model_review_component_test.php` (approval, rejection, pause/resume, rollback, CSRF, local authorization and approved-state next action), `forecast_navigation_contract.php`, `forecast_landing_page_contract_test.php`, changed PHP syntax and diff whitespace checks.

Screenshots: `.impeccable/review/forecast-role-workflow/`. Final finish review: Ship after inspection of eight refreshed desktop/mobile captures affected by the approved-state, healthy-action, supporting-check and disclosure-wrapper fixes. This verdict covers the reviewed presentation changes. The full ML training/backtest suite was not rerun. `docs/DESIGN.md` remains the incumbent system; `.impeccable/design.json` was already absent. Neither was changed or regenerated. Existing legacy encoding in this surface brief was preserved.

## Visual Super Administrator workspace - 2026-11-04

Refinement requested for the defense: reduce text density, add meaningful visuals, and move supporting explanations into tooltips. Super Administrator pages now share six icon-based destinations with one current-page indicator; these indicate navigation, not completion. The short process caption explains data to model to forecast, with approval responsibility in keyboard/touch help. Other roles retain their existing collapsed workflow.

Data Readiness exposes three actual product-check counts with meters. Import uses a three-step sequence and a native styled CSV picker. Status explanations, import scope, candidate-training scope and chart descriptions reuse the existing accessible tooltip; next actions, errors and operational warnings remain visible. Exceptions adds an actual coverage meter. Motion is limited to navigation arrival and disabled under reduced-motion preferences. No dependencies, forecast calculations or authorization changes.

Verification: PHP syntax, navigation and landing contracts, the disposable-database import browser suite, and the landing browser suite covering all six pages at 320/390/640/1440px in light/dark themes. Added checks for current-page navigation, keyboard tooltip dismissal, readiness meters and active-tab text contrast. Screenshots: `tmp/manual/superadmin-forecasting-refresh/`. Visual review caught and corrected dark-theme active-tab contrast. Full model training/backtest suite not rerun for these presentation changes.

## Compact readiness catalog - 2026-11-04

Follow-up: dense product rows replace repeated reason paragraphs and two disclosures with a short status plus tooltip, a usable-day count/meter, and one Details disclosure with recent/testing icons. Full dates, blockers, actions and history evidence remain available on demand. Row padding is reduced and pagination increases from 15 to 25 products. Desktop screenshot review shows a compact two-line product row; the browser regression asserts a closed row height at most 80px and keyboard access to details and help. Existing light/dark and mobile checks remain in place. Calculations and eligibility policy are unchanged.

## Analytics empty states - 2026-11-05

Refined all five analytics tabs and the supporting Store performance, historical checks and prediction-log empty states. Reused the existing shared renderer, icons and theme tokens with layered geometric shapes, concise subtext and the existing next-action links. Shapes assemble once in 650-900ms and remain still under reduced motion or print. Missing evidence remains unavailable; no forecast logic or authorization changed.

Verification: PHP syntax, diff whitespace and the disposable-database forecast landing browser suite passed. Coverage includes all five empty tabs at 320/390/1440px in both themes, short subtext, decorative accessibility, finite animation and reduced motion. Inspected desktop/mobile empty-state captures and the dark mobile page; screenshots are in `tmp/forecast-empty-refresh/`. Full ML training/backtest suite was not rerun for this presentation change.

## Compact forecast checks - 2026-11-05

Demand Forecast checks now use short issue titles, semantic status icons, one owner line and aligned existing actions. The review count is secondary. Full conditions and effects remain in the shared keyboard/touch help; critical issues still open the disclosure automatically. Actions, roles and forecast calculations are preserved.

Verification: PHP syntax, diff whitespace and the disposable-database forecast landing browser suite passed. Added concise-title, sentence-badge removal and keyboard-help checks; open checks fit 320/390/640/1440px in both themes. Inspected desktop/mobile light/dark section captures in `tmp/forecast-checks-refresh/`. Live localhost inspection ended because Computer Use could not verify the current browser URL; visual checks used the rendered test application. Full ML training/backtest suite was not rerun.
## Compact workspace tabs - 2026-11-10

Every forecast page now opens with one compact in-page tab strip instead of the tall icon tiles: icon and label sit inline in a 44px control. The Super Administrator sees six destinations, the Administrator four, and the Inventory Manager now sees Demand forecast and Buying suggestions. Tabs scroll horizontally on narrow screens and centre the current destination; the active tab uses solid Action Blue with white text in both themes. Forecast Analytics uses the same segmented treatment for its five chart tabs, replacing the underlined tab bar.

Page subtitles were removed from all seven forecast pages. Supporting explanations moved to the existing keyboard/touch tooltips or native disclosures: the product forecast date range, Store performance, product coverage and readiness definitions. Data Readiness definitions now live in one disclosure instead of a visible help card. Forecast Exceptions uses the shared fact strip, drops ghost table rows and reflows its queue to labelled rows on mobile. Data Readiness uses the same mobile row layout.

Verification: `npm run check`, `npm run check:contracts`, `forecast_navigation_contract.php`, `forecast_landing_page_contract_test.php`, `forecast_analytics_contract.php` and the disposable-database `forecast_landing_browser_test.js` passed. Browser checks cover all six Super Administrator routes plus Administrator and Inventory Manager, 320/390/640/1440px, both themes, tab placement and active contrast. Screenshots: `tmp/forecast-redesign/`. Full ML training/backtest suite was not rerun; calculations, authorization and server actions are unchanged.


## Administrator Model Review direction - 2026-11-18

Confirmed through grill-with-docs. Lead with whether the Administrator has a model decision to make and show the next available action. When no Training Candidate is available, lead with **Check Data Readiness**. Use **Model under testing** while observation is in progress and **Ready for review** when the candidate is eligible for an Administrator decision. Both labels describe states of the existing Training Candidate. This records the design direction for a future change.

- **Review evidence.** Show which Products the selected Training Candidate actually covered and which were excluded, with reasons where recorded. Use **Coverage details unavailable** when the run has no coverage record. Keep current Product readiness on Data Readiness. Show observation progress and the plain-language reason for any blocker. Show a comparison with the approved Store model only when there is comparable evidence. Keep raw metrics in **View technical details**.
- **Administrator decision.** Put Product coverage and test results before the decision actions. Explain beside **Approve and activate** that it selects the model and queues a forecast update. Explain beside **Reject model** that the current setup stays in effect. State that these actions do not change stock or place orders. Show controls only when the existing authorization and action rules allow them.

Implementation shows training queue status and observation progress from saved evidence. A review starts with the 28-day error comparison. Technical metrics disclose WAPE, MAE, and RMSE by forecast horizon with keyboard and touch help. Product coverage uses the selected training run. Its search and difference sort cover all products, ten per page, with a single expandable chart. Product totals pair one forecast with recorded sales per usable date in the first 28 usable Store dates of observation. Approval, rejection, pause, resume and rollback stay on their existing local, audited flows.

Verification: Administrator Model Review component, approval and recovery contracts, forecast landing browser suite, and Playwright checks using 50 fixture Products. Checked chart expansion, search across all rows, difference sorting, five pages, metric period switching, keyboard tooltip dismissal and narrow layouts. The dedicated forecasting navigation contract still flags Store Calendar grouping and the Data Readiness link.


## Model Training arrangement - 2026-11-24

Confirmed through grill-with-docs: organize Model Training into Training overview, Training settings, How it works, and Training history. Supporting explanations use the existing keyboard/touch info tooltip, with Escape dismissal. Keep operational warnings, validation errors, actual status and next actions visible.

- Group the weekly schedule and Candidate Error Limits within Training settings; preserve separate forms and saves. Name WAPE controls as error limits rather than model parameters. Tooltips explain stricter/looser limits and distinguish training from daily Forecast Generation.
- How it works shows eligible Product coverage, the read-only Random Forest recipe and the approval sequence. Its eligibility tooltip reuses the effective policy rendered on Data Readiness; the full Product table lives on Data Readiness instead of being duplicated here.
- Training history shows actual recent job/run counts and two independent, initially collapsed tables. Jobs describe requests and diagnostics; runs describe execution statistics. Do not merge records or add filler rows.
- Preserve AM/PM, Asia/Manila, automatic opening on invalid quality settings, CSRF, field allowlists, existing role permissions and Demo/replay restrictions. Saving settings does not queue training or approve a model.

Reuse existing RetailMind tokens and native disclosures. No new visual system, dependency or ADR is needed. The glossary distinguishes Model Retraining, Forecast Generation and Candidate Error Limit.

Verification: npm run check, npm run check:contracts, PHP syntax and diff whitespace passed. The dedicated Administrator training settings browser test passed persistence, validation, role/CSRF/allowlist boundaries, atomic audit, independent histories, tooltip click/Escape and viewport bounds for Administrator/Super Administrator at 320/390/640/1440px in light/dark. The training-request browser test passed with its history selector updated. Local screenshots are in tmp/ml-settings-final/; desktop/mobile captures were visually inspected. TestSprite run 0a3d5b2e-1250-45e6-a955-9ec7df774caf passed all 25 steps against an isolated synthetic Store. The broader forecast landing suite stopped at the unrelated Analytics assertion that expected no trendChart (actual count 1), before its Model Training checks. No forecasting calculations or authorization changes were made.

## Separate Training Configuration direction - 2026-11-24

Confirmed through grill-with-docs. This direction supersedes the four-section Model Training arrangement above. Implemented below as separate training and configuration pages.

- **Model Training:** retain the header and existing forecasting navigation, Training overview, and Training history. Add a **Configure** link in the header to a separate **Training Configuration** page. Keep actual training status, scheduler health, readiness status, **Check Data Readiness**, training actions and essential warnings visible with the overview. Existing candidate and accuracy evidence remains available through its overview disclosures.
- **Training Configuration:** move the weekly retraining schedule and Candidate Error Limits here, preserving separate save actions. Move **How it works** here as an expandable guide for eligibility, the read-only recipe and the approval sequence. Provide a clear return link to Model Training. Saving configuration neither requests training nor approves a model; the daily forecast schedule remains read-only.
- **Training history:** replace the two independent collapsed history tables with **Training jobs** and **Recent training runs** tabs within this section only. Keep Training overview visible above both tabs. Open on Training jobs, retain each history's actual records and counts, and keep job requests distinct from completed-run evidence. Tab navigation must work with keyboard and without JavaScript; empty histories show an honest empty state.
- **Access and presentation:** both Administrator and Super Administrator retain access to operational training configuration. Use a shared forecasting destination rather than placing it exclusively in Platform Settings or Store Configuration. Preserve technical model permissions, CSRF, validation, independent saves, Asia/Manila and AM/PM controls, Demo/replay restrictions, and candidate-versus-approved distinctions. Retain RetailMind's existing visual system and responsive light/dark presentation. No new domain term or ADR is needed for this reversible page-structure change.

Implementation verification must cover both roles, direct unauthorized access, saving and invalid input on the new page, training/readiness actions on Model Training, both history tabs and their empty states, keyboard and JavaScript-free navigation, and narrow layouts in both themes. Update the existing training-settings, training-request and forecast-landing browser expectations for the new structure. Earlier verification above applies only to the existing arrangement.

### Separate-page implementation

Model Training now links to training_configuration.php through Configure. Its overview keeps readiness and the training action together; history uses Jobs / Runs query links and renders the selected table. Training Configuration owns the existing setting validation, independent atomic saves and audit attribution, with How it works collapsed by default. Shared segmented navigation styling is reused without sharing the workspace navigation selector; both full-page and fetch navigation retain Model Training as the parent destination. No dependency, model calculation or authorization changes.

Verification: npm run check, npm run check:contracts, PHP syntax and diff whitespace checks passed. The disposable-database Administrator training settings browser suite passed for both authorized roles, 320/390/640/1440px in light/dark, independent persistence, validation, CSRF and field allowlists, audit rollback, no training or approval side effects, history navigation, parent selection and keyboard/JavaScript-free links. The training-request browser suite and shared fetch-navigation browser suite passed. TestSprite run 33a27b18-c2e9-4674-8b58-21210e683d5e passed the separate-page flow on an isolated synthetic Store; inspected its recorded steps. Refreshed desktop/mobile captures in tmp/training-configuration/ were visually inspected. Landing-page contracts passed; the dedicated forecast navigation contract retains its two previously documented failures for Store Calendar grouping and the Data Readiness link.

The broader forecast landing browser rerun passed workspace switching, including the new training page, then stopped at the previously documented unrelated Analytics assertion: expected no trendChart, actual count 1. Its later route-specific checks remain unverified in that suite; the focused training suites above passed.

## Training readiness action clarity - 2026-11-24

Follow-up clarify/distill: the initial no-model status retains the top readiness action, renamed Check training readiness, with the next step Check which Products meet the training data requirements. Remove the duplicate overview readiness button while retaining its Product count and all blockers. Remove the repeated No candidate model yet card and its unused CSS because the top status already states that fact. Data Readiness remains available through forecast navigation in every state; configuration guidance keeps its contextual link. This supersedes the earlier instruction to keep a readiness action within the overview.

Verification: npm run check, PHP syntax, diff whitespace and the focused Administrator training settings browser suite passed. Both authorized roles, 320/390/640/1440px in light/dark, one top readiness action and its destination, no duplicate overview action/empty card, saves and validation, CSRF/role boundaries, histories and JavaScript-free access are covered. Desktop and mobile light/dark captures in tmp/training-readiness-clarity/ were visually inspected.

TestSprite run 11497ccf-75df-4aa5-8b49-7288a5942557 failed its final destination visibility assertion after recording a successful click. Its saved final HTML has base /components/report/data_readiness.php, title Forecast Data Readiness and the matching h1; the failure is not reported as a pass. The failure bundle was unavailable because the service reported that its environment had changed; inspected the run-scoped steps and downloaded HTML instead. The focused local browser check also exercises the actual link click, destination heading and URL. Full forecasting/ML suites were not rerun for this copy and duplicate-removal refinement.

## Training overview grouping - 2026-11-24

User-requested refinement: group the readiness verdict, ready Product count, short blocker explanation and training button in one action row. Place Latest training job and Automatic training in two aligned status columns beneath one divider; stack them on small screens. Move the full eligibility policy into the shared accessible info help. Preserve Demo/replay warnings, readiness rules, disabled/busy actions and candidate evidence. Remove the standalone Forecast analytics button; the existing Analytics workspace navigation remains available.

On Model Training only, render scheduler health as a short state and last-check timestamp without the Windows task/log instruction or diagnostic reference. The Model Review scheduler partial is unchanged. No scheduler logic, training eligibility, jobs or model approval rules change. Remove unused section-link CSS.

Verification: npm run check, PHP syntax and diff whitespace passed. Focused Administrator training settings and actual training-request browser suites passed; coverage includes blocked/queued/completed/failed jobs, both authorized roles, 320/390/640/1440px in light/dark, removed overview diagnostic copy/Analytics button and intact setting, history and permission behavior. Inspected refreshed desktop/mobile captures in tmp/training-overview-refresh/. TestSprite run aa78431a-3870-4190-881d-833a92981392 passed the overview layout and requirements-help checks; inspected its steps. Layout detector returned no findings, but also reported a PHP ENOENT on a file that exists, so its scan is not treated as full verification. No full forecast suite rerun for this presentation-only refinement.

## Forecast Analytics discussion - 2026-11-24

Confirmed through grill-with-docs; discovery remains open and UI implementation is not yet authorized.

- Lead with forecast-versus-actual sales. Each selected tab should present one main chart and its related table; historical checks and prediction logs belong in secondary tabs rather than competing with the primary view.
- Keep labels concise. Put supporting explanations in existing accessible info help or View details disclosures; keep essential warnings, results and next actions visible.
- Still to resolve: how historical Training Candidate evidence and subsequent Store sales evidence are selected, and how dense tables are presented. Preserve their distinct meanings and existing calculations.

### Analytics source and table decisions

Confirmed in the next interview round:
- Provide a clear Historical test / Store sales source selector. The visible results follow that selection; do not leave a historical section visible beside a Store-sales selection.
- Tables show ten rows per page with search and pagination. Show essential columns first and expose supplementary row details on demand.
- Default source, final tab grouping and initial comparison-table columns remain open. No UI implementation has started.

### Analytics final direction for confirmation

The user accepted the remaining source, grouping and column decisions. This completes the discovery choices above and supersedes their open-decision notes. Await final shared-understanding confirmation before UI implementation.

- Open on Historical test. Keep Historical test / Store sales selection visible and make all source-dependent charts, tables and result labels follow it. Model metadata and training-only measurements remain clearly identified; do not present them as Store sales evidence. Missing results show an honest empty state.
- Use four main tabs: Forecasts vs Sales, Product Errors, Model Details, Prediction Logs. Forecasts vs Sales is the initial tab. Product Errors groups the existing lowest/highest error views behind a selector. Model Details groups error trend, input influence and technical checks with explicit internal selection or disclosure so their contents do not compete at once. Prediction Logs contains the detailed records.
- The primary comparison table shows Product, Date, Forecast and Actual sales. Forecast origin and baseline remain available in row Details where recorded. Retain original evidence dates and values; distinguish otherwise similar observations by their disclosed origin rather than merging evidence silently. Use available Product identifiers when names are not recorded.
- Keep ten rows per page, search and pagination for record tables, and essential columns with on-demand supplementary details. One main chart and its related table per selected view where that evidence exists; do not invent charts or table data for views lacking them.
- Supporting prose uses existing accessible info help and View details. Essential warnings, results, source identity and next actions remain visible. Preserve the existing RetailMind visual system, responsive light/dark behavior, accessible keyboard/touch interaction, calculations, records, authorization and server actions.
- This is a reversible presentation change using existing domain meanings; no glossary addition or ADR is needed. Browser access was unavailable during discussion, so findings are source-based. UI implementation and runtime verification have not started.

## Direction contract - Analytics redesign

THESIS: One evidence source and one task at a time, replacing the competing historical block and chart collection.
OWN-WORLD: Inherit RetailMind navy shell, action-blue selection, neutral work surfaces, semantic forecast/sales series and system UI type. Retain light/dark preference for staff reviewing results in the Store.
STORY: Select the completed run and Historical test or Store sales; compare forecast and actual sales, inspect Product errors, then open model checks or logs as needed.
FIRST VIEWPORT: Existing shell and workspace navigation; aligned source/run controls; concise provenance and essential warning; four navigation tabs; one comparison chart followed by its four-column searchable table. Supporting metadata stays disclosed. The signature is the consistent Product/date/forecast/actual comparison rhythm.
FORM: User-pinned four-tab analysis workspace, code-led within the established world; no concept tournament because composition was explicitly settled. seed: none (user-pinned direction; concept-seed not applicable).
FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

Implementation authorized by the user's final confirmation and explicit impeccable redesign request. No new visual system or model calculation is authorized.

## Analytics implementation and verification - 2026-11-24

Implemented the confirmed direction in forecast_analytics.php with surface-scoped forecast-analytics.css. Historical test is the default; source/run controls govern source-dependent evidence across four query-link views: Forecasts vs Sales, Product Errors (highest/lowest), Model Details (trend, input influence, checks), and Prediction Logs. Input influence remains historical-only. The first comparison has one chart and Product, Date, Forecast and Actual sales columns. Search spans recorded rows with ten per page; Details retains recorded origins, baselines, SKU, horizon and differences. Original daily values and distinct origins remain available. Rankings now include all recorded Products for search; Store logs retain their latest-25 scope and the chart its latest-ten matched comparisons.

Native links, forms and disclosures retain JavaScript-free navigation and evidence access. Mobile tables become labelled rows, the current main tab centres on load/resize, and the shared info-tooltip helper clamps help to the painted root when a stable scrollbar gutter narrows it. Existing navy shell, Action Blue selection, neutral surfaces, system type, semantic series colours, focus treatment and light/dark preference remain the design authority. This is a local extension: docs/DESIGN.md and .impeccable/design.json were neither rewritten nor regenerated; no new global tokens, shipping raster assets or ADR were introduced. Local review captures are evidence only.

Verification: npm run check, npm run check:contracts, PHP syntax, diff whitespace, forecast_analytics_contract.php, forecast_landing_page_contract_test.php and forecast_analytics_chart_test.js passed in the implementation pass. The focused disposable-database candidate_analytics_browser_test.js passed for Administrator/Super Administrator and direct Inventory Manager/Cashier denial, 320/390/640/1440px in light/dark, populated/empty/unknown source isolation, distinct origins, search/pagination, grouping, JavaScript-free access, active-tab visibility, tooltip Escape/gutters, retina resize and unchanged database state. The broader forecast_landing_browser_test.js passed after adapting existing Training Configuration expectations and resetting hover. Shared replenishment_tooltips_test.js and stock_movement_tooltip_browser_test.js passed after the tooltip fix. A final independent general-check rerun is not claimed here.

TestSprite run a42b7001-4dc2-4488-a8ae-bd29a50b20fb passed 36/36 on an isolated synthetic Store; result and steps were inspected in tmp/analytics-testsprite/. This preceded the final tab-centering and gutter corrections, which the focused and shared local suites verified. The independent finish reviewer marked the hidden mobile current-tab and clipped-tooltip findings RESOLVED after refreshed captures and returned SHIP for those two fixes only. Captures are in .impeccable/review/forecast-analytics/, including comparison/errors/model/logs in desktop/mobile light/dark, mobile row details/help, empty state and 320px Store views. The actual signed-in localhost dataset was not inspected; no full ML training/backtest suite ran. Existing global-system drift was preserved rather than promoted into new design rules.

Final independent checks: npm run check and npm run check:contracts passed, as did PHP syntax, Analytics contracts, chart grouping, tooltip unit checks and diff whitespace. The pre-existing duplicate open-register ID warning in cashier/shifts.php remains. The final broad forecast_landing_browser_test.js rerun after the shared tooltip clamp passed. Training Configuration expectations now target its separate page; its recipe-help check uses keyboard activation to avoid hover re-entry during pointer scrolling.
