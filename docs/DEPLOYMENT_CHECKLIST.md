# Production deployment checklist

- Follow the [repository setup and database update instructions](../README.md). Import `src/backend/sql/schema.sql` only for an empty database; back up an existing database before importing `src/backend/sql/update.sql`.
- Create `.env` at the repository root; set `APP_ENV=production` and `APP_DEBUG=false`.
- Use a long random `ML_API_KEY` and keep Flask bound to `127.0.0.1`.
- Set database credentials, `APP_URL`, timezone, and secure-cookie settings.
- Run `composer install --no-dev --optimize-autoloader`.
- Run `python -m pip install -r src/backend/legacy/demandForcasting/requirements.txt` in a virtual environment.
- Run `python src/backend/legacy/demandForcasting/train_model.py` and start `python src/backend/legacy/demandForcasting/predict_api.py` using a service manager.
- Test SMTP from System Settings.
- Schedule `src/backend/scripts/run_maintenance.bat` or `src/backend/scripts/run_maintenance.sh` daily.
- Schedule `src/backend/scripts/backup_database.php` weekly and copy backups to protected external storage.
- Confirm Apache denies direct access to `.env`, `backend`, old root-level backend paths, logs, SQL files, and model artifacts.
- Change the initial super administrator password.
- Enable HTTPS and set `SESSION_SECURE_COOKIE=true`.
- Test login lockout, password reset, scanner pairing, forecasting, email, backup download, and restore on a staging database.

InfinityFree free hosting cannot run the Python forecasting service or scheduled
CLI tasks. Database Backup and Restore require private storage outside the web
root and are unavailable in web-only mode. Follow the hosting-specific
instructions in the repository README for that deployment.
