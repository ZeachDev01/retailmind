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

The GitHub Actions workflow in `.github/workflows/ci.yml` runs the full checks on
pushes and pull requests. On a push to `main`, a separate deployment job builds
the production PHP release, checks its PHP syntax and file sizes, then uploads
it to InfinityFree using explicit FTPS. The upload can proceed when an unrelated
full-suite test fails. It does not upload
the database, `.env`, runtime storage, tests, Python service, or development files.

Before the first deployment, configure these in the GitHub repository under
**Settings → Secrets and variables → Actions**:

| Type | Name | Value |
| --- | --- | --- |
| Secret | `INFINITYFREE_FTP_USERNAME` | The FTP username from the InfinityFree account dashboard |
| Secret | `INFINITYFREE_FTP_PASSWORD` | The FTP password from that dashboard |
| Variable | `INFINITYFREE_FTP_SERVER_DIR` | The exact FTP website directory, ending in `/htdocs/` (usually `/htdocs/`, or `/your-domain/htdocs/` for an add-on domain) |

The FTPS server is `ftpupload.net` on port 21. The workflow fails with a clear
message until these values are configured. It never deletes the remote `.env` or
runtime storage. Set up the production `.env` directly on the host and import
the database through phpMyAdmin; those steps are not part of the file upload.
Change the initial Super Administrator password before exposing the site.

**Hosting compatibility:** InfinityFree only permits application files under
`htdocs`, so the app starts in web-only mode when its default private recovery
directory is inaccessible. Database Backup and Restore are unavailable in that
mode; they require private storage outside the web root under ADR-0004. The
Python/Flask forecasting service and scheduled CLI tasks also cannot run on
InfinityFree free hosting. Set `APP_ENV=production` and `APP_DEBUG=false` in the
server's `.env` before using the site.
