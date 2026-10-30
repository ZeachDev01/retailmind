# Beta questionnaire software quality report

This is the initial read-only report. See the [transaction/browser follow-up](beta-questionnaire-transaction-browser-20261030.md) for later authorized execution and the user's thermal-printer/barcode-scanner acceptance.

Date: 2026-10-30 (Asia/Manila). [Full 120-case test plan](beta-questionnaire-quality-plan.md).

**Overall questionnaire result: NOT VERIFIED.** Four supporting checks passed; one source smoke check failed. No questionnaire workflow was executed, so none of the 120 statements is certified as passed. These results do not establish ISO certification.

## Agreed scope and tested state

The user requested a plan and report only, software checks only, no application fixes, and read-only checks for this round. Timing limits are proposed targets. Transaction tests remain pending until the designated existing test Store is identified and its permitted changes are confirmed.

Repository: `C:\laragon\www\retailmind`. HEAD captured before checks: `2a5e3af99ebc3dca3408570110079bc312551b3f`. The working tree was dirty with existing Backup/Restore services, pages, tests, and untracked files. Results concern that working tree, not a clean commit or deployed installation. No existing application or test files were edited by this QA task.

Runtime observed: PHP 8.5.1 CLI, Node.js v26.1.0, Python 3.14.5; Windows PowerShell. No browser, Store URL, Store database, hardware, or live account was selected. No database/transaction tests or browser sessions were run. Role and routing contracts load the application bootstrap but do not execute Store database operations; normal bootstrap logging initialization may occur.

Scope reached: this plan, this report, and the documentation index. Callers, fixtures, exports, application configuration, and dependencies require no changes for a documentation deliverable. Existing glossary definitions were reused; no new glossary or ADR was warranted.

## Current questionnaire status

| Role | Statements | Pass | Fail | Not run |
| --- | ---: | ---: | ---: | ---: |
| Admin | 40 | 0 | 0 | 40 |
| Inventory Manager | 40 | 0 | 0 | 40 |
| Cashier | 40 | 0 | 0 | 40 |
| Total | 120 | 0 | 0 | 120 |

Source-check failures below are not invented questionnaire task failures. Conversely, a passing source contract is insufficient to pass an entire questionnaire statement. Hardware/user-observation portions are deferred outside this software-only round; every unexecuted statement remains Not run.

## Supporting checks executed

| Evidence | Command | Exit | Result | What it establishes |
| --- | --- | ---: | --- | --- |
| S1 | `git diff --check` | 0 | Pass | No whitespace errors in the tracked diff at execution time; not application correctness. |
| S2 | `php src/backend/scripts/build_styles.php --check` | 0 | Pass | Checked-in stylesheet bundle matches nine source stylesheets. No layout/browser claim. |
| S3 | `php src/backend/tests/smoke_checks.php` | 1 | Fail | Five source-text assertions are unsatisfied; see exact output. |
| S4 | `php src/backend/tests/role_capability_policy_contract.php` | 0 | Pass | Deterministic role-capability rules satisfy the contract; supports ADM-SE-2/4/5, INV-SE-2/4, CAS-SE-2. Does not prove HTTP endpoint enforcement. |
| S5 | `php src/backend/tests/workspace_routing_contract.php` | 0 | Pass | Expected role routes and unknown-role fallback satisfy the contract; supports planned role/workspace checks, including ADM-MA-4, without proving session/login behavior. |

### Exact command output

#### S1

```text
$ git diff --check
(no output)
Exit code: 0
```

#### S2

```text
$ php src/backend/scripts/build_styles.php --check
Stylesheet bundle: passed (9 sources, no CSS imports)
Exit code: 0
```

#### S3

```text
$ php src/backend/tests/smoke_checks.php
Smoke checks failed:
- Discount authorization recorded: missing authorizeSupervisor
- Dedicated system health page present: missing Recommended maintenance commands
- Manager review queue guards approvals and requires rejection reasons: missing required to reject
- Register administration stays with the Administrator workspace: forbidden register-actions
- Cashier Shift opening is owned by the Cashier workspace: missing A Cashier Shift is opened and owned by the Cashier who sells
Exit code: 1
```

#### S4

```text
$ php src/backend/tests/role_capability_policy_contract.php
Role capability policy contract: passed
Exit code: 0
```

#### S5

```text
$ php src/backend/tests/workspace_routing_contract.php
Workspace routing contract: passed
Exit code: 0
```


## Findings requiring investigation

All five findings are source-text contract failures. Their cause may be an implementation regression or an assertion that no longer reflects the intended implementation. This read-only pass does not resolve that distinction. Do not edit assertions merely to obtain green output.

| Finding | Observed assertion mismatch | Related planned coverage / next investigation |
| --- | --- | --- |
| F1 | `authorizeSupervisor` missing from discount-authorization source check | Inspect actual approval/discount authorization and run its behavioral regression safely; relates to CAS checkout/authorization coverage. |
| F2 | `Recommended maintenance commands` missing from System Health page check | Compare current page with intended operator guidance; System Health is beyond these three role questionnaires, but remains part of the repository smoke gate. |
| F3 | `required to reject` missing from manager review queue check | Test rejection validation and feedback, including direct requests; relates to INV-FS-5, INV-US-5, and INV-RE-2. |
| F4 | `register-actions` forbidden text present in Register administration check | Inspect the element's actual purpose and role authorization; relates to ADM staff/Register oversight and role boundaries. |
| F5 | Cashier Shift ownership wording missing | Verify actual Shift owner rules and visible guidance; relates to CAS-FS-1, CAS-US-5, and CAS-SE-4. |

Earlier [role/device notes](role-device-acceptance.md) list the same five smoke mismatches. That is historical corroboration only; this report independently reran the current check. Other historical browser/database failures were not rerun and are not presented as current outcomes. No defect fixes were authorized or attempted.

## Open coverage and completion criteria

1. Identify the designated test installation and database, role accounts, browser versions, supported sizes, fixture ledger, and permitted mutations before transactional execution. Do not use the broad E2E command against existing Store records: its documented fixture setup/removal changes the configured database.
2. Execute the plan's software workflow, authorization, persistence, reconciliation, error, export, responsive, and cross-browser cases, recording actual evidence per case. Successful denied writes must be checked against before/after state.
3. Measure the proposed 2-second search/cart, 3-second save/status, and 5-second dashboard/report thresholds in the specified environment. No performance measurements were made. CSV size and duration budget remain unresolved.
4. Supply genuine original defects/corrected builds and before/after update builds for Maintainability regression statements. Do not infer these passes from a static review.
5. Resolve or explain the five smoke failures with appropriate behavioral evidence and rerun affected checks. A green source gate alone would still not establish 120 questionnaire passes.
6. Keep physical scanners/printers, actual second devices/computers, companion-application observations where unavailable, and respondent ease/clarity ratings deferred. Full questionnaire acceptance requires these observations in a later round; software-only completion cannot certify them.

No overall pass or response score can be issued from this run. The deliverable is a complete traceable plan and a limited, reproducible software evidence report.

## Document verification

The plan was checked against the attachment for all 120 exact statements in role/category/question order, unique case identifiers, 40 cases per role, five per quality category per role, and existing local coverage/link targets. Final whitespace validation is recorded after document creation. These checks validate the deliverable, not the Store workflows.

Final validation: **Pass**. Python assertions confirmed exact source statements, case counts/IDs, coverage-file existence, all links in the two new documents and the new index entry, and no trailing whitespace in generated documents. `git diff --check` also exited 0 after document creation. Existing unrelated documentation links were outside this validation scope. The working tree continued to receive unrelated Backup/Restore edits during this session; those edits were preserved and were not audited by this report.

