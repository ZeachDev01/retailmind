# Administrator and Inventory Manager onboarding

Status: agreed discussion requirements, 2026-11-01 (Asia/Manila). Not implemented. Application changes remain deferred at the user's request.

## Purpose

Guide staff through their role's available workflows and essential Store setup. Start with a short overview, then provide contextual guidance when staff first open each workflow. Experienced staff may skip guidance.

## Coverage

- Administrator: Store setup, staff, reports, forecasting oversight, and operational exceptions.
- Inventory Manager: products, stock monitoring, receiving, inventory control, and Replenishment Recommendations.

Guidance respects current Workspace permissions. It does not grant new authority or imply that planned model approval actions already exist. See [the forecasting specification](hands-off-local-demand-forecasting.md) for the accepted future forecasting workflow.

## Shared setup checklist

Both roles see shared setup progress. Each task links to the existing workflow and permits actions only in the responsible Workspace.

- Inventory Manager confirms products and opening stock are set up. Product fields, opening quantities, package sizes, and stock-rule settings remain part of the existing Add Product procedure; onboarding does not duplicate that procedure.
- Administrator confirms the Store calendar is set up.

Each role explicitly confirms its tasks are complete. The presence of a first Product or calendar entry alone does not establish completion.

Store setup completion and forecast readiness are separate states. Normal Store operations continue while sales history accumulates. Plan for sales-history collection from day one; importing trustworthy dated history from the same Store remains optional technical setup.

Use stock-rule suggestions while usable approved forecasts are unavailable. Initial product stock-rule values are supplied through the existing product procedure using Store knowledge; defaults do not establish their suitability. Recommendations remain advisory and do not place orders or change stock.

Label this guidance "Stock-rule suggestion" and show the suggested buying quantity with its reason, such as "Stock is below the reorder level." Show expected sales as "Not available yet" when no usable forecast exists; stock-rule quantities must not be presented as predicted sales.

## Daily inventory workflow

The Inventory Manager starts with the replenishment list, reviews which Products need buying, and opens forecast details when useful. After goods arrive, the Inventory Manager records the actual quantities through the existing Stock Receiving workflow. Suggested quantities remain advisory; reviewing a recommendation does not record a purchase or receipt.

## Administrator attention

Forecasting notifications tell the Administrator when action is needed, including a qualified model awaiting review or a failure requiring attention. Routine successful runs remain visible on the forecasting page without daily success notifications. Model qualification and the transition to approved forecast guidance follow the accepted forecasting specification linked above.

## Guidance lifecycle

- Show onboarding on the first visit and allow staff to defer it without blocking Store work.
- Keep guidance and the shared checklist accessible afterward through Help.
- Respect skipped guidance and avoid repeated automatic prompts.
- When all checklist tasks are complete, show one completion message and stop onboarding reminders.
- Operational alerts, including low stock and forecast failures, continue independently of onboarding.

## Implementation boundary

The onboarding and daily workflow decisions above are agreed. Storage, completion-message delivery, and other implementation details remain deferred. These requirements do not establish that the current application already supports the accepted local Administrator model-approval workflow.
