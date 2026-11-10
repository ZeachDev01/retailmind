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
src/backend/legacy/routes/           Retired duplicate route files retained for reference
src/backend/scripts/                 Maintenance, backup, migration, release, and tunnel commands
src/backend/storage/                 Runtime output only; keep generated files out of Git
src/backend/tests/                   PHP smoke, database, contract, and browser-script checks
src/backend/dataset/                 Bundled example inventory dataset
scripts/tunnel/                      Windows tunnel wrappers for the backend tunnel scripts
scripts/archive/                     Retained one-off development scripts; review before running
tests/                               Playwright specs configured at the repository root
retailmind-products-import-sample.csv Product import example used by the CSV import test
```

## Documentation

```text
README.md                        Fresh-install summary, testing, and database imports
docs/README.md                   Documentation index
GLOSSARY.md                      Store operations vocabulary; read alongside docs/CONTEXT.md
docs/CONTEXT.md                   Core Store, role, account, inventory, and forecasting vocabulary
docs/guides/WINDOWS_LOCALHOST_INSTALL.md  Complete fresh Windows installation procedure
docs/DEPLOYMENT_CHECKLIST.md     Fresh-install acceptance checklist
docs/guides/                    Interface, installation, and browser behavior guides
docs/adr/                       Architecture decisions
docs/specs/                     Agreed specifications
docs/research/                  Investigation evidence, capstone sources, and notebooks
docs/verification/              Recorded checks and manual verification
docs/release-notes/              Feature notes and release documentation
docs/release-notes/archive/      Legacy release documentation
docs/agents/                    Repository workflow instructions
docs/teaching/                  Lessons, printable reference, teaching CSS, mission, notes, resources, and manuals
```

## Retained Material and Local State

```text
outputs/                        Retained research data and reviewed workbooks
docs/research/                  Investigation notes, capstone sources, and notebooks
docs/teaching/manuals/          Authored manual sources and generated deliverables
docs/verification/evidence/     Curated verification captures
scripts/archive/                Retained one-off development scripts
output/                         Ignored generated browser captures; tests recreate directories
tmp/, .scratch/                 Ignored local scratch and temporary files
.impeccable/                    Design surface briefs; ignored review captures and local config
src/backend/storage/            Application runtime storage
src/backend/storage/bin/        Downloaded runtime tools such as cloudflared.exe
src/backend/storage/tunnel/     Runtime tunnel configuration and logs
retailmind-private-*/           Recovery storage naming; inspect configuration before any relocation
.release/                       Generated release package
.worktrees/                     Separate Git working trees
vendor/, node_modules/          Installed dependencies
```

Runtime code, configuration, recovery storage, dependencies, and generated
directories keep their current paths. Retained authored manuals, capstone
sources, research data, and curated evidence stay in the existing documentation
categories. Generated browser captures, scratch files, and design-review images
are ignored and can be recreated by their producers.

## Naming Notes

- `src/frontend/components/` contains the current authenticated admin, inventory, invoice, and notification pages.
- Older role URLs such as `admin/`, `manager/`, `invoice/`, and `notification/` are rewritten to `src/frontend/components/`.
- Retired duplicate route files live under `src/backend/legacy/routes/` for reference and are blocked from direct web access.
