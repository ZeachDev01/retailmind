# ML Operation settings policy

Design discussion, 2026-11-03. The following choices are agreed; application behavior has not been changed.

- Keep the current training eligibility policy: at least 140 accumulated usable days per Product, supported recent inputs, and chronological evaluation coverage. Display the same policy on Data Readiness and ML Operation. Remove disconnected minimum/preferred/nonzero-day and maximum-history controls. Keep fixed 7-, 14-, and 28-day Forecast Horizons.
- Let the Super Administrator configure the weekly Model Retraining day and time in Asia/Manila, using the existing scheduler. Replace the disconnected interval and new-sales-record controls. Forecast Generation remains separate from Model Retraining.
- Expose the actual per-horizon WAPE quality limits instead of the disconnected generic threshold. Keep existing baseline comparison, observation, and local approval requirements.
- Remove percentile controls until forecast uncertainty bounds are implemented. Show the actual Random Forest recipe read-only instead of disconnected tuning controls.

Schedule choices agreed in the discussion:

- Default weekly Model Retraining: Sunday at 1:00 AM Asia/Manila. The Super Administrator may change the weekday and time. Both schedule controls and displayed schedule times use AM/PM.
- The time picker accepts 12:00 AM-11:59 PM at minute precision. Tooltip: "Select a time from 12:00 AM to 11:59 PM, Philippine time (Asia/Manila). Prefer a time after Store close. Missed training runs catch up when the PC starts."
- Missed scheduled training catches up on the first scheduler check after the PC turns on, subject to eligible new history and existing duplicate-job safeguards. Catch-up does not wait for the selected time or weekday.
- Initial automatic training uses the next occurrence of the selected training time after eligible history is detected, without waiting for the selected weekday. The default is 1:00 AM Asia/Manila: eligibility detected Monday at 10:00 AM schedules initial training for Tuesday at 1:00 AM. An initial run missed while the PC is off follows the same catch-up rule. Manual training remains available to the Super Administrator.

The user confirmed these choices and the AM/PM presentation. Application implementation remains pending. The implementation specification is [Align forecasting settings and demonstrate the pipeline with isolated M5 data](forecasting-settings-and-m5-demo.md).

Training Eligibility and candidate qualification are separate: eligible Products supply training data; a resulting candidate must pass evaluation and observation requirements before explicit local operational approval. This policy retains [ADR-0022](../adr/0022-local-administrator-forecast-model-approval.md).
