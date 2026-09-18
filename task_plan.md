# Task Plan: Dedicated Administration Pages

## Goal
Replace modal-only administration workflows with dedicated pages while preserving their behavior and established application styling.

## Current Goal
Record the accepted single-Store architecture decision and produce an implementation-ready plan that separates Super Administrator technical governance from Administrator Store operations without changing the demand-forecasting grain.

## Phases
- [x] Phase 1: Inspect the existing audit-log and user-management implementations, routes, dependencies, and tests.
- [x] Phase 2: Implement the dedicated audit-log route/page and remove the modal entry flow.
- [x] Phase 3: Integrate DataTables using existing project conventions and preserve audit-specific behavior.
- [x] Phase 4: Run focused tests/build checks and review the final diff.
- [x] Phase 5: Inspect the existing fiscal-period modal, routes, dependencies, and tests.
- [x] Phase 6: Implement a dedicated fiscal-period page and remove the modal entry flow.
- [x] Phase 7: Run focused tests/build checks and review the fiscal-period diff.
- [x] Phase 8: Inspect the existing system-health modal, service data, routes, styles, and tests.
- [x] Phase 9: Implement a dedicated system-health page and remove the modal entry flow.
- [x] Phase 10: Run focused checks and review the system-health diff.
- [x] Phase 11: Inspect the Manage Users tab markup, state handling, counts, and responsive styles.
- [x] Phase 12: Redesign the Users/Branches tab control without changing its behavior.
- [x] Phase 13: Run focused checks and review the Manage Users tab diff.
- [x] Phase 14: Inspect existing user and branch action markup plus modal lifecycle behavior.
- [x] Phase 15: Move actions into their panel headers and convert branch creation to a compact modal.
- [x] Phase 16: Run focused checks and review the contextual-action diff.
- [x] Phase 17: Reproduce and diagnose the Add Staff modal scrolling failure.
- [x] Phase 18: Make the modal form the viewport-constrained scroll region.
- [x] Phase 19: Run the regression and repository verification checks.
- [x] Phase 20: Inspect Notification Preferences and Profile Preferences markup, state, routes, styles, and tests.
- [x] Phase 21: Move Notification Preferences into Profile Preferences and remove it from Notifications.
- [x] Phase 22: Run focused regressions and repository verification checks.
- [x] Phase 23: Inspect User Management and Audit Logs summary markup and styling.
- [x] Phase 24: Add meaningful icons to the requested summary cards without changing behavior.
- [x] Phase 25: Run focused regressions and repository verification checks.
- [x] Phase 26: Reproduce and diagnose the Admin User Info image/layout defects.
- [x] Phase 27: Implement a responsive profile image treatment and improved page layout.
- [x] Phase 28: Run visual, responsive, lint, smoke, and diff verification.
- [x] Phase 29: Reproduce the cross-page/sidebar avatar failure and inspect shared asset loading.
- [x] Phase 30: Move avatar containment into a cache-safe shared shell asset and add regression coverage.
- [x] Phase 31: Verify avatar rendering invariants across page navigation and rerun repository checks.
- [x] Phase 32: Inspect Receipt Management, authorization/query boundaries, Audit Logs DataTables conventions, and existing test seams.
- [x] Phase 33: Add a failing high-level receipt data contract test and page-level regressions at the issue-defined seams.
- [x] Phase 34: Implement server-side Receipt Management paging, search, filters, safe sorting, state restoration, and intentional UI states.
- [x] Phase 35: Run focused checks, type/lint checks, and the full repository suite.
- [x] Phase 36: Review the implementation against repository standards and issue #3, address findings, and commit the work.
- [x] Phase 37: Resolve the single-Store domain, role authority, dashboard responsibilities, user-management delegation, and forecasting ownership; update `CONTEXT.md` inline.
- [x] Phase 38: Audit the existing role, dashboard, branch-compatibility, user-management, attention, notification, and forecasting seams; record the accepted ADR.
- [x] Phase 39: Define the authorization and singleton-Store compatibility changes, including Emergency Access and Recovery Account constraints.
- [x] Phase 40: Define deep dashboard query modules and shared attention interfaces with explicit role-specific outputs.
- [x] Phase 41: Specify the Super Administrator dashboard layout, data contracts, navigation, and protected actions.
- [x] Phase 42: Specify the Administrator dashboard layout, data contracts, navigation, and operational actions.
- [x] Phase 43: Specify user-management delegation, threshold ownership, five-minute refresh behavior, migrations, and compatibility cleanup.
- [x] Phase 44: Map regression tests, rollout order, acceptance criteria, and final documentation changes; review the complete implementation plan.
- [x] Phase 45: Fetch specification issue #5 and confirm the current code, domain, ADR, and issue-tracker constraints for ticket slicing.
- [x] Phase 46: Draft tracer-bullet tickets with explicit blockers and obtain user approval for granularity and dependency edges.
- [x] Phase 47: Publish approved tickets to GitHub in dependency order with parent references and `ready-for-agent` labels.
- [x] Phase 48: Verify ticket bodies, labels, blockers, and frontier; record the published issue map.
- [x] Phase 49: Inspect branch schema/data dependencies, authorization helpers, migrations, and existing contract-test conventions for issues #6 and #7.
- [x] Phase 50: Add failing database-backed singleton Store scope contracts and implement the compatibility adapter/migration preflight.
- [x] Phase 51: Add a failing deterministic capability-matrix contract and implement the centralized authority policy.
- [x] Phase 52: Integrate the new foundations at safe current seams without prematurely implementing blocked migration tickets.
- [x] Phase 53: Run focused checks, type/lint checks, and the full repository suite.
- [x] Phase 54: Review the implementation against repository standards and issues #6/#7, address findings, and commit the work.

## Decisions
- Prefer the project's existing layout, components, and dependency versions over introducing a second table stack.
- Keep audit logs read-only and optimize the page for searching, sorting, filtering, and pagination.
- Use the dependency-free DataTables 3.0.4 CDN build, consistent with the project's existing CDN-based frontend dependencies.
- Retain the old audit-log URL as an authenticated 302/307 compatibility redirect rather than leaving bookmarks broken.
- Place Fiscal Periods under `components/system_administrator`, preserve all create/close/lock behavior, and retain the former modal URL as an authenticated compatibility redirect.
- Use a page-scoped fiscal-period stylesheet and remove the iframe overlay, embedded presentation branches, and trigger attributes.
- Place System Health under `components/system_administrator`, preserve the read-only service checks and refresh behavior, and retain the former modal URL as an authenticated compatibility redirect.
- Use a page-scoped system-health stylesheet and remove the iframe overlay, embedded presentation branches, and trigger attributes.
- Replace the full-width dark Manage Users tab bar with a compact light segmented control on the full page, while providing a coordinated dark variant for embedded mode.
- Preserve the existing ARIA roles, roving keyboard focus, and panel-switching JavaScript; add only presentational icon, description, and count elements.
- Move Add Staff User from the page topbar into the Users panel header; keep the existing add-user modal and form-state behavior unchanged.
- Move Create Branch into the Branches panel header and open a compact branch modal using the existing overlay, form, and keyboard-dismiss patterns.
- Keep modal headers visible and make each modal's form/body the internal scroll region, sized with dynamic viewport units for mobile keyboards.
- Preserve notification preference persistence and validation while changing only where the controls are presented.
- Make `components/auth/preferences.php` the canonical account-level Preferences page, keep the old notification URL as a compatibility redirect, and scope its styles independently from the notification inbox.
- Reuse Bootstrap Icons already loaded by the application and style icons through page-specific classes rather than inline presentation.
- Scope the User Info redesign to a directly linked stylesheet so profile-avatar containment is resilient to stale cached imports in the shared compatibility bundle.
- Load critical avatar geometry from the shared sidebar shell with a `filemtime` query so navigation across roles/pages cannot reuse stale containment rules.
- Implement issue #3 before blocked issue #4; use the issue-approved seams: one high-level receipt data contract plus page-level smoke coverage.
- Preserve the untracked `CONTEXT.md` file and avoid unrelated worktree changes.
- For issue #5, supersede earlier user-facing branch-management decisions: RetailMind exposes one Store, while one internal singleton branch remains temporarily for database compatibility.
- Keep Demand Forecast training and prediction at Product + Day granularity; dashboard presentation must not alter ML inputs or outputs.
- Separate Super Administrator technical governance from Administrator Store operations through dedicated routes, navigation, query models, and backend authorization.
- Replace the implicit Super Administrator role bypass with an explicit role-access policy shared by routes, navigation, user management, and tests.
- Use one high-level database-backed dashboard workspace contract and one role-access policy contract as the primary test seams; keep route smoke checks thin.
- Keep dashboard attention live-derived and shared with notifications rather than introducing a second ticket lifecycle.
- Publish the implementation-ready specification as GitHub issue #5 with only the `ready-for-agent` label.
- Decompose issue #5 into 14 approved tracer-bullet tickets using expand–migrate–contract for the wide single-Store change; the singleton Store adapter and role capability policy are the initial frontier.
- Publish the approved child tickets as issues #6–#19 with textual blocker references because the configured `gh` workflow does not expose native dependency creation; do not modify parent issue #5.
- Implement issues #6 and #7 together as independent foundation slices; use their explicitly pre-agreed seams: a database-backed singleton Store scope contract and a deterministic capability-matrix contract.
- Preserve the current uncommitted domain documentation/planning files and avoid prematurely implementing the caller migrations assigned to issues #8–#19.

## Errors Encountered
| Error | Attempt | Resolution |
|---|---:|---|
| Git rejected the repository due to sandbox-user ownership | 1 | Use a command-scoped `safe.directory` setting for read-only Git inspection. |
| `rg.exe` could not launch from the WinGet shim | 1 | Fall back to PowerShell `Get-ChildItem` and `Select-String` for repository search. |
| Browser-control runtime was unavailable for visual QA | 1 | Completed repository-wide lint, smoke, route/reference, and diff checks instead. |
| `apply_patch` rejected a delete-and-add of the same legacy fiscal route in one patch | 1 | Split creation and compatibility-route replacement into separate patches. |
| Browser-control JavaScript runtime was not available for local visual QA | 1 | Continue with full PHP lint, smoke checks, source invariants, and diff review. |
| Browser-control runtime remains unavailable for System Health visual QA | 1 | Complete repository-wide lint, smoke, CSS structure, route-reference, and diff checks instead. |
| Browser-control runtime remains unavailable for Manage Users visual QA | 1 | Complete source-level responsive-state review plus full lint, smoke, CSS structure, and diff checks. |
| `ctx_execute` ran a POSIX `for` loop through PowerShell and failed parsing | 1 | Reissued the GitHub query through Node.js `execFileSync` and parsed JSON there. |
| PowerShell inventory command lost `$_` and boolean literals through nested shell quoting | 1 | Replaced nested PowerShell exploration with Node.js filesystem inspection. |
| A Node.js inventory one-liner lost template-literal expressions through shell quoting | 1 | Ran the analysis directly through `ctx_execute` JavaScript instead of `node -e`. |
| Initial receipt contract paging fixture used a disallowed two-row page size, and each sale had only one line item | 1 | Used a valid 10-row page with an offset and seeded multiple line items so numeric item-count ordering is observable. |
| Standards review shell command used CMD-style `if exist` under PowerShell | 1 | Used the already-loaded AGENTS guidance and staged diff for the two-axis review instead of repeating the invalid mixed-shell command. |
| Full `run_all.sh` reached the release package check but the environment has no `rsync` | 1 | Ran the release check separately to confirm the environmental dependency; all preceding suite checks passed, and the failure is recorded rather than changing unrelated release tooling. |
| Contextual-action patch missed a responsive CSS anchor | 1 | Split PHP markup, modal file, JavaScript, CSS, and smoke updates into independent patches. |
| Browser-control runtime remains unavailable for contextual-action QA | 1 | Complete full lint, smoke, CSS structure, modal-reference, and diff checks instead. |
| Add Staff modal could not reach lower fields | 1 | Removed the contradictory dialog overflow rules and added a viewport-constrained scroll region to the modal form. |
| Browser-control runtime remains unavailable for modal-scroll QA | 1 | Added a deterministic stylesheet regression and completed static/runtime verification instead. |
| `git diff --check` found a blank line at the end of `notifications.css` after removing the legacy preference block | 1 | Removed the extra trailing blank line and reran verification. |
| The required `rtk` wrapper cannot execute in the Windows sandbox | 1 | Continue with direct PowerShell commands and record the environment limitation. |
| Git rejected the repository due to sandbox-user ownership during this session | 1 | Use command-scoped `safe.directory` for read-only Git inspection. |
| The `rg.exe` WinGet shim is not executable in the sandbox | 1 | Use PowerShell `Get-ChildItem` and `Select-String` for repository search. |
| PowerShell rejected piping directly from a `foreach` statement in the CSS balance check | 1 | Capture the loop results in a variable, then format the variable in a separate statement. |
| PowerShell `foreach` pipeline mistake recurred during avatar coverage audit | 2 | Corrected immediately by assigning loop output to `$rows`; retained the earlier command pattern as a known Windows-shell pitfall. |
| Browser-control JavaScript runtime is unavailable for User Info visual QA | 1 | Use the supplied reproduction screenshot plus deterministic markup/CSS regressions and responsive source verification. |
| Planning completion helper reported `0/0 phases` for the accumulated checkbox-format plan | 1 | Manually confirmed all 28 listed phases are checked complete; final verification results are recorded in `progress.md`. |
| `ctx_execute_file` blocked skill files outside the project root | 1 | Used the permitted `read` tool for the planning, ADR-format, and codebase-design skill files; retained context-mode for project-file analysis. |
| POSIX `find`, `/dev/null`, and multi-file `ls` syntax failed inside `ctx_batch_execute`'s PowerShell host | 1 | Do not repeat the shell shape; use `ctx_execute` with JavaScript filesystem/process APIs for bounded Windows-safe discovery. |
| `gh issue view` rejected combining `--comments` with `--json` | 1 | Fetch the `comments` JSON field without the incompatible `--comments` display flag and derive the needed context in JavaScript. |
| MySQL rejected a temporary table created `LIKE` its shadowed permanent name | 1 | Run the Store scope contract against isolated in-memory SQLite tables instead of touching or shadowing configured operational data. |
| A PHP one-liner lost `$n` to shell interpolation and produced a parse error | 1 | Do not repeat the unnecessary file-count probe; the full suite had already completed repository-wide PHP lint successfully before its known `rsync` packaging failure. |
