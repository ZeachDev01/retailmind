# Clean local development workspace and lightweight localhost deployment source

## Problem Statement

The RetailMind development workspace and GitHub repository contain accumulated temporary images, browser captures, generated outputs, scratch files, and historical deployment instructions. These make the codebase difficult for the team to navigate and add unnecessary material to deployment downloads. Some output directories also contain authored manuals, original research inputs, scripts, and useful evidence, so deleting entire directories would lose important work.

The intended deployment is a fresh installation on a Windows Store PC, downloaded from GitHub through Git clone. That PC hosts the application and database; existing Cloudflare Tunnel functionality provides online access to the same installation. Automatic deployment to the hosted Hostinger demo is no longer wanted.

## Solution

Clean both the local development workspace and the current GitHub source tree without refactoring application logic. Delete confirmed disposable temporary images and other useless scratch artifacts from disk and Git tracking, organize retained material, prevent generated clutter from returning, and make the existing project documentation a dependable navigation and setup guide.

Preserve Git history and document shallow cloning for deployment downloads. Remove automatic Hostinger deployment while retaining useful automated checks. Document a fresh localhost installation and the existing Cloudflare setup rather than presenting hosted deployment as the primary workflow.

## User Stories

1. As a developer, I want a tidy repository root, so that I can identify application entry points and project resources quickly.
2. As a developer, I want the existing project map to reflect real locations, so that I can navigate the codebase without guessing.
3. As a team member, I want obsolete temporary images deleted locally, so that useless screenshots do not distract from current work.
4. As a developer, I want disposable logs and scratch files removed after checking their uses, so that cleanup does not leave unexplained clutter.
5. As a maintainer, I want artifact classification before deletion, so that fixtures, authored scripts, and important evidence survive.
6. As a maintainer, I want generated artifacts excluded from future commits, so that the repository stays clean.
7. As a developer, I want current local tools, dependencies, and active worktrees preserved, so that cleanup does not interrupt development.
8. As a team member, I want manuals and original research data in documented locations, so that important work remains discoverable.
9. As a reviewer, I want retained evidence references to resolve, so that I can distinguish preserved verification from disposable captures.
10. As an installer, I want a lightweight shallow clone, so that I can download the current deployment source without all historical revisions.
11. As a maintainer, I want Git history preserved, so that existing commits and collaboration references remain usable.
12. As an installer, I want complete fresh localhost instructions, so that I can prepare a new Store PC without relying on the developer's existing machine.
13. As an installer, I want required dependencies and downloads distinguished from optional development tools, so that I install only what each task needs.
14. As an installer, I want explicit empty-database setup instructions, so that I can initialize a fresh Store safely.
15. As a Store operator, I want credentials, uploads, database contents, and Recovery Keys protected during development cleanup, so that operational data is not lost or exposed.
16. As an installer, I want background jobs and private recovery storage covered in setup guidance, so that installation includes the existing operational requirements.
17. As a Super Administrator, I want the existing local Cloudflare setup documented, so that I can enable online access to the Store PC.
18. As a remote Staff Account holder, I want current Workspace permissions preserved through the tunnel, so that repository cleanup does not change application behavior.
19. As a maintainer, I want Hostinger auto-deployment removed, so that pushing source does not upload it to the old hosted target.
20. As a developer, I want useful CI checks retained, so that removing deployment does not remove unrelated verification.
21. As a maintainer, I want obsolete deployment-specific tests adjusted, so that the test suite agrees with the chosen deployment workflow.
22. As an installer, I want required application assets, schema, migrations, and setup files present in a clean checkout, so that a fresh clone remains usable.
23. As a reviewer, I want measured before-and-after file counts and sizes, so that I can assess the cleanup's effect.
24. As a team member, I want incomplete machine-level verification reported explicitly, so that written instructions are not mistaken for a proven Store PC installation.

## Implementation Decisions

- Scope includes local development organization, current tracked repository contents, documentation, ignore rules, and removal of hosted auto-deployment. Application refactoring is separate work.
- GitHub main remains the deployment source. Preserve existing commit history and use a depth-one clone in deployment instructions. Removing current files does not remove their historical versions; do not claim ordinary full clones become equally small.
- Inventory candidate files and their consumers before deletion. Check runtime code, tests, scripts, fixtures, documentation, and relevant tool configuration. Distinguish files read as inputs from screenshot paths that are merely generated outputs.
- The user's latest cleanup decision supersedes the earlier proposal to preserve every disposable artifact in a local archive: confirmed useless temporary images and scratch files are deleted locally as well as removed from tracking. No duplicate trash archive is required.
- Do not blanket-delete output or temporary directories. Preserve or organize authored scripts, manuals, original datasets, reviewed workbooks, notebooks, necessary fixtures, and useful curated verification evidence. Update references when retained material moves; remove stale references to intentionally discarded disposable evidence where appropriate without erasing useful historical findings.
- Prefer existing documentation and archive categories. Avoid a new folder taxonomy or relocating working application modules solely for appearance. Important retained work must remain discoverable; do not silently move unique source material into an ignored location.
- Keep intentionally tracked generated assets required by the application, including its shared stylesheet bundle. Preserve database schemas, migrations, required seed data, product assets, dependency manifests, tests, and required runtime placeholders.
- Review tracked model binaries and runtime assets individually. An ignore pattern or a generated-looking name does not establish that an existing tracked file is disposable.
- Preserve local environment configuration, private recovery material, databases, uploads, installed dependencies, active tool configuration, and worktrees. Follow the existing root working-tree guardrail and preserve concurrent user changes.
- Extend ignore rules for confirmed generated clutter using the current directory layout. Already tracked disposable files must actually leave tracking; ignore rules alone are insufficient.
- Remove the automatic Hostinger deployment workflow and its active setup instructions. Adjust or remove the deployment-only contract and its runner invocation; retain independently useful package/configuration checks and existing Contracts and Playwright workflows.
- Do not change the existing hosted website, remote credentials, or database. Stopping future workflow uploads does not take the hosted site offline. Updating GitHub source requires a later explicit commit/push workflow; publishing this specification does not publish implementation changes.
- Make the main README, documentation index, project map, and deployment checklist consistent with fresh Windows localhost installation and existing Cloudflare access. Label retained hosted-demo material as historical/reference material rather than current deployment instructions.
- Document required local web/database services, supported runtime dependencies, local configuration, fresh empty-database initialization, initial credential replacement, private recovery storage, and existing background-job setup. Explain developer-only test dependencies separately. Derive commands from current source; correct references to absent directories rather than inventing replacements or bundling a runtime redesign.
- Document the existing tunnel provisioning/download route so a fresh clone does not depend on an ignored executable already present on the development PC. Preserve current authorization and Workspace boundaries: the Super Administrator stays local-only, while tunnel sessions retain the existing permitted Workspace behavior.
- No schema, API, role-capability, or application-module interface changes are intended. Existing local-deployment and tunnel decisions apply; older read-only tunnel requirements must not override the later full-Workspace-parity decision.

## Testing Decisions

- Primary acceptance boundary: the resulting repository checkout, its documented fresh-install workflow, and retained existing verification commands. Test observable outcomes rather than asserting an arbitrary folder arrangement or duplicating ignore patterns in new tests.
- Run the existing aggregate stylesheet/view/smoke/tunnel checks and deterministic contracts. These are existing repository seams; no new testing framework is needed.
- Adjust the hosted-deployment-only contract and its suite integration to reflect removal of auto-deployment. Run affected independently useful packaging/configuration checks and check retained workflow definitions for obsolete dependencies on the removed workflow.
- Validate references for moved or removed material, including links in the README, documentation indexes, project map, operational guides, research notes, and affected tests/scripts. Confirm retained fixtures remain readable and browser-output directories can still be created by their existing producers.
- Check that disposable files are absent from both local candidate locations and the tracked tree, that new representative generated artifacts are ignored, and that important retained assets remain available.
- Compare before-and-after tracked artifact counts and checkout sizes. Report the actual reduction separately from historical Git storage; do not invent a numeric size target.
- Verify a clean shallow checkout of the resulting revision contains required application, setup, schema, dependency, and documentation inputs. Do not rely on ignored dependencies, local environment files, model caches, or tunnel binaries from the developer's existing installation.
- Where feasible, follow fresh-install instructions against a disposable empty local database, then verify login and the current initial setup flow. Never import the fresh schema into the developer's or an operating Store's database.
- Machine-level Windows service startup, Cloudflare token/domain provisioning, hardware, and full fresh-PC operation require their actual environment. Report any such checks not performed; do not count a documentation review as a successful installation or change live services merely to validate cleanup.
- These seams synthesize the existing repository checks and agreed no-refactor scope. No additional interview or new test architecture is required for this specification.

## Out of Scope

- Application logic refactoring, module redesign, route moves, UI redesign, or new features.
- Git history rewriting, force pushes, branch pruning, or worktree removal.
- Installing on an actual Store PC, importing a real database, starting/stopping services, provisioning Cloudflare accounts/domains, or changing tunnel permissions as part of writing this spec.
- Upgrading or migrating an existing Store; the documented target is a fresh installation.
- Deleting important manuals, original research inputs, necessary fixtures, operational data, credentials, or Recovery Keys.
- Decommissioning the hosted website or deleting hosted databases and account secrets.
- Broad deletion of historical documentation or evidence solely because it is old.
- Running the cleanup, committing it, or pushing implementation changes during this specification-only task.

## Further Notes

The user explicitly paused cleanup and requested synthesis and publication first. No cleanup, local artifact deletion, deployment change, or database mutation has been performed in this session; only this planning document has been written.

Read-only inspection found approximately 1,871 tracked temporary-directory files, 173 primary-output files, 10 secondary-output files, 151 design-review files, four Word documents, and a root research ZIP. These are starting inventory counts, not a safe-delete list; remeasure when implementation begins.

Important findings include original research CSVs/workbooks and a notebook among outputs, a PDF manual among generated-looking material, intentionally tracked product images and stylesheet output, and a tracked model binary needing classification. Existing documentation references a missing development-work index and an absent legacy forecasting directory. These must be resolved through inspection rather than wholesale deletion or unverified setup instructions.

Local main matched the cached remote reference during initial inspection, but live remote freshness and the current hosted revision were not verified. The future implementation should report actual local changes, verification results, remaining limitations, and any later commit/push status accurately.
