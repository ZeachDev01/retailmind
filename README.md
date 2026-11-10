# RetailMind

Single-Store inventory, receiving, sales, and forecasting application.

Start with the [fresh Windows localhost installation guide](docs/guides/WINDOWS_LOCALHOST_INSTALL.md)
to prepare a Store PC. Use the [project map](docs/PROJECT_STRUCTURE.md) to locate
code and the [documentation index](docs/README.md) for operating guides and
architecture decisions.

Demo forecasting before Store history is available:
[setup, approval, browser review, and verification](docs/guides/DEMO_FORECASTING.md).

## Fresh install summary

1. Install XAMPP with PHP 8.2+ (8.3 recommended), MySQL/MariaDB, Composer, and Git.
2. Clone the current source without history:

   ```powershell
   cd C:\xampp\htdocs
   git clone --depth 1 https://github.com/ZeachDev01/retailmind.git
   ```

3. Copy `.env.example` to `.env` and replace every placeholder.
4. Run `composer install`. The repository does not commit `composer.lock` or
   `vendor/`, so this needs network access.
5. Create an empty database and import
   `src/backend/database/sql/schema.sql`. Never import it into an operating Store.
6. Sign in as `superadmin` / `RetailMind@2026` and change the password immediately.
7. Run `setup_scheduled_tasks.bat` once in an elevated PowerShell to register the
   ML scheduler, daily notifications, and weekly backup.

The full guide covers writable storage, private recovery storage, Recovery Key
custody, Apache access rules, HTTPS, and Cloudflare remote access.

Fresh-install login:

- Username: superadmin
- Password: RetailMind@2026

The seeded account must change this password before the Store is used.

The shared stylesheet is built from the individual files in
`src/frontend/assets/css/`. After editing those files, run
`php src/backend/scripts/build_styles.php` (or `composer build:styles`).
Release packaging rebuilds it. The test suite checks that the checked-in
`style.css` is current.

## Local browser testing

Node.js and npm are optional developer tools. They are not required to serve the
application.

The end-to-end inventory scan workflow uses Playwright with Chromium. It starts
PHP's built-in web server, creates isolated test records in the configured local
database, and removes those records when the test finishes.

Prerequisites:

- PHP and MySQL are running locally.
- The schema and migrations have been applied to the database configured in
  `.env`.
- Node.js 22, 24, or 26 is installed when running browser tests.

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

## Profile pictures and upload limits

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

PHP requires Fileinfo and GD with JPEG, PNG, and WebP support. The repository
includes `.user.ini` for CGI/FastCGI and `.htaccess` settings for Apache
mod_php: `upload_max_filesize=12M`, `post_max_size=16M`, and
`memory_limit=512M`. Hosts with locked settings must permit those limits,
including multipart request overhead. Check the effective web PHP settings after
deployment; CLI settings may differ.

Back up the database and stop Store activity before importing SQL. Stop on the
first error. MySQL/MariaDB DDL commits independently, so a failed import can leave
a partial upgrade; resolve the reported problem and rerun the file.

## Database imports

For the normal local installation, import the fresh schema into an empty
database:

| File | Use | Existing records |
| --- | --- | --- |
| `src/backend/database/sql/update.sql` | Upgrade or repair an existing RetailMind database | Retained, except retired development procurement records and links |
| `src/backend/database/sql/schema.sql` | Install into an empty database | Drops and recreates RetailMind tables; seeds roles and one `superadmin` account only |

A fresh `schema.sql` import leaves product and transaction tables empty. Load the
optional sample catalog with `composer seed:products`; see the
[installation guide](docs/guides/WINDOWS_LOCALHOST_INSTALL.md).

Both SQL files include procurement retirement through
`202610150003_retire_procurement_storage` (October 15, 2026), including grouped
Receiving History, required operational tables, role mappings, settings defaults,
indexes, foreign keys, and historical backfills. Barcode pairing, email logs,
settings, and forecasting SQL dependencies are included too. The update checks
actual table, column, and constraint state even if the migration ledger
incorrectly reports an update as applied. It can be imported again. It requires
the existing base RetailMind tables; it is not a fresh-install script.

The development procurement retirement deletes supplier administration, Supplier
Product Terms, requests, orders, and document links. Receiving quantities,
shopping-store text, receipt references, optional cost history, product planning
defaults, stock/batches, accounts, sales/refunds, customer receipts, and
Protected Audit Records survive. This supersedes #176's preservation scope under
#181; historical ADRs remain records of past decisions. See
[the operator workflow](docs/guides/direct-stock-receiving.md) and
[ADR 0011](docs/adr/0011-retire-development-procurement.md).

Conflicting open shifts, orphaned references, and Stores needing consolidation
must be resolved rather than silently discarded. Neither file needs a hardcoded
database name, stored-procedure permissions, or a separate PHP migration run for
the included updates.

SQL does not install Composer packages, PHP extensions, the optional Python
forecasting worker, `.env` credentials, or private backup storage; configure
those separately. For later migrations, use
`php src/backend/scripts/migrate.php` locally or open
`/src/frontend/components/system_administrator/database_updates.php` as Super
Administrator.

Validation (creates and removes disposable databases only):

```powershell
$env:RUN_DB_TESTS='1'
php src/backend/tests/consolidated_sql_integration.php
php src/backend/tests/procurement_schema_integration.php
```

## Hosted demo material

The Hostinger and InfinityFree deployment procedures are retained as historical
reference only. Automatic deployment to a hosted site has been removed. Current
installation and remote access use a local Windows Store PC and the existing
Cloudflare Tunnel. See the
[saved train/test Demo record](docs/guides/hostinger-demo-replay.md) for earlier
evidence; do not treat it as current deployment instructions.
