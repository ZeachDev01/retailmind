# Task Plan: Dedicated Audit Logs Page

## Goal
Replace the audit-log modal with a dedicated page styled consistently with user management, and enhance its table with the project's DataTables integration.

## Phases
- [x] Phase 1: Inspect the existing audit-log and user-management implementations, routes, dependencies, and tests.
- [x] Phase 2: Implement the dedicated audit-log route/page and remove the modal entry flow.
- [x] Phase 3: Integrate DataTables using existing project conventions and preserve audit-specific behavior.
- [x] Phase 4: Run focused tests/build checks and review the final diff.

## Decisions
- Prefer the project's existing layout, components, and dependency versions over introducing a second table stack.
- Keep audit logs read-only and optimize the page for searching, sorting, filtering, and pagination.
- Use the dependency-free DataTables 3.0.4 CDN build, consistent with the project's existing CDN-based frontend dependencies.
- Retain the old audit-log URL as an authenticated 302/307 compatibility redirect rather than leaving bookmarks broken.

## Errors Encountered
| Error | Attempt | Resolution |
|---|---:|---|
| Git rejected the repository due to sandbox-user ownership | 1 | Use a command-scoped `safe.directory` setting for read-only Git inspection. |
| `rg.exe` could not launch from the WinGet shim | 1 | Fall back to PowerShell `Get-ChildItem` and `Select-String` for repository search. |
| Browser-control runtime was unavailable for visual QA | 1 | Completed repository-wide lint, smoke, route/reference, and diff checks instead. |
