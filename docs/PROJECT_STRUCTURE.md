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
src/backend/app/Attention/           Attention rules, notifications, and review workflows
src/backend/app/Audit/               Protected Audit Record handling
src/backend/app/Authorization/       Role capabilities, workspace routing, and dormancy policy
src/backend/app/Backup/              Database backup, restore, and recovery coordination
src/backend/app/Database/            Migration and schema helpers
src/backend/app/Dashboard/           Dashboard workspaces and platform health sources
src/backend/app/Forecasting/         Demand history, evaluation, activation, and replenishment
src/backend/app/Receipts/            Sale/refund receipt details, rendering, and paper layout
src/backend/app/Services/            Inventory, sales, staff, and dashboard workflows
src/backend/app/Support/             Shared error presentation and operator alerts
src/backend/app/Store/               Store scope, calendar, and write gates
src/backend/app/Views/               Shared receipt, drawer, and pagination templates
src/backend/bootstrap/               Composer and environment bootstrapping
src/backend/config/                  Configuration loaded from environment values
src/backend/includes/                Shared legacy helpers used by existing pages
```

## Data, Operations, and Tooling

```text
src/backend/database/migrations/     PHP migrations run by src/backend/scripts/migrate.php
src/backend/database/seeds/          Product seed CSV used by local seed commands and forecast previews
src/backend/database/sql/            Fresh schema, consolidated upgrades, and historical SQL migrations
src/backend/legacy/demandForcasting/ Python forecasting service and training scripts
src/backend/scripts/                 Maintenance, backup, migration, and release commands
src/backend/storage/                 Runtime output only; keep generated files out of Git
src/backend/tests/                   Smoke, database, and release package checks
scripts/                            Release packaging
scripts/archive/                    Retained one-off development scripts; run from repository root only after review
tests/                              Playwright specs configured at the repository root
retailmind-products-import-sample.csv Product import example used by the CSV import test
```

## Documentation

```text
README.md                        Setup, upgrade, and operating guide
docs/README.md                   Documentation index
GLOSSARY.md                      Store operations vocabulary; read alongside docs/CONTEXT.md
docs/CONTEXT.md                   Core Store, role, account, inventory, and forecasting vocabulary
docs/DEPLOYMENT_CHECKLIST.md     Deployment checklist
docs/guides/                    Interface and browser behavior guides
docs/adr/                       Architecture decisions
docs/specs/                     Agreed specifications
docs/research/                  Investigation evidence
docs/verification/              Recorded checks and manual verification
docs/release-notes/              Feature notes and release documentation
docs/release-notes/archive/      Legacy release documentation
docs/agents/                    Repository workflow instructions
docs/teaching/                  Lessons, printable reference, teaching CSS, mission, notes, and resources
```

## Development Artifacts and Local State

```text
work/README.md                  Index of datasets, prototypes, and preserved archives
work/forecasting-dataset/        Forecasting train/test CSVs and product data
work/hostinger-demo/             Demo snapshot, manifest, and replay instructions
work/visualizations/             Development prototypes
work/archive/root-scratch/      Retained root scratch notes, commit list, and diff
work/archives/                  Retained source ZIP archive
output/                         Screenshots, verification output, and worktree archives
.impeccable/                    Design review evidence and surface notes
.scratch/, tmp/                 Existing local scratch and temporary files
backend/storage/                Existing storage tree; not a second application backend
src/backend/storage/            Application runtime storage
retailmind-private-*/           Recovery storage naming; inspect configuration before any relocation
.release/                       Generated release package
.worktrees/                     Separate Git working trees
vendor/, node_modules/          Installed dependencies
```

The organization pass preserved all files. Runtime code, configuration, recovery
storage, dependencies, and generated directories retain their existing paths.
Loose guides, audits, and design notes use the existing documentation categories;
their filenames and historical contents are retained. Root teaching materials
now live together under `docs/teaching/`.

## Naming Notes

- `src/frontend/components/` contains the current authenticated admin, inventory, invoice, and notification pages.
- `src/backend/legacy/demandForcasting/` keeps its existing spelling because scripts and deployment docs already reference it.
- Older role URLs such as `admin/`, `manager/`, `invoice/`, and `notification/` are rewritten to `src/frontend/components/`.
- Old duplicate route files live under `src/backend/legacy/routes/` for reference and are blocked from direct web access.
