# Agent Guidelines

For coding tasks, load the installed `ponytail:ponytail` skill in full mode unless the user selects another level or disables it.

## Working Tree Guardrail
- Preserve user WIP in the root working tree (`c:\xampp\htdocs\retailmind`): run multi-ticket integration merges in a dedicated `.worktrees/integration-*` worktree, and never run `git stash`, `git checkout --`, `git restore`, or `git reset` against the root working tree.

## Reply Language and Voice
- Mirror the user's language; Bisaya/Cebuano in, Bisaya/Cebuano out.
- Lead with the answer or action. Keep responses short and plain while preserving technical accuracy.
- Keep code, commands, paths, identifiers, and error messages exact. State critical safety risks clearly.

## Context Pointers
- Workspace routes, views, modals, CSS bundle sources (`build_styles.php`), & contract test needles: [`docs/WORKSPACE_MAP.md`](docs/WORKSPACE_MAP.md)
- Review rules, UI/UX density standards, & accessibility gates: [`CODING_STANDARDS.md`](CODING_STANDARDS.md)
- Scheduled backups, recovery-key prompts, & structure compatibility: [`docs/specs/scheduled-backups-and-recovery-keys.md`](docs/specs/scheduled-backups-and-recovery-keys.md), [`docs/adr/0028-scheduled-weekly-backups-and-managed-key-custody.md`](docs/adr/0028-scheduled-weekly-backups-and-managed-key-custody.md)
- Fast verification: `npm run check` (styles, views, smoke). Contracts: `npm run check:contracts`. For layout debugging, query `getBoundingClientRect()` via Playwright instead of scraping screenshots.
- TestSprite verification, first-time suite setup, or CI integration: read [the TestSprite skill](docs/agents/testsprite/SKILL.md). Run relevant tests and inspect failures before reporting; missing credentials mean unverified. Skip TestSprite for docs/build-config edits.
