# Forecasting Scheduler & Worker Operations

This document provides operational instructions for deploying and maintaining the scheduled ML worker, which is responsible for daily Forecast Generation and weekly Model Retraining.

The commands below run forecasting only. Also configure the independent [daily notification job](DAILY_NOTIFICATIONS.md) for Product-aware expiry reminders, including on days without transactions. The full-source maintenance wrappers already call both scripts.

## Automatic training rules

- After the configured daily forecast time (default 23:00), a Store with no completed training candidate is checked for its first training run. It does not have to wait for the weekly training day.
- The existing history reader requires at least one active product with **140 consecutive usable daily observations through yesterday**. This includes genuine zero-sales days; it is not a minimum sales volume or a promise of accuracy. Missing evidence, closures, and stockout exclusions can prevent qualification.
- Once a completed candidate exists, retraining follows the configured weekly day/time (default Sunday 01:00). It requires newer eligible history than the last completed candidate's recorded data window. Unchanged or stale Store history is skipped.
- A queued/running training job blocks another automatic training job, including manual jobs and jobs from a previous day. Any training attempt on the same calendar day prevents repeated automatic attempts that day. The CLI also takes a local filesystem lock against overlapping scheduler ticks.
- Training and evaluation run in the background. Passing tests never automatically approves a model, replaces the approved model, or enables routine purchasing. Review the results in Demand Forecast and ML Operation.
- Isolated Demo databases retain their weekly training behavior; the new first-training trigger applies to Store history.

## Supported Deployment Topologies

The scheduled worker requires reliable execution and must not be interrupted by desktop power savings or employee workstation restarts.

**Supported:**
* **Dedicated Windows Store-PC:** Must be configured to prevent sleep/hibernate during off-hours. Power-loss recovery (BIOS auto-power on) and a UPS are strongly recommended.
* **Linux VPS (Virtual Private Server):** Ideal for reliable cron-based execution.

**Unsupported:**
* Shared hosting environments lacking persistent Python virtual environments or cron access.
* Ordinary employee workstations that are regularly powered off or put to sleep after the store closes.

## Windows Store-PC Scheduling (Task Scheduler)

From a PowerShell terminal at the repository root, with PHP and Python available:

```powershell
./src/backend/scripts/install_forecasting_scheduler.ps1 -WhatIf
./src/backend/scripts/install_forecasting_scheduler.ps1
Get-ScheduledTask -TaskName 'RetailMind ML Scheduler'
Get-ScheduledTaskInfo -TaskName 'RetailMind ML Scheduler'
```

The installer creates a hidden task every five minutes under the current Windows account, with no administrator elevation or password stored by RetailMind. **This setup runs only while that user is signed in.** The PC must be awake, and the application's database must be running. Closing the browser does not stop scheduling. The installer refuses to overwrite an existing task.

Scheduler output is appended to `src/backend/storage/logs/forecasting-scheduler.log`. Database connection failures return a nonzero task result. The task uses the installation's `.env`; isolated Demo launch settings from another terminal do not carry over. No successful task run or model training is implied by the task's `Ready` status.

For an installation that must run after Windows sign-out, configure a suitable task account and its access in Task Scheduler. The manual alternative below requires that account's credentials and local security permissions:

1. Open **Task Scheduler** (`taskschd.msc`).
2. Click **Create Task...** (not Basic Task).
3. **General Tab:**
   - Name: `RetailMind ML Scheduler`
   - Security options: Select **Run whether user is logged on or not** with an account allowed to read the application, access its database, and write its logs/artifacts. Administrator elevation is not required for ordinary forecasting.
4. **Triggers Tab:**
   - New Trigger -> Begin the task: **On a schedule** -> **Daily**
   - Set the time to after Store close (e.g., `11:00 PM`) and recur every `1` days.
   - Also, add a trigger to run the worker process loop every 5 minutes if you prefer the PHP scheduler to trigger tasks dynamically.
5. **Actions Tab:**
   - Action: **Start a program**
   - Program/script: `php.exe`
   - Add arguments: `-f C:\path\to\retailmind\src\backend\scripts\run_scheduler.php`
6. **Conditions Tab:**
   - Uncheck "Start the task only if the computer is on AC power" if running on a dedicated PC with UPS.
   - Check "Wake the computer to run this task".
7. **Settings Tab:**
   - Check "Run task as soon as possible after a scheduled start is missed".

### Dedicated PC Power/Security Obligations
- **Sleep Settings:** Disable automatic sleep in Windows Power Options. The screen can turn off, but the PC must remain awake.
- **Physical Security:** The dedicated PC should be secured in the back office.
- **Updates:** Configure Windows Updates to run outside of the schedule windows to avoid unexpected restarts during ML training.

## Linux VPS Scheduling (Cron & Virtual Environment)

On a Linux server, the scheduler relies on cron and the Python virtual environment.

1. **Activate Virtual Environment and Install Dependencies:**
   ```bash
   cd /path/to/retailmind
   python3 -m venv venv
   source venv/bin/activate
   pip install -r src/backend/scripts/requirements.txt
   ```
2. **Configure Cron:**
   Open crontab with `crontab -e` and add the following entry to trigger the PHP scheduler script every 5 minutes, which delegates to the ML worker if tasks are due:
   ```cron
   */5 * * * * cd /path/to/retailmind && php src/backend/scripts/run_scheduler.php >> /path/to/retailmind/storage/logs/scheduler.log 2>&1
   ```
   *Note: Ensure PHP and Python are accessible in the cron environment's PATH.*

## Backup & Restore of Metadata and Artifacts

- **Artifacts:** The trained models are stored in `src/backend/storage/ml_models/`. These `*.joblib` files are portable and backward-compatible with the same scikit-learn version.
- **Metadata:** Training metadata, job statuses, and evaluation metrics are safely stored in the `ml_jobs` and `model_training_runs` database tables. These are included in encrypted RetailMind Store packages.
- **Restore:** Simply copy the `ml_models` folder to the new host and restore the SQL database. The worker will cleanly resume from the last known state.

## Security & HTTPS

- Over-the-network communication between the worker and the database (if hosted separately) must enforce SSL/TLS.
- The Python ML worker connects directly to the local database securely via environment variable injection (`.env`), eliminating exposed endpoints.

## Monitoring and Recovery

System health surfaces in the Admin Dashboard automatically track:
- Worker heartbeats and last activity.
- Missed runs (if no task has completed within 48 hours).
- Failures and safe diagnostic statuses.

If a run is missed, the scheduler's idempotency guarantees that overlapping processes or lease recoveries will safely catch up without producing duplicate forecasts or promoting duplicate models. Full technical traces of failures are retained in `activity_log` and `model_training_runs`.
