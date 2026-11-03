# Administrator forecasting workflow discussion

Discussion date: 2026-11-02, Asia/Manila. Status: synthesized into [the action-focused forecasting specification](../specs/action-focused-forecasting-and-operational-evidence.md) and published as [GitHub issue #410](https://github.com/ZeachDev01/retailmind/issues/410), labelled ready-for-agent. No application changes or new deployment verification performed.

## Accepted decisions

- Final deployment is localhost on Windows using XAMPP services. This is the acceptance environment, not merely a development assumption.
- The Administrator needs a simple dashboard showing today's usability, actions needing attention, and a short performance summary. Deeper readiness, model review, and exception pages open when relevant; every warning explains its effect and next step.
- Show coverage per Product rather than one blanket Ready label. For partial availability, state how many Products use current forecasts and how many use stock rules, with a link explaining which Products and why.
- Each issue names the responsible role or states that automatic recovery requires no action. Escalate to technical support when automatic recovery cannot resolve the condition.
- Dashboard review actions open an informed model review before approval; approval is not a one-click dashboard mutation.
- Show Still collecting evidence until sufficient genuine Store outcomes are available. Once available, distinguish overestimation from underestimation; demo results and successful background jobs do not establish prediction usefulness.
- Assess shortages and excess stock separately as business outcomes. Do not interpret higher purchase suggestions as proof of success; acceptable business tolerances remain to be settled.
- Model approval should lead with products covered, comparison results explained in units, observation progress, limitations, and what approval changes. Technical metrics remain available as supporting detail. Fixed automated qualification checks remain mandatory.
- Reliability requires separate evidence for operational execution/recovery and useful prediction performance against actual Store sales. Neither substitutes for the other.
- Stockout periods are incomplete evidence of customer demand, including periods with positive recorded sales. Do not claim overestimation solely from lower sales during stockouts; report forecast error on usable observations separately from recorded stockouts and expiry losses.
- Retain existing qualification checks and the weekly comparison. Revisit thresholds only with genuine Store evidence; passing checks permits informed approval and does not guarantee future results.
- Require a dated demonstration on the actual Windows/XAMPP installation covering unattended startup, missed-work recovery, completed forecasts, failure handling, and continued routine Store operations. Report each acceptance scenario as Passed, Failed, or Not tested, with its supporting evidence.
- The user's immediate priority is concrete evidence that the feature functions end to end. Do not make future predictive accuracy a prerequisite for demonstrating implementation behavior, and do not present that demonstration as proven Store accuracy.
- Previously accepted local Administrator approval and mostly unattended operation remain in force; see ADR-0022 and the hands-off forecasting specification.

## Current implementation findings

Static code review found local Administrator approval, pause/resume, compatible rollback, accumulated training history eligibility, prospective candidate observation, and stock-rule fallback already implemented. These findings are not runtime verification.

- Administrator navigation already exposes Data Readiness, Model Review & Approval, Demand Forecast, and Forecast Exceptions.
- The model review page foregrounds technical terms and metrics. Its Purchasing Suggestions Active label currently reflects lack of suspension, which does not establish available current forecast guidance.
- Demand Forecast freshness labels and replenishment eligibility use different freshness conditions. Staff need a consistent explanation of whether a displayed historical forecast can guide buying today.
- Forecast generation still has a consecutive-observed-date check that needs comparison with closure-aware training eligibility.
- Daily schedule fallback remains 23:00 in code, while the accepted policy is 01:00 Philippine time. Installed settings were not inspected.
- Current prediction features use sales history and calendar patterns; collected price/promotion evidence is not proof those fields influence the model.
- Existing workflow test reports include synthetic fixtures and simulated installation assertions. They do not independently prove unattended operation after an actual Windows reboot on the target XAMPP installation or useful prediction performance on genuine Store history.

## Evidence to establish

Operational acceptance needs a recorded target-installation demonstration: service startup, background execution without browser/application login, missed-schedule catch-up, database startup delay, complete persisted forecast output, Administrator approval, failure/recovery and stock-rule fallback. Verify routine Store operations continue during forecasting work.

Prediction acceptance needs dated predictions recorded before their outcomes, genuine subsequent Store sales with availability limitations preserved, a simple weekly comparison, and understandable per-horizon/product results. Synthetic and public-demo evidence must be labelled separately. Exact business acceptance tolerances remain open; existing configured qualification gates are not waived.

## Open decisions

- Exact numeric business tolerances for overestimation versus underestimation remain deferred pending genuine Store evidence. Existing qualification thresholds remain in force; no new tolerance is required to specify an operational demonstration.
- No further business-policy questions block specification of the agreed dashboard, role workflow, and operational evidence. Installation paths, service configuration, available history, and runtime outcomes are facts to verify rather than assumptions to ask the user to settle.

## Work boundary

The user explicitly deferred implementation. Continue discussion, then prepare a specification; application changes are not authorized at this stage. The later UI implementation should use relevant design skills and deliver functioning actions, truthful states, and consistent guidance across the forecasting pages.

Related context: [earlier reliability discussion](forecasting-pipeline-reliability-2026-10-31.md), [hands-off specification](../specs/hands-off-local-demand-forecasting.md), [local approval decision](../adr/0022-local-administrator-forecast-model-approval.md).
