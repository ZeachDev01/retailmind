---
version: 1
slug: "forecast-workspace"
primary_target: "src/frontend/components/report/predictions.php"
related_targets: ["src/frontend/components/report/data_readiness.php", "src/frontend/components/report/forecast_exceptions.php", "src/frontend/components/report/forecast_analytics.php", "src/frontend/components/report/forecast_replay.php", "src/frontend/components/administrator/model_review.php", "src/frontend/components/inventory_management/product_investigation.php", "src/frontend/components/inventory_management/replenishment_queue.php", "src/frontend/components/system_administrator/ml_settings.php", "src/frontend/components/system_administrator/forecast_import.php", "src/frontend/assets/css/forecast-workspace.css"]
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