# Local codebase cleanup verification, 2026-11-10

Scope: [issue #463](https://github.com/ZeachDev01/retailmind/issues/463), clean local development workspace and lightweight localhost deployment source.
This record covers the cleaned checkout, retained checks, reference audit, shallow-clone proof, and measured reduction.
No application behavior was changed for this verification.

- Date: 2026-11-10
- Branch: `ticket/463-verify`, verified at integration tip `6e5a2c99` (`integration-463`), plus this record.
- Comparison baseline: `main` at `9917630a`.
- Environment: Windows, PHP 8.5.1 CLI, Node/npm from the repository `package.json`, local MariaDB 10.11.16.

## 1. Measured reduction

Baseline source: the issue #463 exploration inventory (`exploration.md` section 6) measured on `main` `9917630a`.
The ticket also records a smoke-metric-corrected baseline of `327,324,949` bytes; the table keeps the exploration figure and the alternate delta is stated below.

Tracked files are counted with `git ls-files`; bytes are apparent file sizes.

| Scope | Before files | Before bytes | After files | After bytes |
|---|---:|---:|---:|---:|
| All tracked files | 3746 | 327,656,484 | 1861 | 169,976,293 |
| `tmp/` | 1871 | 201,961,800 | 0 (absent) | 0 |
| `output/` | 173 | 10,840,464 | 0 (absent) | 0 |
| `outputs/` | 10 | 534,999 | 11 | 663,765 |
| `.impeccable/` | 151 | 13,926,656 | 7 | 50,229 |
| `docxFile/` | 4 | 2,520,258 | 0 (absent) | 0 |
| `docs/research/` | 24 | 297,832 | 256 | 58,772,872 |
| `docs/teaching/` | 6 | 14,361 | 83 | 12,605,955 |

- Delta: **-1885 tracked files (-50.3%)** and **-157,680,191 bytes (-48.1%, 150.4 MiB)**. This record adds the final file (12,882 bytes).
- With the corrected baseline `327,324,949` bytes the byte delta is `157,348,656` bytes (-48.1%).
- `outputs/` and `docs/research/` grew because supplied research input and authored capstone source moved into tracked locations instead of being deleted.
- Historical Git objects are preserved: the repository still holds 21,743 in-pack objects in 269.33 MiB of packs, unchanged by this cleanup. A normal full clone therefore does not shrink; only a depth-one clone avoids downloading history.

## 2. Retained checks

Run from the ticket worktree at `6e5a2c99`. All passed unless listed as not run.

| Check | Command | Result |
|---|---|---|
| Stylesheet bundle | `npm run check` (step 1) | Pass: 9 sources, no CSS imports |
| View markup audit | `npm run check` (step 2) | Pass: 0 errors, 0 warnings |
| Smoke checks | `npm run check` (step 3) | Pass: 113 checks |
| Tunnel setup block contract | `npm run check` (step 4) | Pass: all sections succeeded |
| Tunnel parity contract | `npm run check` (step 5) | Pass |
| Deterministic contracts | `npm run check:contracts` | Pass: attention, forecasting notices, store overview, failed sign-in, hosted database config |
| Hosted database configuration | `php src/backend/tests/hosted_database_config_test.php` | Pass |
| PHP lint, changed files | `php -l` on the five changed PHP files | Pass: no syntax errors |
| Manifest JSON | `php -r 'json_decode(composer.json)'`, `node -e JSON.parse(package.json)` | Pass |
| Composer validation | `composer validate` | Not run: `composer` is not installed on this machine and `composer.lock` is absent |
| Playwright / E2E suites | `npm run test:e2e`, `npm run playwright:install` | Not run: browser suites require a running app and database. `.github/workflows/playwright.yml` runs the root `tests/` suite, not the DB-backed `test:e2e` suite, so these remain unverified |

Changed PHP files linted: `src/backend/app/Services/OperationalDataResetService.php`, `src/backend/app/Services/TunnelSetupBlockBuilder.php`, `src/backend/tests/smoke_checks.php`, `src/frontend/components/administrator/store_settings.php`, `src/frontend/components/system_administrator/system_settings.php`.

## 3. Reference and stale-path audit

### 3.1 Markdown links

A local link checker scanned `README.md` and every `docs/**/*.md`, resolving inline links and images relative to their file.
It skips `http(s)`, `mailto`, `tel`, `data`, root-absolute targets, and pure anchors.

| Revision | Markdown files | Relative links checked | Broken |
|---|---:|---:|---:|
| `main` `9917630a` (before cleanup) | 189 | 303 | 32 |
| Cleaned tree with this record | 196 | 322 | 25 |

- The cleanup fixed three pre-existing broken links to the deleted `work/` directory (`README.md`, `docs/README.md`, `docs/research/public-sales-capstone.md`) and introduced no new broken links.
- This record fixed four non-portable machine-absolute `file:///` links in `docs/WORKSPACE_MAP.md` and `docs/specs/storewide-caching-and-offline-tolerant-operations.md`; they now resolve as repository-relative links.
- **25 intentional exceptions remain.** All point to git-ignored local `.scratch/` evidence that was never tracked and cannot exist in a clean checkout: `docs/guides/public-forecasting-demo.md` (8 image links), `docs/guides/train-test-sales-replay.md` (2 image links), `docs/research/m5-expansion-results-20261028.md` (6), `docs/research/m5-results-20261028.md` (4), `docs/research/uci-ii-results-20261028.md` (5). These are historical evidence notes; the findings stay, the local capture paths do not resolve.

### 3.2 Removed artifacts and stale paths

Searches over the tree (excluding `.git`, `node_modules`, and worktrees) found:

- `tmp/`, `output/`, `docxFile/`, `.impeccable/review/`, `test_rn.txt`, `.example.env`, `.github/workflows/ci.yml`, `src/backend/tests/infinityfree_deploy_contract.py`, and `scripts/build-infinityfree-release.sh` are absent from the working tree and from tracking.
- Remaining `tmp/` and `output/` mentions are documentation of the ignore rules in `docs/PROJECT_STRUCTURE.md`, plus `docs/specs/navbar-icons-design.md`, which describes the ignored output location for regeneratable captures; retained evidence lives at `docs/verification/evidence/navbar-icons/`.
- `.impeccable/review/` references remain in the seven authored surface briefs under `.impeccable/surfaces/` and in five browser tests that recreate the directory. The briefs are historical design-review records; the tests create the ignored directory before writing. `docs/verification/notifications-ui-audit.md` already states that its captures were removed.
- `receipt_and_item_categorize.zip` references now resolve to `outputs/receipt-review-2026-10-27/original/receipt_and_item_categorize.zip`, except the Colab notebooks, which describe an external outer archive by name.
- No active workflow or script performs Hostinger or InfinityFree deployment. Remaining hosted references are labeled historical (`README.md`, `docs/guides/hostinger-demo-replay.md`, `docs/guides/hostinger-demo-deployment-plan.md`), live-host references (`docs/guides/HOME_SCREEN_APP.md`, `docs/guides/DAILY_NOTIFICATIONS.md`), an ADR example, or the defensive `.ftp-deploy-sync-state.json` entry in the `.htaccess` and remote-access guardrail denylists.
- Retained workflows: `.github/workflows/contracts.yml` (push and pull request) and `.github/workflows/playwright.yml`. No deploy workflow remains.

### 3.3 Ignore rules

`git check-ignore` confirms representative regenerated output is ignored:

| Path | Rule |
|---|---|
| `tmp/sample.png` | `.gitignore: /tmp/` |
| `output/playwright/x/desk.png` | `.gitignore: /output/` |
| `output/receipt-logo/log.txt` | `.gitignore: /output/` |
| `.impeccable/review/x/desk.png` | `.gitignore: /.impeccable/review/` |
| `.impeccable/build/state.json` | `.gitignore: /.impeccable/build/` |
| `src/backend/config/forecasting_runner.json` | `.gitignore: /src/backend/config/forecasting_runner.json` |
| `src/backend/storage/ml_models/x.joblib` | `.gitignore: /src/backend/storage/ml_models/*.joblib` |

### 3.4 Preserved material

| Material | Location | Check |
|---|---|---|
| Capstone adjustment source | `docs/research/capstone-adjustments-2026-11-04/` | 121 tracked files |
| Capstone paste-ready source | `docs/research/capstone-paste-ready-2026-11-04/` | 110 tracked files |
| Manual sources, deliverables, and optional build scripts | `docs/teaching/manuals/` | 77 tracked files, indexed by `docs/teaching/manuals/README.md` |
| Live manual capture inputs | `docs/teaching/manuals/source/live/` | 61 tracked files |
| Supplied research ZIP | `outputs/receipt-review-2026-10-27/original/receipt_and_item_categorize.zip` | present |
| Codebase deepening analysis | `docs/research/codebase-deepening-plan-2026-11-07.md` | present |
| Navbar icon evidence | `docs/verification/evidence/navbar-icons/` | 6 tracked files |
| Design surface briefs | `.impeccable/surfaces/` | 7 tracked files |

The dashboard links these from `docs/README.md` and `docs/teaching/manuals/README.md`; the research sources are now listed in the documentation index.

## 4. Shallow checkout proof

```bash
git clone --depth 1 --branch integration-463 file:///C:/xampp/htdocs/retailmind \
  C:/Users/Mike/AppData/Local/Temp/opencode/retailmind-463/shallow-clone
```

Result: one commit (`6e5a2c99`), 1860 tracked files, 169,963,121 bytes of working-tree content, and a 99.36 MiB object pack. The clone predates this record, so it carries one tracked file fewer than the final tree. The full repository still carries 269.33 MiB of historical packs.

Required inputs present: `index.php`, `composer.json`, `src/backend/database/sql/schema.sql`, `src/backend/database/migrations/`, `.env.example`, `README.md`, `docs/guides/WINDOWS_LOCALHOST_INSTALL.md`, `package.json`, and `.github/workflows/contracts.yml`.
Nine storage directories carry `.gitkeep` placeholders (`backups`, `bin`, `exports`, `imports`, `logs`, `profile-images`, `receipts`, `sessions`, `tunnel`); `src/backend/storage/images/` needs none because tracked product uploads already populate it.

Required artifacts absent from the clone: `tmp/`, `output/`, `.impeccable/review/`, `docxFile/`, `test_rn.txt`, `.github/workflows/ci.yml`, `src/backend/tests/infinityfree_deploy_contract.py`, `scripts/build-infinityfree-release.sh`, and `.example.env`.

The clone is a proof that tracked inputs are complete. It does not contain ignored inputs: `vendor/` (`composer install` required), `node_modules/`, `.env`, the downloaded `cloudflared.exe`, logs, sessions, backups, or the private recovery store.

## 5. Disposable database check

A local MariaDB server was available, so the optional check ran with a uniquely named disposable database. The configured Store database was never opened, referenced, imported into, or modified.

- Database created: `retailmind_cleanup_verify_040c803b` (name validated against `^retailmind_cleanup_verify_[0-9a-f]{8}$` before creation and before the drop).
- Import: `src/backend/database/sql/schema.sql` from the shallow clone, exit 0.
- Verified: 81 tables, 103 `schema_migrations` rows, roles `admin`, `cashier`, `inventory_manager`, `super_admin`, one `superadmin` user with `must_change_password = 1`, and `password_verify('RetailMind@2026')` true for the stored hash.
- Verified empty operational data: `products = 0`; `categories = 7` seed rows.
- Dropped the same database; no `retailmind_cleanup_verify_%` schema remained afterwards.

## 6. Limitations

- Not verified on a real Store PC. Windows service registration, scheduled tasks, file permissions, and first-run browser setup were not exercised.
- No Cloudflare tunnel token, domain, Access policy, or live tunnel connection was provisioned or verified. Only the retained tunnel contracts and documentation were checked.
- No live hosted site, Hostinger or InfinityFree resource, DNS record, credential, or hosted database was touched or verified.
- The application bootstrap, login flow, and browser suites were not run against the imported schema; the database check verified schema import and seed rows only.
- `composer install` and `composer validate` were not run because Composer is not installed here and `composer.lock` is absent. Dependency resolution for a fresh Store PC remains unproven.
- Git history was not rewritten. Old revisions still contain every removed artifact, so full-clone size does not shrink and repository size on disk only drops after the cleaned commit becomes the shallow-clone target.
- 25 `.scratch/` evidence links in historical notes remain unresolved by design; the captured files were never tracked.
- `.github/skills/` (about 22 MB tracked) was outside this cleanup scope and is unchanged.
- Machine-absolute `file:///` links were fixed only where the link checker found them; other docs were not re-read for prose accuracy.

## 7. Follow-up

- Decide whether the `.scratch/` evidence links should become plain text or point at a tracked evidence location.
- Add a one-line note to `.impeccable/surfaces/` briefs if the removed review captures should stop being named as if present.
- Re-check `docs/guides/DAILY_NOTIFICATIONS.md`, `docs/guides/HOME_SCREEN_APP.md`, and ADR-0030 hosted examples when the hosted demo is finally decommissioned.
- Pin dependencies with a committed `composer.lock` before treating the fresh-install guide as reproducible.
