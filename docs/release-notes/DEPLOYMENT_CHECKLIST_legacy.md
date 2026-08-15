# Production deployment checklist

- Import `sql/schema.sql` for a new database or `sql/upgrade_random_forest_v2.sql` for an existing database.
- Run `php scripts/migrate.php` after deploying updated application files.
- Copy `.env.example` to `.env`; set `APP_ENV=production` and `APP_DEBUG=false`.
- Use a long random `ML_API_KEY` and keep Flask bound to `127.0.0.1`.
- Set database credentials, `APP_URL`, timezone, and secure-cookie settings.
- Run `composer install --no-dev --optimize-autoloader`.
- Run `python -m pip install -r legacy/demandForcasting/requirements.txt` in a virtual environment.
- Run `python legacy/demandForcasting/train_model.py` and start `python legacy/demandForcasting/predict_api.py` using a service manager.
- Test SMTP from System Settings.
- Schedule `scripts/run_maintenance.bat` or `scripts/run_maintenance.sh` daily.
- Schedule `scripts/backup_database.php` weekly and copy backups to protected external storage.
- Confirm Apache denies access to `.env`, `legacy/demandForcasting`, `sql`, `storage`, `scripts`, `config`, and `includes`.
- Change the initial super administrator password.
- Enable HTTPS and set `SESSION_SECURE_COOKIE=true`.
- Test login lockout, password reset, scanner pairing, forecasting, email, backup download, and restore on a staging database.

- Open a cashier shift before testing POS checkout.
- Verify a second administrator approves replenishment requests and purchase orders created by another user.
- Test supplier → purchase order → receiving → inventory flow, including package conversion and damaged quantities.
- Confirm inactive and expired products cannot be sold.
- Retrain Random Forest and review baseline comparison and Forecast Exceptions.
- Run `bash tests/run_all.sh` before production deployment.
- Use `scripts/build_release.sh` instead of manually zipping the Git working directory.
