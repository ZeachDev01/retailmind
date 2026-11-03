# Role onboarding and stock-rule-first replenishment guidance

## Problem Statement

Administrator and Inventory Manager staff need to understand their available workflows and finish essential Store setup without learning every screen at once. Existing product entry already includes product details, opening stock, package sizes, and replenishment settings. Repeating those fields in an onboarding wizard would add work and create competing procedures.

Staff need to distinguish a Store that is ready to operate from forecasting that is ready to guide buying. A new Store can operate and use stock rules while genuine sales history accumulates. Suggested buying quantities must not look like predicted customer sales. Experienced staff need to skip guidance, and staff who finish setup should not receive redundant reminders.

The Administrator needs actionable forecasting notices rather than daily success messages. The Inventory Manager needs a practical sequence from reviewing replenishment needs to recording goods actually received, with forecast details available when useful.

## Solution

Add optional, role-specific onboarding using the existing RetailMind design system and Impeccable's onboarding guidance. Start with a short overview of the active Workspace, then introduce workflows in context. Both roles see a shared setup checklist linking to existing procedures; only the responsible Workspace can confirm its tasks.

The Inventory Manager completes product and opening-stock setup through Add Product. The Administrator completes Store calendar setup. Each explicitly confirms its checklist tasks. Show one completion message when the checklist is finished, stop automatic onboarding reminders, and keep the checklist and workflow guidance accessible through Help. Staff can skip guidance without blocking work or falsely completing setup.

Make replenishment the starting point for the Inventory Manager's daily buying workflow. Without a usable approved forecast, show "Stock-rule suggestion," the suggested buying quantity, and a reason. Show expected sales as "Not available yet." Staff decide what to buy and record actual arrivals through Stock Receiving. Forecasting notifications ask the Administrator to act when needed; routine successful runs stay visible on the forecasting page.

## User Stories

1. As an Administrator, I want a short first-visit overview of my Workspace, so that I understand my responsibilities and available workflows.
2. As an Inventory Manager, I want a short first-visit overview of my Workspace, so that I know where to begin stock work.
3. As an Administrator, I want guidance on Store setup, so that I can prepare the Store's operational context.
4. As an Administrator, I want guidance on staff management, so that I can find the existing account workflows.
5. As an Administrator, I want guidance on reports, so that I can review Store performance.
6. As an Administrator, I want guidance on forecasting oversight, so that I can understand readiness and the actions available to my Workspace.
7. As an Administrator, I want guidance on operational exceptions, so that I can find work requiring review.
8. As an Inventory Manager, I want guidance on product setup, so that I can use the existing Add Product procedure correctly.
9. As an Inventory Manager, I want guidance on stock monitoring, so that I can find low-stock and availability information.
10. As an Inventory Manager, I want guidance on Stock Receiving, so that I can record goods brought into the Store.
11. As an Inventory Manager, I want guidance on inventory control, so that I can find counts and Stock Issue review.
12. As an Inventory Manager, I want guidance on Replenishment Recommendations, so that I can turn stock information into buying decisions.
13. As an experienced staff member, I want to skip onboarding, so that I can begin work immediately.
14. As a busy staff member, I want to defer guidance, so that onboarding does not interrupt Store operations.
15. As a returning staff member, I want skipped or completed guidance to stay quiet, so that I do not receive repeated automatic prompts.
16. As a staff member, I want to reopen guidance through Help, so that I can refresh my knowledge when needed.
17. As a staff member, I want brief contextual guidance when first opening an unfamiliar workflow, so that explanations arrive when useful.
18. As an Administrator, I want to see shared setup progress, so that I know which Inventory Manager tasks remain.
19. As an Inventory Manager, I want to see shared setup progress, so that I know whether the Store calendar has been reviewed.
20. As a staff member, I want checklist links to open existing permitted workflows, so that I can act without repeating data entry.
21. As an Inventory Manager, I want product details, opening stock, package sizes, and stock-rule settings to remain in Add Product, so that there is one product setup procedure.
22. As an Inventory Manager, I want to explicitly confirm product and opening-stock setup, so that a first saved Product does not falsely complete Store setup.
23. As an Administrator, I want to explicitly confirm Store calendar setup, so that a first calendar entry does not falsely complete my review.
24. As a staff member, I want checklist actions restricted to the responsible Workspace, so that shared visibility does not grant extra authority.
25. As a staff member with multiple roles, I want guidance to follow my active Workspace, so that displayed actions match my current permissions.
26. As a staff member, I want one checklist completion message, so that I know essential setup is finished without repeated reminders.
27. As a Store operator, I want setup completion separate from forecast readiness, so that operations continue while sales history accumulates.
28. As an Inventory Manager, I want clearly labelled stock-rule guidance before usable forecasts exist, so that replenishment is useful from day one.
29. As an Inventory Manager, I want each stock-rule suggestion to show a buying quantity and reason, so that I can assess why it was suggested.
30. As an Inventory Manager, I want expected sales to show "Not available yet" without a usable forecast, so that a buying quantity is not mistaken for a prediction.
31. As an Inventory Manager, I want the replenishment list as the starting point for daily buying review, so that I can focus on Products needing attention.
32. As an Inventory Manager, I want forecast details available when useful, so that charts support a decision without becoming required steps.
33. As an Inventory Manager, I want suggestions to remain advisory, so that actual purchases can reflect Store knowledge and available goods.
34. As an Inventory Manager, I want actual received quantities recorded through Stock Receiving, so that inventory reflects goods that arrived.
35. As a Store operator, I want viewing guidance to leave stock and purchasing records unchanged, so that information is not mistaken for a transaction.
36. As an Administrator, I want notice when a qualified model awaits review, so that I can take the permitted action when the approval workflow is available.
37. As an Administrator, I want forecasting failures needing attention to produce actionable notices, so that I can find the next step.
38. As an Administrator, I want routine successful runs visible without daily success notifications, so that important notices remain noticeable.
39. As a staff member, I want operational alerts to continue after onboarding finishes, so that low stock and forecast problems still reach me.
40. As a staff member using a keyboard or small screen, I want accessible guidance and checklist controls, so that onboarding works in my normal working conditions.

## Implementation Decisions

- Add onboarding and Help access to the authenticated Workspace shell and existing workflow entry points. Reuse current components, navigation, visual tokens, and Operator Alert patterns. Apply Impeccable to this Operate surface with concise copy and restrained hierarchy.
- Cover the Administrator and Inventory Manager workflows above using a short overview and contextual guidance. Do not require a tour of every screen. Provide clear skip/defer and Help actions.
- Keep staff guidance state distinct from shared Store setup state. Remember skipped/completed guidance, share setup confirmations, and preserve results across navigation and later visits. Reopening Help must not restart automatic prompts. Skipping must not complete Store setup.
- Reuse existing product entry for product details, physically counted opening stock, package conversions, and stock-rule values. Values reflect Store knowledge; defaults do not establish suitability. Do not add a second product form or per-product onboarding confirmations.
- The Inventory Manager explicitly confirms product and opening-stock setup. The Administrator explicitly confirms Store calendar setup. Existing records alone do not satisfy either confirmation. Both roles see updated progress after an authorized confirmation.
- Enforce active Workspace permissions server-side for confirmations and linked workflows. Shared visibility does not permit Administrator inventory mutation or Inventory Manager calendar setup confirmation. Reuse request validation and CSRF protections.
- Show a single completion message and stop automatic onboarding reminders after all tasks finish. Keep guidance available through Help. Operational alerts continue independently.
- Keep operational setup, history eligibility, model qualification, model approval, and usable forecast availability distinct. Setup completion does not train or activate a model.
- Plan for genuine customer sales history from day one. Trustworthy dated history from the same Store may be imported through the existing technical workflow. Replenishment Receipts do not substitute for customer sales history.
- Reuse existing replenishment calculations and package/minimum-quantity rules. Without a usable approved forecast, label the basis "Stock-rule suggestion," show the buying quantity and reason, and show expected sales as "Not available yet." Missing evidence is not a zero-sales prediction.
- Use the existing forecast usability boundary for displayed sales and buying calculations. Coordinate with the forecast-validity issue rather than inventing another policy. Unusable historical forecasts must not silently supply today's suggested buying quantity.
- Start Inventory Manager buying-review guidance at the replenishment list. Forecast details are optional. Stock Receiving records actual arrivals; viewing advice creates no purchase, receipt, or inventory movement.
- Reuse Administrator attention and notification surfaces for actionable review requests and failures. Routine successful runs remain on the forecasting page without daily success notifications. Avoid redundant notices for an unchanged condition using existing notification conventions.
- Local Administrator model approval follows ADR-0022 and existing forecasting work. Do not bypass qualification, observation, approval, or technical configuration boundaries. Guidance must describe implemented permitted actions honestly and must not advertise a nonfunctional approval action.
- Reuse persistence conventions and introduce only the state needed for guidance and shared confirmations. Exact storage and delivery mechanics remain implementation choices; no new public API or duplicated settings system is prescribed.

## Testing Decisions

- The user confirmed real authenticated Administrator and Inventory Manager browser workflows with isolated test data. Prefer one browser-to-HTTP acceptance boundary, extending existing harnesses and fixtures rather than creating an onboarding test framework.
- Test observable behavior and persisted outcomes, not private helpers, exact DOM structure, or storage schema. Prior art includes existing Playwright Workspace-switching, forecast-decision, notification-list, product-creation, and Stock Receiving tests.
- Cover first visit, skip/defer, navigation and later login without repeated prompts, Help reopening, and contextual guidance in the active Workspace. Verify skipping does not complete shared setup.
- Complete setup in separate role sessions using existing Add Product and Store Calendar workflows, confirm tasks in the responsible Workspace, and observe shared progress. One initial Product or calendar entry must not silently complete a task.
- Verify one completion message and no recurring onboarding reminder after all tasks finish, including refresh and later login. Reopening Help must not change operational records or completion state.
- Verify incorrect-Workspace and invalid-CSRF confirmation submissions fail without changing shared progress. Include a multi-role account to prove active Workspace authority.
- Reuse forecast-decision fixtures for the exact stock-rule label, buying quantity, reason, and unavailable expected-sales state. Distinguish genuine zero forecasts from unavailable forecasts. Integrate stale, suspended, and otherwise unusable forecast cases with forecast-validity work instead of duplicating calculations.
- Exercise replenishment review, optional forecast details, and Stock Receiving. Advice must not mutate stock; receiving actual goods must update committed stock through the existing workflow.
- Reuse notification fixtures to distinguish a qualified candidate awaiting review, an actionable failure, and a routine successful run. Rechecking an unchanged condition must not create redundant notices. Onboarding completion must not suppress operational alerts. Coordinate candidate-ready tests with existing model approval work.
- Inspect rendered desktop and narrow-screen views using existing theme/browser patterns. Verify keyboard operation, visible focus, readable status text, appropriate focus handling for overlays if used, and reachable skip/Help controls. Follow light/dark conventions where supported.
- Use disposable databases only. Fixtures prove workflow behavior, not forecast accuracy, deployment readiness, or real model qualification. Run these tests during implementation; this specification does not claim they already pass.

## Out of Scope

- Application implementation during this specification task; coding remains deferred at the user's request.
- Rebuilding product entry, receiving, staff management, reports, inventory control, or Store Calendar as onboarding-specific workflows.
- Mandatory training, quizzes, a blocking tour, fabricated transactions, or visiting every hint before Store work begins.
- New Cashier or Super Administrator onboarding, broad navigation redesign, analytics infrastructure, or changed role authority.
- Automatic ordering, purchase requests, stock changes from recommendations, or Administrator approval of ordinary Stock Receiving.
- Redesigning stock-rule arithmetic, package rounding, model training, thresholds, history eligibility, Windows startup, retries, feature experiments, or the model approval engine. Those remain separate forecasting work.
- Treating setup confirmations, completed guidance, public datasets, or synthetic tests as proof of model accuracy or approval.
- Removing operational alerts when guidance is skipped or complete.

## Further Notes

- Synthesized from accepted discussion on 2026-11-01, Asia/Manila. The user explicitly confirmed the browser workflow test boundary before publication.
- This extends [the forecasting specification](https://github.com/ZeachDev01/retailmind/issues/359) with onboarding and operational guidance. Preserve ADR-0005's single-pipeline and advisory-guidance principles and ADR-0022's accepted local Administrator approval direction.
- Coordinate notices with [background recovery and actionable notices](https://github.com/ZeachDev01/retailmind/issues/362), recommendation validity with [current-forecast purchasing guidance](https://github.com/ZeachDev01/retailmind/issues/369), and permitted destinations with [forecasting navigation](https://github.com/ZeachDev01/retailmind/issues/371). These are integration references, not a requirement to delay the optional onboarding shell until every forecasting task is done.
- Earlier inspection found Super Administrator-only model approval permissions, incomplete production activation actions, and weaker validity checks for buying calculations than displayed expected sales. Accepted policy and published specifications do not establish those gaps are implemented. Verify the current code when taking this issue.
- Preserve unrelated working-tree changes. No destructive cleanup or live Store fixture setup is authorized.
