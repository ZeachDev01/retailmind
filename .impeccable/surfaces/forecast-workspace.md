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
