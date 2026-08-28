# Project Structure

This repository is organized so reviewers can quickly separate production code, public pages, supporting tools, and documentation.

## Runtime Entry Points

```text
index.php                        Landing page, login form, and role redirect
frontend/index.php               Landing page implementation
frontend/modules/auth/login.php  Redirect to root login
frontend/cashier/                Cashier-facing screens
frontend/modules/                Authenticated back-office modules
frontend/barcodeScanner/apiScanner/ JSON endpoints for browser and scanner workflows
frontend/barcodeScanner/         Barcode scanner web app assets
```

## Application Code

```text
backend/app/Core/                Shared infrastructure classes
backend/app/Database/            Migration and schema helpers
backend/app/Services/            Business workflows and dashboard services
backend/bootstrap/               Composer and environment bootstrapping
backend/config/                  Configuration loaded from environment values
backend/includes/                Shared legacy helpers used by existing pages
```

## Data, Operations, and Tooling

```text
backend/database/migrations/     PHP migrations run by backend/scripts/migrate.php
backend/sql/                     Fresh schema and SQL migration references
backend/legacy/demandForcasting/ Python forecasting service and training scripts
backend/scripts/                 Maintenance, backup, migration, and release commands
backend/storage/                 Runtime output only; keep generated files out of Git
backend/tests/                   Smoke, database, and release package checks
```

## Documentation

```text
README.md                        Setup, upgrade, and operating guide
docs/DEPLOYMENT_CHECKLIST.md     Deployment checklist
docs/release-notes/              Feature notes and release documentation
backend/legacy/                  Retained old route files, blocked from direct web access
```

## Naming Notes

- `frontend/modules/` contains the current authenticated admin, inventory, invoice, and notification pages.
- `backend/legacy/demandForcasting/` keeps its existing spelling because scripts and deployment docs already reference it.
- Older role URLs such as `admin/`, `manager/`, `invoice/`, and `notification/` are rewritten to `frontend/modules/`.
- Old duplicate route files live under `backend/legacy/routes/` for reference and are blocked from direct web access.
