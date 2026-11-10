# Fresh-install deployment checklist

Use this checklist to accept a new Windows Store PC installation. The complete
procedure is in the
[fresh Windows localhost installation guide](guides/WINDOWS_LOCALHOST_INSTALL.md).

## Before installation

- [ ] Windows is 64-bit and XAMPP provides Apache, MySQL/MariaDB, and PHP 8.2+
  (8.3 recommended).
- [ ] PHP has `fileinfo`, `gd` with JPEG/PNG/WebP support, `pdo_mysql`, `openssl`,
  and `mbstring`.
- [ ] Composer and Git are installed. Node.js, npm, and Python are optional.
- [ ] The source is a clone at `C:\xampp\htdocs\retailmind`. A shallow clone uses
  `git clone --depth 1 https://github.com/ZeachDev01/retailmind.git`.
- [ ] `.env` was copied from `.env.example`, every placeholder is replaced, and
  the file is not tracked.

## Application and database

- [ ] `composer install` completed. `composer.lock` and `vendor/` are not
  committed; network access is required.
- [ ] The target database was empty before importing
  `src/backend/database/sql/schema.sql`. It is never imported into an operating
  Store; existing databases use `update.sql` after a backup.
- [ ] The import seeded roles and one `superadmin` account. Product and transaction
  tables are empty unless the optional `composer seed:products` command was used.
- [ ] `php src/backend/scripts/migrate.php` reports no pending migration.
- [ ] Sign-in as `superadmin` / `RetailMind@2026` works and forces a password
  change. The new password works after signing out.

## Storage and recovery

- [ ] Apache and the scheduled-task account can write to the storage directories
  under `src/backend/storage/`.
- [ ] `BACKUP_STORAGE_PATH` is absolute, persistent, outside the application and
  web root, and shared by Apache and CLI recovery commands.
- [ ] A manual Database Backup completes and validates.
- [ ] The Recovery Key is downloaded and stored off the Store PC, separate from the
  backup packages.
- [ ] The weekly Scheduled Backup day, time, and destination are reviewed.

## Background jobs

- [ ] `setup_scheduled_tasks.bat` registered `RetailMind ML Scheduler`,
  `RetailMind Daily Notifications`, and `RetailMind Weekly Backup`.
- [ ] Each task has a successful manual run and a new log entry under
  `src/backend/storage/logs/`.
- [ ] Python is either installed for forecasting or explicitly accepted as
  unavailable. Notifications and backups do not depend on Python.

## Web access and remote access

- [ ] Apache denies `.env`, backend paths, logs, SQL files, backup packages, and
  model artifacts.
- [ ] The local URL loads over HTTP, or HTTPS is configured before production use.
- [ ] `SESSION_SECURE_COOKIE=true` when HTTPS is enabled; `false` only for plain
  HTTP localhost.
- [ ] Cloudflare Tunnel setup uses the generated Store PC block. `cloudflared.exe`
  is downloaded on demand, not present in a fresh clone.
- [ ] Remote sign-in reaches the intended Workspace. `admin`,
  `inventory_manager`, and `cashier` keep their localhost capabilities; the Super
  Administrator stays local-only.

## Repository checks

- [ ] `npm run check` passes.
- [ ] `npm run check:contracts` passes.

## Machine-level acceptance

The following need the actual Store PC and cannot be proven by a documentation
review:

- [ ] Apache and MySQL start after a reboot and after power loss.
- [ ] Scheduled tasks run with no interactive user signed in.
- [ ] The real barcode scanner and thermal printer work.
- [ ] Cloudflare account, token, DNS, Access policy, and HTTPS are correct.
- [ ] A restore on replacement hardware meets the recovery target.
