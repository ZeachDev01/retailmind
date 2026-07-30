# RetailMind Inventory and Random Forest Forecasting System

RetailMind is a PHP and MySQL inventory system for Shalom Store with three operational roles, barcode sales, stock monitoring, replenishment, reporting, and a Python Random Forest demand-forecasting service.

## Major Random Forest v2 updates

The forecasting module now:

- Builds a complete daily history for each product, including dates with zero sales.
- Uses lag demand, 7/14/30-day rolling averages, rolling volatility, short-versus-long trend, product identity, category, weekday, week, month, weekend, payday, and special-date indicators.
- Uses chronological backtesting instead of a random train/test split.
- Calculates MAE, RMSE, WAPE, SMAPE, R², out-of-bag score, feature importance, and per-product accuracy.
- Produces lower and upper prediction ranges from the individual Random Forest trees.
- Calculates confidence from the width of each product’s prediction range rather than from the global R² score.
- Stores transparent reorder calculations and their inventory inputs.
- Supports configurable model settings and conditional automatic retraining.

The PHP application now includes:

- Forecast Analytics and Data Readiness dashboards.
- ML settings and training-run history.
- API-key authentication for the Flask service.
- Secure barcode pairing tokens and restricted cross-origin requests.
- Internal barcode generation and printable Code 128 product labels for products without manufacturer barcodes.
- Login throttling, temporary lockout, password requirements, session invalidation, and password reset.
- SMTP email notifications through PHPMailer.
- Store and receipt configuration.
- Database backup, validated restore, automatic safety backup, and backup history.
- Maintenance scripts for retraining, notifications, and scheduled backups.

## Required upgrade steps

For an existing installation:

1. Back up the current database.
2. Import `sql/upgrade_random_forest_v2.sql` in phpMyAdmin.
3. Copy `.env.example` to `.env` and enter database, ML API, and email settings.
4. Use the same long `ML_API_KEY` value for the PHP application and the Python service.
5. Install PHP email dependencies:

```bash
composer install --no-dev
```

6. Install Python dependencies:

```bash
cd ml
python -m pip install -r requirements.txt
```

7. Retrain the new model:

```bash
python train_model.py
```

8. Start the local Flask service:

```bash
python predict_api.py
```

The health endpoint is public at `http://127.0.0.1:5000/health`. Prediction, metrics, and retraining endpoints require the `X-API-Key` header.

## Fresh database installation

Import `sql/schema.sql` into an empty selected MySQL or MariaDB database. The schema contains the v2 security, settings, forecast, training-history, email-log, and backup-history tables.

The initial account in the schema is:

```text
Username: superadmin
Password: RetailMind@2026
```

Change this password immediately after the first login.

## Forecast data requirements

Default readiness requirements are configurable under **Administration → ML Settings**:

- At least 30 complete calendar days from the first recorded sale.
- At least 5 dates with nonzero sales.
- Up to 365 recent calendar days used for training.
- A preferred history target of 90 calendar days.

Zero-sales dates are valid training observations and are displayed in **Reports → Data Readiness**.

## Forecast and reorder outputs

Each eligible product stores:

- 7-day and 30-day expected demand.
- Lower and upper prediction ranges.
- Supplier-lead-time expected demand and range.
- Product-level MAE, RMSE, WAPE, and SMAPE.
- Current stock, incoming stock, safety stock, MOQ, and package size used.
- Suggested reorder quantity.

The reorder calculation is:

```text
Lead-time demand + safety stock - current stock - incoming stock
```

A positive result is rounded up to respect minimum order quantity and package size.

## Barcode generation and printing

Inventory Managers and Administrators can generate an internal RetailMind barcode when a product has no manufacturer barcode. In **Products & Stock**, the barcode may be scanned, entered manually, generated before saving, or left blank for automatic generation.

The **Print Barcode Labels** page supports:

- Code 128 labels that work with the existing POS barcode lookup.
- 1 to 200 copies per print job.
- 40 × 25 mm, 50 × 30 mm, and 60 × 40 mm label layouts.
- Product name, barcode value, brand/category, and selling price on each label.
- Barcode generation during CSV product imports when the barcode column is blank.

No database migration is required for this feature.

## Automatic maintenance

### Windows Task Scheduler

Create a daily task that runs:

```text
C:\xampp\htdocs\inventory_system\scripts\run_maintenance.bat
```

Create a separate weekly task for backups:

```text
php C:\xampp\htdocs\inventory_system\scripts\backup_database.php
```

### Linux cron

Example daily maintenance and weekly backup:

```cron
15 1 * * * /path/to/inventory_system/scripts/run_maintenance.sh
30 2 * * 0 php /path/to/inventory_system/scripts/backup_database.php
```

`ml/auto_retrain.py` retrains only when one or more configured conditions are met: missing model, model age, enough new sales records, or WAPE above the threshold.

## Email setup

Configure these values in `.env`:

```text
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-account
MAIL_PASSWORD=your-password-or-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME=RetailMind
```

Then use **Administration → System Settings → Email delivery test**.

Email and in-app alerts can cover low stock, replenishment approval, expiring batches, unusual forecast spikes, and model-training failures. Delivery attempts are written to `email_delivery_log`.

## Backup and restore

Administrators can open **Administration → Backup & Restore** to:

- Create and download a portable SQL backup.
- Save a server-side backup history copy.
- Validate and restore RetailMind-generated SQL files.
- Automatically create a safety backup before a restore.

Scheduled backups are created by `scripts/backup_database.php` under `storage/backups`.

## Security notes

- Do not upload `.env`, `.git`, logs, saved model artifacts, or database backups to a public repository.
- The production ZIP intentionally excludes those files.
- Use HTTPS and set `SESSION_SECURE_COOKIE=true` in production.
- Keep the Flask API bound to `127.0.0.1` unless it is protected by a reverse proxy and firewall.
- Add external barcode origins only through `BARCODE_ALLOWED_ORIGINS`; same-origin scanning needs no additional value.
- Changing a password or disabling an account increments its session version and invalidates existing sessions.

## Main folders

```text
admin/          Administration, ML settings, store settings, backup/restore
cashier/        Point of sale and cashier dashboard
manager/        Products, inventory, CSV import, counts, replenishment
report/         Forecasts, analytics, readiness, operational reports
ml/             Random Forest training, API, and automatic retraining
scripts/        Notifications, maintenance, and scheduled backup commands
sql/            Fresh schema and v2 migration
storage/        Logs, generated backups, imports, and exports
```
