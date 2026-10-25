# Daily expiry reminder verification (#235)

Verified October 19, 2026, Asia/Manila. This verifies the daily entrypoint in an isolated database, not an installed hosted daily job.

## Implementation checks

`RUN_DB_TESTS=1 php src/backend/tests/daily_expiry_mysql_test.php` executes the entire `run_notifications.php` entrypoint in disposable MySQL databases created through the existing theme fixture. Both the application clock and MySQL session timestamp are controlled. The test requires the completion message, because the previous global exception handler could return exit zero after failure.

Red/green checks reproduced and corrected:

- The legacy notification enum rejected `attention` and stopped the daily check before expiry evaluation. Migration `202610190001_attention_notification_type`, fresh schema and manual update SQL now accept the existing attention writer, preserving notification history.
- The fixed scheduled 30-day selection generated history before a Product's warning boundary. The daily check now shares Product-aware eligibility and batch/milestone identity with transaction checks.
- The shared generation path did not honor in-app opt-out. Active primary-role eligibility and default in-app delivery are retained; scheduled opted-in expiry email remains available for email-only recipients.

The integration test covers two- and five-day warning boundaries, defaults beyond 30 days, global settings, later Product setting changes, separate batches, repeated daily and overlapping transaction checks, undated/depleted exclusions, Administrator oversight, inactive/Cashier exclusions, preferences, expiration day and the next Manila midnight, unchanged batch/total stock, and a forced operational failure returning nonzero. It checks email attempts through the existing delivery log with outbound mail disabled; it does not claim SMTP delivery.

Expired wording is `Expired stock—waiting for supplier collection` for every Product, following the user's implementation clarification. No bread classifier is added.

The final review also caught a same-day email regression for recipients using both delivery channels. A failing repeated-run integration assertion reproduced it. The daily path now reuses `email_delivery_log` to suppress the same recipient/subject on the same Manila date, preserving the former scheduled guard without creating another in-app notification. Coverage verifies the next day's email still runs and the preexisting email-only repeated-invocation behavior remains unchanged.

Existing Product warning, Supplier Pullout, Replacement Stock, attention, fresh-schema and notification-list browser checks cover compatibility. Release verification builds the existing package and checks that `src/backend/scripts/run_notifications.php` is included unchanged. No other operational scripts or ML service are added to the hosted package.

## Scheduler evidence and remaining verification

On the development Windows PC, `Get-ScheduledTask` exposed 197 tasks with 199 actions. Every action's task name, executable, arguments and working directory was inspected against `retailmind|php|laragon|run_notifications|run_scheduler|run_maintenance`; no match was found. The Task Scheduler Operational event log was disabled, so no run history was available. PHP CLI 8.5.1 with PDO MySQL was available and Laragon MySQL was running.

The existing daily maintenance wrappers call both forecasting and notifications. The forecasting guide's standalone `run_scheduler.php` action calls forecasting only. The hosted release previously omitted the notification CLI script entirely; it now includes the daily entrypoint. See [daily invocation instructions](../guides/DAILY_NOTIFICATIONS.md).

The current deployment workflow is named Hostinger and uses secret-configured FTP host and application URL. README references to the former InfinityFree deployment do not establish the current server configuration. This session had no hosting control-panel, SSH/cron account, installed hosted job or remote execution-log access. No scheduler was registered or changed. Local task inspection does not establish the deployed scheduler's state.

Still required on the deployed target: verify host scheduler support, uploaded CLI path, execution account/PHP environment, actual daily command and trigger, a successful manual execution, and the next automatic execution's fresh completion log and Inventory Manager reminders. Confirm email delivery separately if enabled. Daily delivery remains unverified until this operational evidence is collected. A CLI script, passing tests or a successful upload alone is insufficient.
