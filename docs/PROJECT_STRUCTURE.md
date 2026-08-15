# Project Structure

This repository is organized so reviewers can quickly separate production code, public pages, supporting tools, and documentation.

## Runtime Entry Points

```text
index.php                        Landing page and role redirect
modules/auth/login.php           Login screen
cashier/                         Cashier-facing screens
modules/                         Authenticated back-office modules
barcodeScanner/apiScanner/       JSON endpoints for browser and scanner workflows
barcodeScanner/                  Barcode scanner web app assets
```

## Application Code

```text
app/Core/                        Shared infrastructure classes
app/Database/                    Migration and schema helpers
app/Services/                    Business workflows and dashboard services
assets/bootstrap/                       Composer and environment bootstrapping
config/                          Configuration loaded from environment values
includes/                        Shared legacy helpers used by existing pages
```

## Data, Operations, and Tooling

```text
database/migrations/             PHP migrations run by scripts/migrate.php
sql/                             Fresh schema and SQL migration references
legacy/demandForcasting/                Python forecasting service and training scripts
scripts/                         Maintenance, backup, migration, and release commands
storage/                         Runtime output only; keep generated files out of Git
tests/                           Smoke, database, and release package checks
```

## Documentation

```text
README.md                        Setup, upgrade, and operating guide
docs/DEPLOYMENT_CHECKLIST.md     Deployment checklist
docs/release-notes/              Feature notes and release documentation
legacy/                          Retained old route files, blocked from direct web access
```

## Naming Notes

- `modules/` contains the current authenticated admin, inventory, invoice, and notification pages.
- `legacy/demandForcasting/` keeps its existing spelling because scripts and deployment docs already reference it.
- Older role URLs such as `admin/`, `manager/`, `invoice/`, and `notification/` are rewritten to `modules/`.
- Old duplicate route files live under `legacy/routes/` for reference and are blocked from direct web access.
