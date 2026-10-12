# Platform health, migrations, and logs

Open **Platform service is unhealthy → System Health**. By default, any one non-healthy check outside the Forecasting category triggers this dashboard warning. Forecasting checks feed separate forecast-related attention. System Health reports current conditions and has no manual-clear, migration-execution, or log-management button.

Resolve the check named in System Health, select **Refresh checks**, then refresh the dashboard. Examples include restoring the database connection, applying pending migrations, correcting storage permissions, configuring the email provider, or recording a current-format Database Backup. The warning clears when the number of non-healthy platform checks falls below the configured threshold; marking a notification read does not change health.

## Run migrations from the repository root

Create and retain a Database Backup first. Start MySQL/MariaDB in Laragon and ensure the application database settings are correct. Open PowerShell:

```powershell
Set-Location C:\laragon\www\retailmind
& "C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe" src/backend/scripts/migrate.php
```

If PHP is already available on PATH, use `php src/backend/scripts/migrate.php`. The runner applies only pending migrations and records completed migration keys. Expect `Applied N migration(s) successfully.` or `Database is already up to date.` If it reports failure, retain the output and investigate before retrying; database schema changes may already have partially applied. Refresh System Health after success.

The existing UI command `php backend/scripts/migrate.php` assumes the working directory is `src`; it is not the correct path from the repository root. These instructions do not execute migrations.

## Diagnose application errors

`src/backend/storage/logs/app.log` holds application diagnostics, including exceptions and stack traces. Read recent entries with:

```powershell
Get-Content .\src\backend\storage\logs\app.log -Tail 60
```

Compare timestamps with the failing action, identify the first relevant error, fix its cause, then repeat that action and check for new entries. Retain useful evidence; redact credentials, tokens, and private details before sharing logs. Archive logs before maintenance rotation rather than losing diagnostic history.

The Application log health check is intentionally simple in the current implementation: a non-empty log updated within the last 24 hours reports a warning. It does not interpret error severity or determine whether an error was fixed. An empty or missing log, or a log whose last update is more than 24 hours old, reports healthy. After fixing an error, this check can therefore remain in warning until 24 hours pass without another log update. Deleting the file would remove diagnostic evidence and would not resolve an ongoing failure.

Protected Audit Records are separate from application diagnostics. They record attributable actions and review decisions, can be inspected and exported through the application, and cannot be individually edited or deleted there.
