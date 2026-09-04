# Project Structure

This repository is organized so reviewers can quickly separate production code, public pages, supporting tools, and documentation.

## Runtime Entry Points

```text
index.php                        Landing page, login form, and role redirect
src/frontend/index.php               Landing page implementation
src/frontend/components/auth/login.php  Redirect to root login
src/frontend/cashier/                Cashier-facing screens
src/frontend/components/                Authenticated back-office modules
src/frontend/barcodeScanner/apiScanner/ JSON endpoints for browser and scanner workflows
src/frontend/barcodeScanner/         Barcode scanner web app assets
```

## Application Code

```text
src/backend/app/Core/                Shared infrastructure classes
src/backend/app/Database/            Migration and schema helpers
src/backend/app/Services/            Business workflows and dashboard services
src/backend/bootstrap/               Composer and environment bootstrapping
src/backend/config/                  Configuration loaded from environment values
src/backend/includes/                Shared legacy helpers used by existing pages
```

## Data, Operations, and Tooling

```text
src/backend/database/migrations/     PHP migrations run by src/backend/scripts/migrate.php
src/backend/sql/                     Fresh schema and SQL migration references
src/backend/legacy/demandForcasting/ Python forecasting service and training scripts
src/backend/scripts/                 Maintenance, backup, migration, and release commands
src/backend/storage/                 Runtime output only; keep generated files out of Git
src/backend/tests/                   Smoke, database, and release package checks
```

## Documentation

```text
README.md                        Setup, upgrade, and operating guide
docs/DEPLOYMENT_CHECKLIST.md     Deployment checklist
docs/release-notes/              Feature notes and release documentation
src/backend/legacy/                  Retained old route files, blocked from direct web access
```

## Naming Notes

- `src/frontend/components/` contains the current authenticated admin, inventory, invoice, and notification pages.
- `src/backend/legacy/demandForcasting/` keeps its existing spelling because scripts and deployment docs already reference it.
- Older role URLs such as `admin/`, `manager/`, `invoice/`, and `notification/` are rewritten to `src/frontend/components/`.
- Old duplicate route files live under `src/backend/legacy/routes/` for reference and are blocked from direct web access.
