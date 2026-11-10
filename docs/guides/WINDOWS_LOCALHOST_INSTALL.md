# Fresh Windows localhost installation

Use this guide to install RetailMind from a GitHub clone on a Windows Store PC.
The PC hosts both the application and its MySQL/MariaDB database. Cloudflare
Tunnel provides optional remote access to the same installation.

> **Verification status:** this procedure was derived from the current source
> tree. It has not been executed on a fresh Store PC as part of this work. Treat
> the checklist at the end as the acceptance test for the actual machine.

For a shorter acceptance list, see the
[deployment checklist](../DEPLOYMENT_CHECKLIST.md). For code locations, see the
[project structure](../PROJECT_STRUCTURE.md).

## 1. Required software

| Component | Requirement |
| --- | --- |
| Windows | Windows 10/11, 64-bit |
| Web server | XAMPP with Apache and MySQL/MariaDB |
| PHP | PHP 8.2 or newer; PHP 8.3 is the recommended tested version |
| PHP extensions | `fileinfo`, `gd` with JPEG/PNG/WebP support, `pdo_mysql`, `openssl`, `mbstring` |
| Composer | Required to install PHP dependencies |
| Git | Required to clone the source |
| Node.js and npm | Optional; developer and browser-test use only |
| Python | Optional; forecasting worker and model training only |

`composer.json` accepts PHP 8.1, but encrypted backup and recovery need PHP 8.2
or newer. Enable the extensions in `C:\xampp\php\php.ini`, then restart Apache.
Confirm them from a terminal:

```powershell
php -v
php -m
```

The application serves normal pages without Node.js or Python. Install them
only when you run browser tests, train models, or use the ML worker.

## 2. Clone the source

Use a shallow clone for a Store PC. It downloads the current source without the
full commit history:

```powershell
cd C:\xampp\htdocs
git clone --depth 1 https://github.com/ZeachDev01/retailmind.git
```

Place the checkout at `C:\xampp\htdocs\retailmind`. If the folder already exists,
clone under another name or move the checkout before continuing.

The shallow clone stays small only when you create it this way. An existing full
clone does not shrink, and Git history remains available in the remote
repository.

## 3. Create the environment file

Copy the template at the repository root:

```powershell
cd C:\xampp\htdocs\retailmind
Copy-Item .env.example .env
```

Edit `.env` and replace every placeholder. The template contains:

| Key | Purpose |
| --- | --- |
| `APP_NAME` | Display name; keep `RetailMind` |
| `APP_ENV` | `development` for a local install; use `production` only with HTTPS and `APP_DEBUG=false` |
| `APP_DEBUG` | Keep `false` for normal operation |
| `APP_URL` | Local URL, for example `http://localhost/retailmind` |
| `APP_TIMEZONE` | `Asia/Manila` for Store operations |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST`, `DB_PORT` | Local database host and port, usually `localhost` and `3306` |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Dedicated database and account created in step 5 |
| `DB_CHARSET` | `utf8mb4` |
| `ML_API_URL`, `ML_API_KEY`, `ML_API_TIMEOUT` | ML worker connection values; only needed for Python forecasting |

Add these keys when they are not already present:

```dotenv
DATABASE_ENV=local
BACKUP_STORAGE_PATH=C:\xampp\retailmind-private
SESSION_SECURE_COOKIE=false
```

- `DATABASE_ENV=local` prevents hosted-database safeguards from treating the
  Store as a hosted deployment.
- `BACKUP_STORAGE_PATH` must be an absolute, persistent directory outside both
  the project and the web root. See step 8.
- Keep `SESSION_SECURE_COOKIE=false` on plain HTTP localhost. Set it to `true`
  when the installation uses HTTPS, including through a tunnel.

Never commit `.env`. The root `.htaccess` denies direct web access to it.

## 4. Install PHP dependencies

The repository does not commit `composer.lock` or `vendor/`. Composer must reach
the network and resolve dependencies:

```powershell
cd C:\xampp\htdocs\retailmind
composer install
```

For a production-style install, use:

```powershell
composer install --no-dev --optimize-autoloader
```

Do not copy `vendor/` from another PC unless it was built for the same PHP
version and extensions.

## 5. Create an empty database and import the schema

Create a dedicated database and database user in phpMyAdmin or MySQL. Do not
reuse an operating Store database for a fresh installation.

1. Start Apache and MySQL in XAMPP.
2. Open `http://localhost/phpmyadmin`.
3. Create a database, for example `retailmind`, with `utf8mb4` collation.
4. Create a database user with full privileges on that database only.
5. Put those credentials in `.env`.

Import the fresh schema into the empty database:

1. Select the new database in phpMyAdmin.
2. Open **Import**.
3. Choose `src/backend/database/sql/schema.sql`.
4. Import the file and stop on the first error.

`schema.sql` drops and recreates the RetailMind tables. It creates the role
records (`super_admin`, `admin`, `inventory_manager`, `cashier`), one
`superadmin` account, Store settings, categories, platform defaults, and the
migration ledger. Product and transaction tables start empty.

Never import `schema.sql` into an existing or operating Store. For an existing
database, back it up and use `src/backend/database/sql/update.sql` instead.

After the schema import, run any pending PHP migrations:

```powershell
php src/backend/scripts/migrate.php
```

The fresh schema already records current migrations. This command is the normal
path for later source updates and for an installation assembled from an older
schema.

### Optional sample products

To load the example product catalog:

```powershell
composer seed:products -- --confirm-local-database=retailmind
```

Replace `retailmind` with the database name in `.env`. The seed refuses to run
unless `APP_ENV` is `local` or `development`, the database host is loopback, and
the confirmation matches the configured database name. The equivalent direct
command is:

```powershell
php src/backend/scripts/seed_products.php --confirm-local-database=retailmind
```

The seed does not create sales history. `composer seed:sales-trend` is a
separate local Demo command and is not part of a Store installation.

## 6. First sign-in

Open the application URL and sign in:

- Username: `superadmin`
- Password: `RetailMind@2026`

The seeded account has `must_change_password` set. Change the password
immediately, before any other configuration. Create the operational
Administrator account from the Super Administrator Workspace after the change.

## 7. Writable storage

Apache or the PHP CLI must be able to write to the runtime directories under
`src/backend/storage/`:

```text
backups
bin
exports
images
imports
logs
profile-images
receipts
sessions
tunnel
```

Most directories contain tracked `.gitkeep` placeholders. `profile-images`
contains its own access-denial `.htaccess`. Grant modify rights to the Apache
service account and to the account that runs scheduled tasks. Do not grant web
access to any of these directories.

## 8. Private recovery storage and keys

Database Backup and Restore require private storage outside the application and
web document root. Set one absolute path for the whole installation, for
example:

```dotenv
BACKUP_STORAGE_PATH=C:\retailmind-private
```

All PHP workers and recovery commands must share the same path. If the key is
missing or the path is inaccessible, Backup and Restore are unavailable.

After the first backup:

1. Download the Recovery Key and keep a copy off the Store PC.
2. Keep the encrypted package and its key under separate custody.
3. Confirm the key acknowledgment prompt in the application.
4. Review the weekly Scheduled Backup weekday, time, and destination in Platform
   Settings. The default is Sunday at 23:00 Asia/Manila; a blank destination uses
   the private recovery store.

Losing every Recovery Key copy makes the packages unrecoverable. See
[Database Backup and recovery](../BACKUP_RECOVERY.md),
[package format limits](../STORE_PACKAGE_FORMAT.md), and
[ADR-0028](../adr/0028-scheduled-weekly-backups-and-managed-key-custody.md).

## 9. Background jobs

Run the combined installer from the repository root in an elevated PowerShell:

```powershell
cd C:\xampp\htdocs\retailmind
.\setup_scheduled_tasks.bat
```

The wrapper calls
`src/backend/scripts/install_scheduled_tasks.ps1`. By default it registers three
Windows tasks that run as `NT AUTHORITY\SYSTEM`:

| Task | Schedule | Work |
| --- | --- | --- |
| `RetailMind ML Scheduler` | Every 5 minutes and at startup | Runs the forecasting scheduler and queued ML jobs |
| `RetailMind Daily Notifications` | Daily at 03:00, with catch-up | Runs `run_notifications.php` for the Daily Stock Brief and expiry reminders |
| `RetailMind Weekly Backup` | Hourly check and at startup | Runs `run_scheduled_backup.php`; the due weekday and time come from Platform Settings |

The installer also enables Store email alerts in the database. Python is
optional: without it, notifications, backups, and ordinary PHP scheduling still
run, while model training and ML jobs remain unavailable.

Logs are written under `src/backend/storage/logs/`:

```text
forecasting-scheduler.log
daily-notifications.log
weekly-backup.log
```

Verify the installed tasks:

```powershell
Get-ScheduledTask -TaskName 'RetailMind ML Scheduler'
Get-ScheduledTaskInfo -TaskName 'RetailMind ML Scheduler'
Get-ScheduledTask -TaskName 'RetailMind Daily Notifications'
Get-ScheduledTaskInfo -TaskName 'RetailMind Daily Notifications'
Get-ScheduledTask -TaskName 'RetailMind Weekly Backup'
Get-ScheduledTaskInfo -TaskName 'RetailMind Weekly Backup'
```

A task state of `Ready` only proves registration. Inspect the log after a manual
run and again after the next scheduled time.

`src/backend/scripts/install_forecasting_scheduler.ps1` remains available as a
manual alternative for the ML task only. It runs under the current Windows
account, so it runs only while that user is signed in. Prefer the combined
installer for a Store PC. See
[Forecasting scheduler](FORECASTING_SCHEDULER.md) and
[Daily notifications](DAILY_NOTIFICATIONS.md).

## 10. Apache and HTTPS

The repository includes `.htaccess` files that deny `.env`, Git metadata, SQL,
logs, backup packages, model artifacts, and direct paths into backend code.
Apache must allow `.htaccess` overrides for the checkout; XAMPP does this by
default.

The root `.user.ini` and `.htaccess` set upload and memory limits. Confirm the
effective web values after changing PHP configuration; CLI values can differ.

For a Store PC used only on localhost, plain HTTP is acceptable. Set
`SESSION_SECURE_COOKIE=false` in that case. Before any HTTPS or tunnel use:

1. Enable HTTPS for the public hostname.
2. Set `APP_ENV=production` only when the public site is ready.
3. Set `SESSION_SECURE_COOKIE=true`.
4. Restart Apache and sign in again.

## 11. Cloudflare remote access

Remote access is optional. The supported path is **Platform Settings** &rarr;
**Remote Access** in the Super Administrator Workspace:

1. Create a tunnel and public hostname in Cloudflare Zero Trust.
2. Save the tunnel token and hostname.
3. Generate the Store PC setup block.
4. Run the block once in an elevated PowerShell on the Store PC.

`cloudflared.exe` is downloaded on demand from the official Cloudflare GitHub
release. It is not committed and is not present in a fresh clone. Do not copy it
from another machine unless the download is unavailable and the same binary is
needed for an offline repair.

Tunnel sessions keep full localhost Workspace capabilities for `admin`,
`inventory_manager`, and `cashier`, including writes, exports, and receipt
printing. The Super Administrator Workspace stays local-only. See
[the remote tunnel guide](../DEPLOYMENT_REMOTE_TUNNEL.md) and
[ADR-0030](../adr/0030-tunnel-sessions-have-full-workspace-parity.md).

## 12. Verification checklist

- [ ] `php -v` reports PHP 8.2 or newer; `php -m` lists the required extensions.
- [ ] The clone is at `C:\xampp\htdocs\retailmind` and `.env` is not tracked.
- [ ] `composer install` completes without network or extension errors.
- [ ] The database was empty before `schema.sql` was imported.
- [ ] Sign-in with `superadmin` / `RetailMind@2026` works and forces a password change.
- [ ] The new password works after signing out.
- [ ] Storage directories are writable; the application log records no permission error.
- [ ] `BACKUP_STORAGE_PATH` is outside the web root and a manual backup completes.
- [ ] The Recovery Key is downloaded and stored off the Store PC.
- [ ] `setup_scheduled_tasks.bat` registers all three tasks.
- [ ] A manual run of each task writes a new log entry with a successful result.
- [ ] `npm run check` and `npm run check:contracts` pass when Node is available.
- [ ] A tunnel login reaches the intended Workspace; the Super Administrator stays local-only.
- [ ] `SESSION_SECURE_COOKIE=true` after HTTPS is enabled.

## 13. What still needs the real Store PC

The following require the actual machine, network, hardware, and accounts. They
cannot be proven by reading this guide:

- XAMPP and Apache startup after a Windows reboot or power loss.
- Scheduled tasks running while no user is signed in.
- The real barcode scanner and thermal printer.
- Cloudflare account, token, DNS, and Access policy setup.
- Database restore on replacement hardware.
- Recovery within the target time with real Store data and keys.

Record the observed result for each item. Do not report a documentation review as
a completed Store PC installation.
