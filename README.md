Hi

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

The GitHub Actions workflow in `.github/workflows/ci.yml` runs the full checks on
pushes and pull requests. On a push to `main`, a separate deployment job builds
the production PHP release, checks its PHP syntax and file sizes, then uploads
it to InfinityFree using explicit FTPS. The upload can proceed when an unrelated
full-suite test fails. It does not upload
the database, `.env`, runtime storage, tests, Python service, or development files.

Before the first deployment, configure these in the GitHub repository under
**Settings → Secrets and variables → Actions**:

| Type     | Name                          | Value                                                                                                                      |
| -------- | ----------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| Secret   | `INFINITYFREE_FTP_USERNAME`   | The FTP username from the InfinityFree account dashboard                                                                   |
| Secret   | `INFINITYFREE_FTP_PASSWORD`   | The FTP password from that dashboard                                                                                       |
| Variable | `INFINITYFREE_FTP_SERVER_DIR` | The exact FTP website directory, ending in `/htdocs/` (usually `/htdocs/`, or `/your-domain/htdocs/` for an add-on domain) |

The FTPS server is `ftpupload.net` on port 21. The workflow fails with a clear
message until these values are configured. It never deletes the remote `.env` or
runtime storage. Set up the production `.env` directly on the host and import
the database through phpMyAdmin; those steps are not part of the file upload.
Change the initial Super Administrator password before exposing the site.

### Deploy the same code to a member's InfinityFree account

In the same repository's **Settings → Secrets and variables → Actions**, add:

| Type | Name | Value |
| ---- | ---- | ----- |
| Secret | `INFINITYFREE_MEMBER_FTP_USERNAME` | Your member's InfinityFree FTP username |
| Secret | `INFINITYFREE_MEMBER_FTP_PASSWORD` | Your member's InfinityFree FTP password |
| Variable | `INFINITYFREE_MEMBER_FTP_SERVER_DIR` | Their website's exact FTP directory, ending in `/htdocs/` |
| Variable | `INFINITYFREE_MEMBER_DEPLOY_ENABLED` | `true` to enable the second deployment |

Once enabled, every push to `main` deploys the same commit to both accounts in
independent jobs. A failed deployment to one account does not cancel the other.
Leave the enable variable unset or set it to `false` to deploy only to the
original account. The member must configure their own hosted `.env` (including
`APP_URL` and database credentials), import the schema, and apply the database
updates described below. Each account keeps its own database and runtime storage.

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
