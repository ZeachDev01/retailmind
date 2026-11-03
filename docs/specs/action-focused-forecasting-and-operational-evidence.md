## Problem Statement

The Store Administrator needs to understand and use Demand Forecasting without machine-learning expertise. Existing forecasting pages expose readiness, candidate review, forecasts, and exceptions, but technical wording and inconsistent Ready or Active labels make it difficult to determine whether forecasts can guide buying today, which Products are covered, and who must act next.

The immediate priority is concrete evidence that the feature functions from Store history through background execution, approval, saved forecasts, display, Replenishment Recommendations, and recovery. Existing test reports include synthetic data, simulated installation assertions, and directly inserted failure notices. These are useful checks but do not prove actual worker failure notification delivery, unattended Windows/XAMPP operation, or useful predictions on genuine Store sales.

Final deployment is localhost on Windows using XAMPP services. The PC may be powered off overnight; routine operation cannot depend on a developer, an open browser, an application login, or manual XAMPP startup. A successful workflow demonstration must be distinguishable from evidence of prediction usefulness.

## Solution

Make Demand Forecast the simple, action-focused starting point for the Administrator. Lead with three questions: Can we use forecasting today? What needs my action? Is it helping? Reuse the existing detailed pages for investigation, informed model approval, and recovery.

Show current Product coverage explicitly, name the role responsible for each action, and explain automatic recovery without asking staff to intervene unnecessarily. Keep technical metrics available beneath understandable explanations. Approval remains an informed local Administrator decision after fixed automated checks pass.

Deliver a dated, traceable operational evidence package demonstrating the actual pipeline and target Windows/XAMPP installation. Separately display prediction evidence from genuine completed Store outcomes, or Still collecting evidence when unavailable. Label test and demo evidence honestly. Forecasts and buying suggestions remain advisory.

## User Stories

1. As an Administrator, I want one forecasting starting page, so that I know where to begin without visiting every forecasting page.
2. As an Administrator, I want to see whether forecasts can guide buying today, so that I do not confuse historical results with current guidance.
3. As an Administrator, I want counts of Products using forecasts and Products using stock rules, so that partial availability is visible.
4. As an Administrator, I want to inspect the Products behind each coverage count, so that I can understand exclusions.
5. As an Administrator, I want each excluded Product to have a clear reason, so that I know whether evidence, approval, freshness, or recovery is needed.
6. As an Administrator, I want coverage totals independent of a Product search, so that searching does not misrepresent Store-wide readiness.
7. As an Administrator, I want the date covered by the latest usable results, so that I understand how recent the guidance is.
8. As an Administrator, I want the latest update attempt distinguished from usable saved results, so that one failed attempt does not misrepresent existing valid guidance.
9. As an Administrator, I want issues prioritized by their effect on current guidance, so that the most important work is easy to find.
10. As an Administrator, I want every issue to explain its effect and next step, so that I can act without interpreting technical errors.
11. As an Administrator, I want each action to name its responsible role, so that work goes to the correct person.
12. As an Administrator, I want automatic retries identified as requiring no action, so that I do not create duplicate work.
13. As an Administrator, I want exhausted recovery to explain when technical support is needed, so that I know when local action is insufficient.
14. As an Administrator, I want a clear no-action state, so that a healthy dashboard does not manufacture tasks.
15. As an Administrator, I want a model-review action to open the detailed review before approval, so that activation is an informed decision.
16. As an Administrator, I want the proposed model described in ordinary Store language, so that I understand what I am approving.
17. As an Administrator, I want covered and excluded Products listed before approval, so that I understand its scope.
18. As an Administrator, I want comparisons explained in units sold for a stated period, so that error measures have practical meaning.
19. As an Administrator, I want observation progress and remaining blockers explained, so that I know why approval is unavailable.
20. As an Administrator, I want to know what approval changes and what remains advisory, so that I do not mistake activation for a purchase or stock adjustment.
21. As an Administrator, I want to inspect supporting technical metrics when needed, so that explanations remain auditable without dominating the page.
22. As an Administrator, I want unqualified candidates blocked from approval, so that understandable UI does not weaken safeguards.
23. As an Administrator, I want to approve a qualified Store candidate locally without routine developer access, so that Store operation is self-sufficient.
24. As an Administrator, I want to reject a candidate without losing the approved model, so that review does not disrupt working guidance.
25. As an Administrator, I want to pause and resume forecast-backed guidance with clear consequences, so that I can manage operational concerns safely.
26. As an Administrator, I want compatible previous-model recovery explained and recorded, so that rollback is deliberate and attributable.
27. As an Administrator, I want a short performance summary, so that I can understand whether evidence supports continued use.
28. As an Administrator, I want Still collecting evidence when genuine Store outcomes are insufficient, so that missing evidence is not presented as success.
29. As an Administrator, I want overestimation and underestimation shown separately, so that opposite errors do not cancel into a reassuring average.
30. As an Administrator, I want performance tied to the evaluated model, Products, dates, and Forecast Horizon, so that unrelated results are not mixed.
31. As an Administrator, I want forecasts compared with the existing simple weekly comparison, so that complexity alone is not treated as improvement.
32. As an Administrator, I want stockout periods marked as incomplete demand evidence, so that lower recorded sales are not incorrectly treated as proven overestimation.
33. As an Administrator, I want recorded stockouts and expiry losses distinguished from forecast error, so that business outcomes are not confused with prediction metrics.
34. As an Administrator, I want demo and synthetic results clearly labelled, so that they cannot be mistaken for genuine Store performance.
35. As an Inventory Manager, I want to see whether each buying suggestion uses forecasts or stock rules, so that I understand its basis.
36. As an Inventory Manager, I want expected sales distinguished from suggested purchase quantities, so that I do not buy the forecast quantity blindly.
37. As an Inventory Manager, I want the same usability explanation available to me, so that using forecasts does not require generation or approval permission.
38. As an Inventory Manager, I want actionable stock-evidence issues linked to existing inventory work, so that I can correct records through the proper workflow.
39. As an Inventory Manager, I want stock receiving and counting to continue during forecasting work or failure, so that the model does not block Store activity.
40. As a Cashier, I want sales and refunds to continue during forecasting work or failure, so that customers can still be served.
41. As a Super Administrator, I want technical settings and diagnostics kept in the technical Workspace, so that operational users are not asked to manage algorithms or service internals.
42. As a Store owner, I want required XAMPP services and forecasting scheduling to start with Windows, so that staff need not launch them manually.
43. As a Store owner, I want background work to run without an interactive Windows sign-in, browser, or RetailMind login, so that routine execution is unattended.
44. As a Store owner, I want missed work recovered after the PC starts, so that powering off overnight does not permanently stop forecasting.
45. As a Store owner, I want restart and database-startup delays tested on the target installation, so that service configuration is demonstrated rather than assumed.
46. As a Store owner, I want identified history traced through an actual worker run to saved forecasts, so that queueing alone is not called success.
47. As a Store owner, I want displayed quantities checked against persisted output, so that the dashboard is proven to show the worker's results correctly.
48. As a Store owner, I want a real failure to trigger retries, notice, fallback, and recovery in an isolated acceptance copy, so that failure handling is demonstrated end to end.
49. As a Store owner, I want every acceptance scenario marked Passed, Failed, or Not tested with dated evidence, so that completion claims are reviewable.
50. As a Store owner, I want operational acceptance reported separately from prediction usefulness, so that the feature can be demonstrated before sufficient live outcomes accumulate.
51. As a keyboard or assistive-technology user, I want labelled controls, clear focus, and understandable status text, so that I can complete forecasting tasks accessibly.
52. As an Administrator using a narrow screen, I want actions and explanations to remain readable and usable, so that the dashboard does not depend on a wide monitor.

## Implementation Decisions

### Existing architecture and delivery boundary

- This is a follow-up to completed forecasting work, not a replacement forecasting engine. Reuse DemandForecastService, DemandHistoryService, StoreTrainingEligibilityService, CandidateEvaluationService, StoreModelGovernanceService, StoreActivationService, ForecastingSchedulerService, ReplenishmentQueueService, the existing job service, and role capability policy.
- Preserve the single asynchronous Random Forest pipeline, persisted results, supported 7/14/28-day Forecast Horizons, chronological qualification checks, approved-model identity, demo isolation, and Protected Audit Records. Follow ADR-0005 as modified by ADR-0022.
- Reuse Demand Forecast as the Administrator's forecasting landing surface and retain Data Readiness, Model Review & Approval, Forecast Exceptions, and Replenishment Recommendations as linked destinations. Avoid a parallel dashboard with independently computed status.
- Reconcile current source with prior completed work before editing; earlier documents contain stale implementation-status statements. No schema migration is presumed. Add persistence only if existing dated evidence cannot represent a required outcome, and document the necessity.
- Keep the user's current task limited to specification and publication. This issue describes subsequent implementation and verification; publication is not evidence that either has occurred.

### Dashboard structure and status contract

- First show today's forecast usability and full eligible Store Product coverage, then actions needing attention, then a brief performance summary. Define the Product denominator explicitly using the existing active replenishment Product scope. Compute coverage before optional search/filtering; filtered results must be labelled separately.
- Use one shared operational eligibility decision for dashboard counts, per-Product status, forecast display explanations, exceptions, and buying guidance. Reuse the replenishment eligibility boundary; do not reproduce a weaker freshness-only check in presentation code.
- Current guidance requires complete successful operational output, the approved compatible Store model, the accepted completed Philippine-day origin, supported Product evidence, and no suspension or observation-only restriction. Unavailable Products use existing stock rules and retain unknown expected sales rather than fabricated zeros. Explain products with no positive buy suggestion without implying stock rules always recommend a purchase.
- Distinguish current usability, latest attempt, and background activity. A pending job is not a completed forecast; a failed latest attempt does not invalidate still-eligible earlier output. Old output may remain dated history but must not be labelled usable for buying.
- Handle empty Store, insufficient history, no approved model, candidate observation, partial coverage, current coverage, stale or incomplete output, failed update, automatic retry, paused guidance, and recovery. Never label guidance Active solely because it is not paused.
- Example coverage wording is Forecasts available for 18 of 25 products; 7 use stock rules. Example retry wording is Forecast update failed. Buying suggestions are using stock rules. Automatic retry scheduled; no action needed. Render only conditions supported by actual state; these examples are not Store results.
- Each issue includes a plain-language condition, practical effect, responsible role or automatic action, and a working link to the existing next step. Put conditions affecting current guidance first, followed by available review actions and informational progress. Do not create speculative or duplicate warnings.
- Keep Store Calendar and Promotions in existing operational navigation with contextual links. Preserve technical forecasting pages for the Super Administrator. Use the app's existing tokens, components, theme support, accessible patterns, and responsive layout; no new UI framework or chart dependency is needed. Apply relevant installed design skills during implementation and verify rendered desktop/narrow-screen behavior.

### Review, roles, and actions

- The review action navigates to a business-first approval summary: Products covered/excluded, model comparison with units and periods, observation progress, passed checks and blockers, limitations, and the effect of activation. Put raw MAE, RMSE, WAPE, model identifiers, folds, and diagnostics in supporting details with explanations. WAPE is error, not an accuracy percentage.
- Preserve fixed automated gates and at least 28 usable completed prospective observation target dates. Preparing a candidate does not replace the approved model. Historical backtests do not substitute for prospective observation; synthetic elapsed days never authorize actual Store activation.
- Local Administrator approval, rejection, suspension/resumption, and compatible rollback use existing authenticated, CSRF-protected, audited actions. Revalidate eligibility on mutation. Do not add dashboard one-click activation or permit remote mutation.
- The Administrator owns operational review, calendar maintenance, approval, and exceptions. The Super Administrator owns imports, technical configuration, manual training, and maintenance. The Inventory Manager owns inventory execution and buying decisions; the Cashier records sales/refunds without forecasting management rights. Preserve active Workspace capability enforcement even for users assigned multiple roles.
- Provide read-only usability explanations to the Inventory Manager without granting generation or approval capability. Dashboard links never substitute for backend authorization.
- Forecast Quantity remains expected recorded sales in Base Units; Replenishment Recommendations account for stock and existing replenishment rules. Neither places an order nor changes inventory. Only existing operational workflows such as Stock Receiving change stock.

### Honest evidence and performance

- Maintain separate operational evidence and prediction-performance evidence. A passing job, a history-length readiness category, a screenshot, or a public-data benchmark is not measured Store prediction usefulness.
- Reuse candidate/forecast provenance and outcome evaluation. Show model identity, Product coverage, supported Forecast Horizon, evaluation dates, and usable comparison count. Compare dated forecasts made before the outcomes with subsequent genuine Store observations and the existing weekly comparison on matching eligible periods.
- Until existing evaluation prerequisites and genuinely usable outcomes support a claim, show Still collecting evidence with actual progress and reasons. Do not invent an accuracy target, replace existing gates, or introduce a new numeric sufficiency threshold. When performance belongs to a candidate or previous model, label that association rather than implying it describes the current approved model.
- Summarize overestimated and underestimated units separately at stated Product/horizon boundaries. Do not cancel signed errors into an accuracy claim or multiply overlapping forecast windows into apparently independent evidence. Missing or incomparable data stays unavailable.
- Stockouts, including positive sales before a sellout, leave unmet demand unknown. Preserve recorded sales but do not treat incomplete availability as proof of complete demand. Closures, unknown history, and imported evidence retain their established distinctions.
- Keep recorded stockouts and expiry losses separate from forecast errors. Reuse available verified operational evidence for these outcomes; show unavailable when absent. Do not claim forecasting caused fewer shortages or less waste without a valid comparison. Exact business tolerances remain deferred; existing qualification checks remain in force.
- Collected prices and promotion evidence do not establish active model inputs or improvement. Current model behavior must be described truthfully; new input experiments are not part of this issue.

### Windows/XAMPP acceptance

- Use the actual target Windows localhost/XAMPP configuration. Record installed PHP/Python executable selection, database configuration, service account/startup behavior, scheduler configuration, application revision, and model provenance without exposing credentials.
- Preserve accepted daily Forecast Generation at 01:00 and Sunday candidate training at 01:00, Asia/Manila. Verify effective settings and correct conflicting defaults without silently overriding an explicitly selected operational schedule. Verify latest-completed-day cutoff, current-only catch-up, deduplication, and the three-total-attempt policy.
- Verify automatic local service startup and job execution without interactive Windows sign-in, browser, application login, or manual XAMPP launch. The system cannot run while powered off; it must resume useful work after startup.
- Verify closure-aware accumulated history and observation continuity against the worker, including any stricter consecutive-observed-date generation checks. Fix contradictory prerequisites in shared logic rather than merely changing page labels. Keep 140 accumulated usable training records and existing chronological coverage requirements.
- Exercise destructive resets and failure injection only in isolated acceptance data/artifacts on the target software stack. Do not corrupt genuine Store history or promote synthetic candidates into production. Distinguish isolated target-stack demonstrations from genuine Store readiness.

## Testing Decisions

- The user approved the highest existing end-to-end boundary: identified Store history through the real queue and worker, persisted model/forecast output, authenticated pages, and Replenishment Recommendations. Prefer this boundary rather than adding parallel private test interfaces. Use focused existing service contracts only for failures or authorization cases that are impractical to exercise at that boundary.
- Prior art includes the Store forecasting operational workflow suite, Demand Forecast workflow browser fixtures, forecast decision/browser checks, forecast navigation and notices checks, scheduler/runner installation checks, and Store activation/recovery contracts. Extend or correct their behavioral assertions; source-string matching and simulated configuration assertions cannot establish installation acceptance.
- Test externally observable behavior: permitted/blocked actions, saved output, displayed quantities and explanations, usable coverage, fallback, actual notices, and recovery. Do not assert private methods, SQL spelling, chart internals, or model tree layout.
- Exercise the history reader, training eligibility, candidate evaluation, governance/activation, real worker persistence, replenishment eligibility, role authorization, and dashboard together. Seed minimum isolated history where necessary, then execute the actual pipeline rather than inserting a successful job or forecast as proof of completion.
- Demonstrate blocked unqualified approval and qualified local approval. Synthetic qualification/observation may support isolated workflow tests only and must be labelled; document genuine production qualification separately.
- Assert search-independent coverage, full/partial/no coverage, expired and incomplete forecasts, model replacement, observation-only results, pause/resume, compatible rollback, failed update with valid earlier output, and mismatched Product evidence. The dashboard and buying guidance must agree on the same Product/date/model.
- Inject an actual worker failure and observe retries, final job state, generated notification, stock-rule fallback, then successful recovery. Directly inserting the expected notification is not evidence that the failure path delivers it.
- Verify negative authorization paths for Cashier, Inventory Manager, remote access, and wrong active Workspace. Exercise successful local Administrator controls and prove read-only explanations do not require mutation permissions.
- Browser verification covers working action destinations, explicit approval review, keyboard navigation, labelled controls, visible focus, text-based statuses, existing light/dark themes, narrow-screen layout, empty/error/loading states, and no false success after queueing. Capture screenshots as supporting visual evidence alongside behavior assertions.
- On the target Windows/XAMPP installation, perform an actual restart without interactive sign-in and inspect independent service/job evidence afterward. Demonstrate database startup delay, missed daily/weekly catch-up, duplicate prevention, actual full worker completion, and continued sales/refund/receiving/count operations during forecasting activity. A simulated SYSTEM account setting is not an unattended boot test.
- Produce an acceptance matrix containing scenario, environment/revision, data provenance, expected result, observed result, Philippine timestamp, supporting run/model/forecast references and artifacts, and Passed/Failed/Not tested. Capture only evidence actually observed; record unavailable prerequisites or manual steps honestly.

| Acceptance scenario | Required evidence |
| --- | --- |
| History to candidate | Identified isolated source history, actual completed training/evaluation, saved compatible candidate |
| Qualification to approval | Blocked unqualified attempt, labelled observation evidence, authorized approval and audit attribution |
| Approval to forecasts | Actual worker execution and complete persisted 7/14/28-day output |
| Forecasts to dashboard | Displayed dates, quantities, model identity and coverage agree with saved output |
| Forecasts to buying guidance | Eligible Products use forecasts; excluded Products show truthful stock-rule basis |
| Failure to recovery | Injected failure, bounded attempts, generated notice, fallback and successful recovery |
| Windows/XAMPP startup | Actual unattended startup and catch-up supported by dated service/job evidence |
| Store non-disruption | Successful routine Store operations during forecasting work/failure |

- Separate the operational completion verdict from prediction usefulness. Do not declare target-installation acceptance complete while a mandatory operational scenario is Failed or Not tested. Insufficient genuine Store outcomes may leave prediction usefulness Still collecting evidence without preventing an honestly labelled isolated functional demonstration.

## Out of Scope

- Implementing the application or changing Windows services during specification/publication.
- A second forecasting engine, replacement model architecture, cloud deployment, multi-Store support, or a new dashboard framework.
- Automatic model activation, ordering, inventory adjustment, relaxed qualification, combined Workspace powers, or routine developer approval for qualified local activation.
- New price/promotion/stockout feature experiments, invented lost-demand quantities, new accuracy promises, or revised error thresholds without evidence.
- New causal business-benefit claims, a general expiry/stockout analytics subsystem, or waiting for months of outcomes before demonstrating isolated functional behavior.
- Destructive testing on genuine Store records, production use of synthetic observation evidence, or presenting demo performance as genuine Store performance.

## Further Notes

- Decisions synthesized on November 2, 2026, Asia/Manila. The user explicitly requested discussion followed by a specification and deferred implementation.
- This follows [the hands-off forecasting specification, issue #359](https://github.com/ZeachDev01/retailmind/issues/359). Related completed work includes [navigation #371](https://github.com/ZeachDev01/retailmind/issues/371), [unattended startup #363](https://github.com/ZeachDev01/retailmind/issues/363), [scheduler recovery #361](https://github.com/ZeachDev01/retailmind/issues/361), [candidate observation #367](https://github.com/ZeachDev01/retailmind/issues/367), [current purchasing guidance #369](https://github.com/ZeachDev01/retailmind/issues/369), [forecasting notices #382](https://github.com/ZeachDev01/retailmind/issues/382), and [Store Calendar #386](https://github.com/ZeachDev01/retailmind/issues/386). Their completion is prior art, not proof of target-installation acceptance.
- Static inspection found inconsistent current-forecast labels, an Active label derived solely from suspension, technical-first approval wording, a generation-window check needing closure-aware comparison, and a 23:00 daily default conflicting with accepted policy. Recheck these against the implementation revision before modifying them.
- No new runtime tests, unattended reboot, target deployment acceptance, or genuine Store performance measurement were performed while producing this spec. Existing reports must not be promoted to stronger claims without examining the underlying evidence.
