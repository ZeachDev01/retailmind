# Project Structure

This repository is organized so reviewers can quickly separate production code, public pages, supporting tools, and documentation.

## Runtime Entry Points

```text
index.php                        Landing page, login form, and role redirect
src/frontend/index.php               Landing page implementation
src/frontend/components/auth/login.php  Redirect to root login
src/frontend/components/cashier/     Cashier-facing screens
src/frontend/components/                Authenticated back-office modules
src/frontend/components/barcodeScanner/apiScanner/ JSON endpoints for authenticated browser and scanner workflows
src/frontend/components/barcodeScanner/ Barcode scanner web app assets
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
src/backend/database/seeds/          Product seed CSV used by local seed commands and forecast previews
src/backend/sql/                     Fresh schema and SQL migration references
src/backend/legacy/demandForcasting/ Python forecasting service and training scripts
src/backend/scripts/                 Maintenance, backup, migration, and release commands
src/backend/storage/                 Runtime output only; keep generated files out of Git
src/backend/tests/                   Smoke, database, and release package checks
scripts/                            Release packaging
tests/                              Playwright specs configured at the repository root
```

## Documentation

```text
README.md                        Setup, upgrade, and operating guide
docs/README.md                   Documentation index
docs/DEPLOYMENT_CHECKLIST.md     Deployment checklist
docs/guides/                    Interface and browser behavior guides
docs/adr/                       Architecture decisions
docs/specs/                     Agreed specifications
docs/research/                  Investigation evidence
docs/verification/              Recorded checks and manual verification
docs/release-notes/              Feature notes and release documentation
docs/release-notes/archive/      Legacy release documentation
docs/agents/                    Repository workflow instructions
src/backend/legacy/                  Retained old route files, blocked from direct web access
```

## Naming Notes

- `src/frontend/components/` contains the current authenticated admin, inventory, invoice, and notification pages.
- `src/backend/legacy/demandForcasting/` keeps its existing spelling because scripts and deployment docs already reference it.
- Older role URLs such as `admin/`, `manager/`, `invoice/`, and `notification/` are rewritten to `src/frontend/components/`.
- Old duplicate route files live under `src/backend/legacy/routes/` for reference and are blocked from direct web access.
