# Daily operational notifications

Run `src/backend/scripts/run_notifications.php` once each day using the deployment's PHP CLI and database environment. It evaluates Product warning lead times, outstanding expired batches, and the existing unrelated operational checks. It never removes stock. The release package includes this CLI entrypoint; a code upload does not install its scheduled job.

On a Windows Store PC, the supported installer is `setup_scheduled_tasks.bat`. It registers the daily notification task together with the ML scheduler and weekly backup. See the [installation guide](WINDOWS_LOCALHOST_INSTALL.md).

Apply database updates before enabling the job, including `202610190001_attention_notification_type`. Older notification enums reject the shared attention writer and stop the daily check before expiry evaluation.

## Hosted cron

On a host with PHP CLI and cron, configure a daily job in the existing hosting control panel, using the actual PHP executable and deployed directory, for example:

```cron
0 3 * * * /absolute/path/to/php /absolute/path/to/retailmind/src/backend/scripts/run_notifications.php >> /absolute/path/to/retailmind/src/backend/storage/logs/daily-notifications.log 2>&1
```

Confirm the control panel's schedule timezone and execution account. Application expiry dates always use Asia/Manila; schedule the job daily in that timezone where the host permits it. The account must read the deployed root `.env` and write application logs. Keep credentials in `.env`, never in the cron command. Verify the script exists after release upload.

Run the same command once through the host's CLI, inspect its exit status, and confirm `Notification checks completed.` appears in the output log. Then inspect the installed cron command and the next scheduled run's new log output. Record the execution time and the Inventory Manager's resulting batch reminders. Existing opted-in expiry email delivery remains enabled; inspect the email delivery log when checking that channel.

InfinityFree free hosting does not support the required scheduled CLI execution. Do not claim daily delivery there. Use a deployment with an existing supported scheduler; this script is CLI-only and must not be exposed as an HTTP cron endpoint.

## Windows Task Scheduler

For a Windows Store PC, run `setup_scheduled_tasks.bat` from the repository root in an elevated PowerShell. It registers `RetailMind Daily Notifications` for 03:00 with catch-up, plus the ML scheduler and weekly backup tasks. This is the primary setup; see the [installation guide](WINDOWS_LOCALHOST_INSTALL.md).

The older `run_maintenance.bat` wrapper invokes forecasting and notifications together. To create or repair a notifications-only task manually, use a daily Task Scheduler action with the actual PHP path and deployed script. For example, use `cmd.exe` with arguments:

```text
/d /c ""C:\path\to\php.exe" "C:\path\to\retailmind\src\backend\scripts\run_notifications.php" >> "C:\path\to\retailmind\src\backend\storage\logs\daily-notifications.log" 2>&1"
```

Choose an account with access to `.env`, the database and logs. Configure a daily trigger, run when the user is logged out, and run as soon as possible after a missed start. Keep the PC available during the schedule. The forecasting-only `run_scheduler.php` action does not replace this job.

Verify the installed task's action, arguments, working directory and daily trigger. Run the task once, then inspect `Get-ScheduledTaskInfo` for `LastRunTime` and `LastTaskResult`. Require a new `Notification checks completed.` log entry as well as a successful result. Recheck the next scheduled execution; a manual run proves execution, not the daily trigger.

## Delivery behavior

Scheduled and transaction-triggered checks share batch/user milestone identity. Repeated evaluation retains one current batch condition while preserving milestone history. In-app preferences and active account/primary-role eligibility apply. For recipients using both in-app and email, the existing email delivery log suppresses repeated attempts for the same subject during the same Manila day; a previous day's email does not suppress the next day's attempt. An email-only preference still receives scheduled expiry email without an extra in-app alert. As with the previous email-only scheduled path, invoking the job repeatedly can repeat email attempts for those recipients; keep one daily job rather than overlapping jobs. No new delivery ledger or reminder-frequency setting is introduced.

The same daily job also sends the active remote access URL to active Administrators and Super Administrators at most once per Manila day. This catches up accounts created after the tunnel started. The day marker is saved only after every recipient succeeds, so a failed delivery is retried by the next daily run without re-emailing recipients already sent that day.

Deployment verification and current access limits are recorded in [the #235 verification note](../verification/daily-expiry-reminders.md).
