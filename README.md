# RetailMind

Single-Store inventory, receiving, sales, and forecasting application.

Start with the [project map](docs/PROJECT_STRUCTURE.md) to locate code,
the [documentation index](docs/README.md) for operating guides, or
the [development work index](work/README.md) for datasets and retained artifacts.

Demo forecasting before Store history is available:
[setup, approval, browser review, and verification](docs/guides/DEMO_FORECASTING.md).

See the [documentation index](docs/README.md) for deployment, recovery,
interface guides, architecture decisions, and release history.

The shared stylesheet is built from the individual files in
`src/frontend/assets/css/`. After editing those files, run
`php src/backend/scripts/build_styles.php` (or `composer build:styles`).
Release packaging and deployment rebuild it automatically; the test suite checks
that the checked-in `style.css` is current.

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

On each push to `main`, `.github/workflows/ci.yml` builds the PHP release,
checks deployment isolation and database configuration, then uploads `.release/`
to the [Hosted Demo](https://antiquewhite-cattle-865506.hostingersite.com).
A local commit deploys after it is pushed to GitHub. Manual redeployment is
available under **Actions > Deploy to Hostinger > Run workflow**.

FTPS uses `srv2062.hstgr.io:21` with strict certificate verification. Create an
additional FTP account restricted to the Demo's `public_html` directory, then
configure these repository secrets under **Settings > Secrets and variables >
Actions**:

| Secret | Value |
| --- | --- |
| `HOSTINGER_DEMO_FTP_USERNAME` | Complete assigned username of the Demo-only FTP account |
| `HOSTINGER_DEMO_FTP_PASSWORD` | Password of that FTP account |

The FTP account's root must be
`/home/u125532033/domains/antiquewhite-cattle-865506.hostingersite.com/public_html`;
the workflow uploads to `./` inside it. Do not use the main account with access
to every website. Existing generic `FTP_*` and `DB_*` repository secrets are
not used by this deployment.

Deployments preserve the hosted `.env`, sessions, uploads and logs. Keep
`APP_ENV=production`, `APP_DEBUG=false`, `DATABASE_ENV=hosted`, the Demo database
credentials and `FORECAST_REPLAY_DATABASE` in the private hosted `.env`.
Database migrations and Demo SQL imports are never run automatically. Never
import `schema.sql` or a Demo snapshot into an operating Store.

Uploads run one at a time without cancelling an active upload. After upload,
the workflow checks the HTTPS login page and confirms `/.env` returns 403.
These checks do not authenticate or verify database contents. The separate
Playwright workflow tests `playwright.dev` examples and does not gate deployment.

For a hosted database error, read
`public_html/src/backend/storage/logs/app.log` in hPanel. Correct the private
hosted `.env`; redeployment deliberately leaves it unchanged. See the
[Hosted Demo guide](docs/guides/hostinger-demo-replay.md) for the isolated snapshot.

### Single-run database imports

Select your RetailMind database in phpMyAdmin, then use **Import** with one file:

| File                         | Use                                               | Existing records                                                           |
| ---------------------------- | ------------------------------------------------- | -------------------------------------------------------------------------- |
| `src/backend/database/sql/update.sql` | Upgrade or repair an existing RetailMind database | Retained, except retired development procurement records and links                      |
| `src/backend/database/sql/schema.sql` | Install into an empty database                    | Drops and recreates RetailMind tables; includes seed accounts and products |

Both files include procurement retirement through `202610150003_retire_procurement_storage`
(October 15, 2026), including grouped Receiving History, required operational tables, role mappings, settings defaults,
indexes, foreign keys, and historical backfills. Barcode pairing, email logs,
settings, and forecasting SQL dependencies are included too. The update checks actual table,
column, and constraint state even if the migration ledger incorrectly reports an
update as applied. It can be imported again. It requires the existing base
RetailMind tables; it is not a fresh-install script.

The development procurement retirement deletes supplier administration, Supplier Product Terms,
requests, orders, and document links. Receiving quantities, shopping-store text, receipt
references, optional cost history, product planning defaults, stock/batches, accounts,
sales/refunds, customer receipts, and Protected Audit Records survive. This supersedes
#176’s preservation scope under #181; historical ADRs remain records of past decisions.
See [the operator workflow](docs/guides/direct-stock-receiving.md) and
[ADR 0011](docs/adr/0011-retire-development-procurement.md).

Back up the database and stop Store activity before importing the update. Stop
on the first error. MySQL/MariaDB DDL commits independently, so a failed import
can leave a partial upgrade; resolve the reported problem and rerun the file.
Conflicting open shifts, orphaned references, and Stores needing consolidation
must be resolved rather than silently discarded. Neither file needs a hardcoded
database name, stored-procedure permissions, or a separate PHP migration run for
the included updates.

SQL does not install Composer packages, PHP extensions, the Python forecasting
service, `.env` credentials, or private backup storage; configure those separately.
For later migrations, use `php src/backend/scripts/migrate.php` locally or open
`/src/frontend/components/system_administrator/database_updates.php` as Super
Administrator. Change the seeded password after a fresh installation.

Validation (creates and removes disposable databases only):

```powershell
$env:RUN_DB_TESTS='1'
php src/backend/tests/consolidated_sql_integration.php
php src/backend/tests/procurement_schema_integration.php
```

**Hosting compatibility:** InfinityFree only permits application files under
`htdocs`, so the app starts in web-only mode when its default private recovery
directory is inaccessible. Database Backup and Restore are unavailable in that
mode; they require private storage outside the web root under ADR-0004. The
Python/Flask forecasting service and scheduled CLI tasks also cannot run on
InfinityFree free hosting. Set `APP_ENV=production` and `APP_DEBUG=false` in the
server's `.env` before using the site.
