# Automatic Store model training

Implemented 2026-10-29 (Asia/Manila), following user approval of automatic training with manual model approval.

## Behavior

- First candidate: after the configured daily forecast time, the scheduler checks the existing history reader for active products with 140 consecutive usable days through yesterday. No weekly wait is required when no completed training candidate exists.
- Later candidates: follow the configured weekly schedule and require newer eligible history than the latest completed training data window.
- Queued/running training, including manual and older jobs, prevents duplicate automatic jobs. A training attempt already made that day also prevents another automatic attempt. A local file lock serializes scheduler invocations.
- Existing evaluation, Demo isolation, approved-model selection, and manual approval remain in force. Failed quality checks do not automatically replace the approved model.

## Local Windows setup

Installed `RetailMind ML Scheduler` using `src/backend/scripts/install_forecasting_scheduler.ps1`. Verified its action uses hidden Windows PowerShell, its repetition interval is five minutes, its principal uses the current signed-in user with limited privileges, and its state is Ready. No existing task was overwritten.

The PC must remain awake and the Windows user signed in; database services must be available. The task does not require a browser tab. For another computer, use the installer and instructions in [the scheduler guide](../guides/FORECASTING_SCHEDULER.md).

The application's configured database was unreachable during verification. A direct invocation of the same PowerShell runner correctly returned exit code 1 and wrote a diagnostic to `src/backend/storage/logs/forecasting-scheduler.log`. No successful operational queue, training run, or accuracy result is claimed. Existing database settings were not changed.

## Checks

- Scheduler behavior tests pass against in-memory SQLite: insufficient/eligible history, first training outside Sunday, before-close suppression, weekly updates, unchanged/stale history, pending/manual-job suppression, Demo behavior, system attribution, and unchanged approval/observation settings.
- Two Store history/CLI tests pass: real reader/worker history parity and a concurrent scheduler exiting before database access.
- Fifteen worker boundary tests pass, including failures preserving previous results and explicit approval requirements.
- Model review summary tests, PHP syntax checks, PowerShell parsing, installer dry run, installed-task inspection, offline logging check, and diff whitespace checks pass.

Regular closures and excluded days can still prevent the existing consecutive-day threshold from being met. Masked history windows and price/promotion/availability model inputs were not part of this change.
