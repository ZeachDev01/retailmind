# Hostinger: saved train/test Demo

Use a separate Demo website/subdomain and a new empty database. This snapshot
shows saved training results, predicted-versus-actual sales, stock and experimental
buying previews. It does not activate Store forecasting.

## Files prepared for this session

| File | Purpose |
| --- | --- |
| `work/hostinger-demo/replay-demo.sql` | Committed schema and sanitized Demo data, about 1 MB |
| `work/hostinger-demo/manifest.json` | Exported row counts and SHA-256 checksum |
| `.scratch/hostinger-demo-private/activate-accounts.sql` | Private account activation; not committed |
| `.scratch/hostinger-demo-private/credentials.txt` | New private passwords; not committed |
| `.scratch/hostinger-demo-private/hostinger-demo-import.zip` | Convenience bundle containing SQL, activation, private passwords and this guide; not committed |

The private files belong to this computer and are not in a Git clone. Keep a
private backup of the import ZIP. Do not upload that ZIP, passwords, SQL exports,
`work/`, `.scratch/`, or Git metadata into `public_html`.

## Import the database

1. In the Demo website's hPanel, create a **new database and database user**.
   Record the complete assigned names, hostname and your chosen DB password.
2. Open that database in phpMyAdmin and confirm it has no tables. Use **Import**
   to import `replay-demo.sql`. Do not import the normal `schema.sql` or `update.sql`.
3. Import the private `activate-accounts.sql` into the same database. This sets
   different random passwords for `demo_super_admin`, `demo_admin`,
   `demo_inventory_manager` and `demo_cashier`. Read them in `credentials.txt`.
   The old local password `DemoForecast@2026` does not unlock this export.
4. Keep the activation file and credentials private. The committed SQL alone
   intentionally cannot authenticate an account.

Hostinger's [official phpMyAdmin import instructions](https://www.hostinger.com/support/1884149-how-to-import-a-database-with-phpmyadmin-in-hostinger/)
describe selecting the database, opening Import and choosing the SQL file.
If an import fails partway, create another empty Demo database and retry; do not
continue against a partially imported database or clear an existing Store.

## Install the matching application code

Deploy the code from the commit containing this snapshot, including
`forecast_replay.php` and the updated `ReplenishmentQueueService.php`.
Uploading only SQL will not add the replay screen to an older app version.

Use the existing release layout: root `index.php`, `.htaccess`, `.user.ini`,
`src/.htaccess`, `src/frontend/`, PHP runtime directories under `src/backend/`
and Composer `vendor/`. See `scripts/build-infinityfree-release.sh` for the
existing allowlist/package builder; its PHP runtime layout can also be uploaded
to a Hostinger PHP site. Run `composer install --no-dev --optimize-autoloader`
before packaging. Keep its access-denial `.htaccess` files and create writable
backend log/session directories as required by the normal app setup.

Use an existing supported PHP version for this app with PDO MySQL, Fileinfo
and GD enabled. Configure HTTPS. Do not run the repository's existing automatic
InfinityFree deployment workflow to deploy this separate Hostinger Demo.

Create a private `.env` beside root `index.php`, replacing every placeholder:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-demo.example.com
APP_TIMEZONE=Asia/Manila
DATABASE_ENV=hosted
DB_CONNECTION=mysql
DB_HOST=YOUR_DATABASE_HOST
DB_PORT=3306
DB_NAME=YOUR_FULL_DEMO_DATABASE_NAME
DB_USER=YOUR_FULL_DEMO_DATABASE_USER
DB_PASSWORD="YOUR_DATABASE_PASSWORD"
DB_CHARSET=utf8mb4
SESSION_NAME=retailmind_capstone_demo
SESSION_SECURE_COOKIE=true
FORECAST_REPLAY_DATABASE=YOUR_FULL_DEMO_DATABASE_NAME
```

`FORECAST_REPLAY_DATABASE` must exactly equal the active Demo database name.
It enables only the prepared replay report and experimental preview for that
database, with normal role checks. It does not approve a model, enable production
training or alter normal replenishment quality gates. Leave it unset on a Store.
Keep `APP_ENV=production`; do not use development mode to bypass the replay guard.

## Demonstrate the workflow

1. Visit `https://your-demo.example.com/?login=1` and sign in using the private
   `demo_inventory_manager` password.
2. Open `/components/report/forecast_replay.php`. It should show the train/test
   dates, error scores, product selection and predicted-versus-actual units.
3. Follow **Current stock** or **Simulated sales history**. The initial snapshot
   has 20 products. `UCI-20725` sold 4,550 units and has zero stock remaining.
4. Review **Current stock and experimental buying preview** on the replay page.
   Normal recommendations still use Stock rules because the model failed validation.
5. Sign in as `demo_super_admin`, open **ML Operation**, and inspect the saved
   candidate and errors. Training is disabled for the hosted replay snapshot.

The database contains results from the frozen train-only model
`demo_20261021054643_3c12ae`. It contains no model binary or Python environment.
Hostinger lists Python support on VPS, not Web/Cloud hosting, in its
[language support documentation](https://www.hostinger.com/support/which-programming-languages-and-frameworks-are-supported-at-hostinger/).
Saved SQL results need only the PHP app and database. To produce new results,
train a fresh isolated replay locally and export another snapshot. Do not run
a forecasting worker, retraining cron or Store activation against this snapshot.

## Expected results and limitations

- Train: 5,760 rows, 2025-10-14 through 2026-07-28.
- Test: 1,680 daily product observations, 2026-07-29 through 2026-10-20.
- Saved daily predictions: 2,240 (three test blocks plus one future preview).
- Held-out WAPE: 40.50% / 38.69% / 39.68% for 7 / 14 / 28 days. Lower is better.
- Training validation failed; no model was approved. Preview buying quantities
  are experimental. Original UCI dates were shifted; they are simulated recent sales.
- Opening stock is an illustrative, pre-funded scenario. Revenue/cash are zero
  placeholders and purchase costs are unknown. This does not establish Store accuracy.

This session verified a fresh local SQL restore using a hosting-style DB name,
all four passwords with PHP, stock ledger reconciliation, and the production-mode
database opt-in guard. The existing localhost browser replay also passed.
Automatic approval review blocked starting a temporary production-mode HTTP test
server with the reason "blocked by policy". Actual Hostinger import, hosted login,
PHP configuration and HTTPS behavior still need verification after upload.
No Hostinger resources were modified here.

For the training method and local reproducibility, see the
[train/test replay guide](train-test-sales-replay.md).
