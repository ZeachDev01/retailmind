Fresh-install login:

- Username: superadmin
- Password: RetailMind@2026

## Local browser testing

The end-to-end inventory scan workflow uses Playwright with Chromium. It starts
PHP's built-in web server, creates isolated test records in the configured local
database, and removes those records when the test finishes.

Prerequisites:

- PHP and MySQL are running locally.
- The schema and migrations have been applied to the database configured in
  `.env`.
- Node.js 22, 24, or 26 is installed.

Install the project dependencies and Chromium once:

```powershell
npm install
npm run playwright:install
```

Run the browser workflow:

```powershell
npm run test:e2e
```

The test must only be pointed at a disposable local or CI database. Do not use
production database credentials in `.env` when running it.

## Cash Refund database testing

The refund race test creates a temporary database from the canonical table
definitions, runs competing refunds, checks legacy reversal isolation, and drops
the temporary database. Use a local MySQL/MariaDB account with permission to create
and drop databases. It reads connection settings from `.env` and does not write
to the configured Store database.

```powershell
$env:RUN_REFUND_DB_TESTS = '1'
php src/backend/tests/cash_refund_concurrency_integration.php
```

The same flag enables this test in `src/backend/tests/run_all.sh`.

## InfinityFree upload pipeline

Profile pictures accept still JPG, PNG, and WebP originals up to 5 MB (5,242,880
bytes). GIF and animated PNG/WebP uploads are rejected; existing pictures remain
viewable. Images must decode successfully and stay within 8,000 pixels per side
and 40 million pixels total. Uploads are validated before any picture or account
change; no client crop replaces the original-file validation.

Profile picture selection opens a square preview. Adjust Zoom, Horizontal
position, and Vertical position, then Save picture or Cancel. Only Save uploads
the original and crop coordinates; the server validates both and saves the
selected area as a 512 × 512 still PNG. Cancel leaves the saved picture unchanged.
Run `node src/backend/tests/profile_picture_crop_browser_test.js` to verify the
HTTP upload/crop flow with Chromium and an isolated temporary SQLite database;
this test never connects to the Store database. PHP needs PDO SQLite for it.

PHP requires Fileinfo and GD with JPEG, PNG, and WebP support. The release ships
`.user.ini` for CGI/FastCGI and `.htaccess` settings for Apache mod_php:
`upload_max_filesize=5M`, `post_max_size=8M`, and `memory_limit=256M`. Hosts with
locked settings must permit those limits, including multipart request overhead.
Check the effective web PHP settings after deployment; CLI settings may differ.

On a push to branch `1`, the GitHub Actions workflow in `.github/workflows/ci.yml`
creates the production `.env` from repository secrets and uploads the project to
InfinityFree over FTP.

Before the first deployment, configure these in the GitHub repository under
**Settings → Secrets and variables → Actions**:

| Type | Name | Value |
| --- | --- | --- |
| Secret | `FTP_SERVER` | The FTP hostname from the InfinityFree account dashboard (usually `ftpupload.net`) |
| Secret | `FTP_USERNAME` | The FTP username from the InfinityFree account dashboard |
| Secret | `FTP_PASSWORD` | The FTP password from that dashboard |
| Secret | `DB_HOST` | The MySQL hostname from the InfinityFree MySQL Databases page |
| Secret | `DB_NAME` | The complete InfinityFree database name |
| Secret | `DB_USER` | The InfinityFree MySQL username |
| Secret | `DB_PASSWORD` | The InfinityFree hosting/database password |

The workflow uploads to `/htdocs/` over explicit FTPS on port 21 and fails with a clear
message until these values are configured. It generates `.env` during deployment;
the database itself must still be imported through phpMyAdmin.
Change the initial Super Administrator password before exposing the site.

If the hosted page says "RetailMind cannot reach its data", read the latest
error in `htdocs/src/backend/storage/logs/app.log` through the hosting File
Manager. Keep `APP_DEBUG=false` on the live site. Confirm `htdocs/.env` exists
and its `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` match the account's
MySQL Databases page; use the full database name and the exact MySQL hostname,
not the website or FTP hostname. For automated deployments, correct the GitHub
secrets above and redeploy, since each deployment replaces `.env`.
The environment loader supports hosting with `putenv()` disabled.

After importing `src/backend/sql/schema.sql` into a new hosted database, sign in
as Super Administrator and open
`/src/frontend/components/system_administrator/database_updates.php`. Apply the
pending database updates before using the workspaces. This uses the same
idempotent migrations as `php src/backend/scripts/migrate.php` on local or CI
installations and does not replace existing users or their passwords.

**Hosting compatibility:** InfinityFree only permits application files under
`htdocs`, so the app starts in web-only mode when its default private recovery
directory is inaccessible. Database Backup and Restore are unavailable in that
mode; they require private storage outside the web root under ADR-0004. The
Python/Flask forecasting service and scheduled CLI tasks also cannot run on
InfinityFree free hosting. Set `APP_ENV=production` and `APP_DEBUG=false` in the
server's `.env` before using the site.
