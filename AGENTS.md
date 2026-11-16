# Agent Guidelines

This repository uses **Caveman** (high-density, token-efficient voice) and **Ponytail** (minimalist, anti-overengineering engineering) disciplines.

## Working Tree Guardrail
- Preserve user WIP in the root working tree (`c:\xampp\htdocs\retailmind`): run multi-ticket integration merges in a dedicated `.worktrees/integration-*` worktree, and never run `git stash`, `git checkout --`, `git restore`, or `git reset` against the root working tree.

## Ponytail: The 7-Step Minimalism Ladder
Before writing any code, walk down this ladder and stop at the first working step:
1. **YAGNI**: Does this actually need to exist? If not, stop.
2. **Re-use**: Is it already in the codebase? Use it.
3. **Stdlib**: Does the runtime standard library do this? Use it.
4. **Native**: Is there a native platform/browser feature? Use it.
5. **Dependency**: Does an already installed package do this? Use it.
6. **One-liner**: Can it be done in one clean line? Do that.
7. **Minimum**: Write the absolute least code that solves the problem.

> **Lazy != Reckless**: Never compromise on security, input validation, error handling, or accessibility. No speculative abstractions.

## Caveman: Communication Voice
- Drop conversational fluff, preamble, and pleasantries.
- Answer first: `[thing] [action] [reason]. [next step]`
- All code blocks, terminal commands, file paths, parameters, and error messages stay 100% exact.
- If critical safety or security risk, warn clearly and directly.

## Reply Language and Voice
- Mirror the user's language. Bisaya/Cebuano in, Bisaya/Cebuano out, in every skill and workflow.
- Short and plain: answer, logic, and decision first. Skip technical jargon unless the user asks for it.
- Code, commands, paths, identifiers, and error messages stay 100% exact.
- Ask questions in the user's language, in full sentences.

## Context Pointers
- Workspace routes, views, modals, CSS bundle sources (`build_styles.php`), & contract test needles: [`docs/WORKSPACE_MAP.md`](docs/WORKSPACE_MAP.md)
- Review rules, UI/UX density standards, & accessibility gates: [`CODING_STANDARDS.md`](CODING_STANDARDS.md)
- Scheduled backups, recovery-key prompts, & structure compatibility: [`docs/specs/scheduled-backups-and-recovery-keys.md`](docs/specs/scheduled-backups-and-recovery-keys.md), [`docs/adr/0028-scheduled-weekly-backups-and-managed-key-custody.md`](docs/adr/0028-scheduled-weekly-backups-and-managed-key-custody.md)
- Fast verification: `npm run check` (styles, views, smoke). Contracts: `npm run check:contracts`. For layout debugging, query `getBoundingClientRect()` via Playwright instead of scraping screenshots.

## Available Skills
- `/ponytail`: Apply 7-step minimalism ladder.
- `/ponytail-review`: Hunt for over-engineering and dead abstractions.
- `/ponytail-audit`: Audit repo for premature complexity.
- `/ponytail-debt`: Generate technical debt ledger.
- `/caveman`: Terse voice, cut token bloat by 65-75%.
- `/caveman-review`: Single-line code review (`L<line>: <severity>: <problem>. <fix>`).
- `/caveman-commit`: Conventional commit message generator.
- `/caveman-compress`: Compress documentation / rules.
- `/ultracave`: Extreme linguistic compression tier.
- `/megacave`: High-density classical format.
- `/caveman-stats`: Show token optimization metrics.

<!-- BEGIN TESTSPRITE AGENT SECTION (testsprite agent install codex) -->
<!-- testsprite-skill: testsprite-verify+testsprite-onboard+testsprite-ci v0.16.0 sha256:06c93c50afc8 -->
# TestSprite Verification Loop

Run relevant tests and inspect failures before reporting. Skip docs/build-config
edits; missing credentials mean unverified. Honor the user's CLI/MCP choice.

## 1. Preflight and project

```bash
testsprite --version
testsprite auth status
```

If missing, advise CLI install or `testsprite setup`. Find the project via
`$TESTSPRITE_PROJECT_ID`, `.testsprite/config.json`, then `testsprite project list
--output json`; ask if several match.

For a local app without a project:

```bash
testsprite project create --type frontend --name "<repo name>" --local <port> --local-host <host>
testsprite test create --plan-from plan.json --project <projectId>
testsprite test run <id> <id> --local <port> --local-host <host> --output json
```

Local projects need V3 (V2-only: exit 7, `local-origin-requires-v3`). Exploration
and `test plan generate` exit 6 before charge. Create plans with `test create
--plan-from` without `--run`; then run several ids with `--local`. Portal runs
are blocked free until `project update <id> --url https://…` sets a public URL.
For deployed projects use `project create --url`; empty suites can use `test plan
generate --project <id>` then `test plan accept`.

## 2. Run against the change

The CLI does not host apps. Create a deployed environment once; select it with
`--env <name>` or omit for Default. Unknown names list valid names. Use `--local
<port>` for local FE runs.

```bash
# Deployed frontend: create + run, or run an existing test
testsprite project env create <id> --name staging --url https://staging.example.com
testsprite test create --plan-from plan.json --run --wait \
  --env staging --timeout 600 --output json
testsprite test run <test-id> --env staging \
  --wait --timeout 600 --output json
# Backend Python assertion
testsprite test create --type backend --name "Login rejects empty password" \
  --project <id> --code-file /tmp/test.py --run --wait --timeout 600
# Several tests, one batch (V3 FE: 0.5 credit each)
testsprite test run <test-id> <test-id> --env staging --wait --output json
# Dependency batch; --filter <substr>, --status failed,blocked
testsprite test run --all --project <id> --env staging --wait --output json
```

- Supported backends grant existing keys `run:tunnel`; a narrowed key without it
  exits 3. Free-plan local runs work. BE cannot use `--local`.
- `--local` implies `--wait` (1200 s per run; ordinary: 600). `--local-host`:
  `localhost`, `127.0.0.1` (default), `::1`. A dead port exits 5 before charge;
  `--skip-preflight` skips the probe. Environment `--url` rejects private (non-loopback) hosts.
  `--local` selects/creates `local-<port>`; a named `--env` must be local.
  `--target-url` cannot combine with `--local`.
- Run local ids together (`test run <id> <id> … --local <port>`); `--all --project
<id> --local <port>` skips BE tests. One tunnel, 5 concurrent runs by default
  (`--max-concurrency` 1–10). Five live bindings per user; `tunnel_binding_limit`
  exits 11 without retry. Use `tunnel list` then `tunnel stop <id>` or `--all --confirm`.
- Keep early **stderr** `Run <runId>` receipts and any `Dashboard: <url>`.
  Stdout is the JSON channel.
- An **owned** local timeout, Ctrl-C, or poll failure requests cancellation and
  closes the tunnel. Before a terminal V3 FE verdict, the backend attempts a refund;
  check `refund.status`: cancellation does not guarantee a refund.
  `--no-cancel-on-interrupt` detaches, but the owned tunnel still closes.
- After an owned timeout, start a **new** `test run <test-id> --local <port>
--timeout 1800` (retain `--local-host`). `test wait` cannot reopen the tunnel;
  ordinary/adopted waits can resume with `test wait <run-id>` if reachable.
- Keep `tunnel start` alive; borrow with `--local <port> --tunnel-client <uuid>`.
  Adopted runs normally detach without cancellation or closing the owner's tunnel.
  If the owner has disappeared during a run, cancellation is requested by default;
  `--no-cancel-on-interrupt` skips it. A second connection with the same credential
  takes over; the first exits **10**.
- Loopback environments need `--local <port>`. Bare CLI runs selecting one by
  name or Default exit 6 (`tunnel-required`) before dispatch or charge. The Portal
  shows no Run; backend requests are refused free. Schedules and test lists get
  free BLOCKED results. A public `--env` or Default works regardless of earlier
  tunnel runs. V3 local runs use the agent path, never saved-code replay, and
  preserve saved code.
- `--wait` handles polling/backoff; don't wrap it in a retry loop.
- Backend Python runs top-to-bottom, not via pytest: call `test_*` functions.
  The sandbox has stdlib, `requests`, `pytest`, `numpy`, `scipy`; use HTTP,
  not project imports or uninstalled packages.
- Backend `--produces`/`--needs` are repeatable; `--category teardown` marks cleanup.
  Set dependencies with `test create` or edit with `test update`; do not delete
  and recreate. Use `test run --all` for producer → consumer → teardown ordering
  and variable passing. A BE run includes that closure and its side effects;
  `--skip-dependencies` selects only the named test. Triage failed producers before
  blaming consumers starved of their token/fixture.

## 3. Inspect and report

```bash
testsprite test artifact get <run-id> --out ./.testsprite/runs/<run-id>/
testsprite test steps <test-id> --run-id <run-id> --output json
```

Read failing steps, screenshots and root cause. Bare `test steps <test-id>`
shows the latest run; pin the receipt's id. For empty latest steps, choose an
earlier run from `test result <test-id> --history`. Report verdict and dashboard.

Exits: 0 passed; 1 failed/blocked/cancelled; 3 auth/scope; 4 not found;
5 validation; 6 conflict/precondition; 7 timeout/unsupported; 10 unavailable;
11 rate limited; 12 insufficient credits.

## Dry-run and setup

`--dry-run` works without credentials:

```bash
testsprite test run <test-id> --dry-run --output json
testsprite test create --plan-from plan.json --dry-run --output json
```

Setup: `npm install -g @testsprite/testsprite-cli`; `testsprite setup`.

**First-time setup:** if this repo has no TestSprite tests yet, seed a *broad* first suite across its main user flows — not just one test — each with a concrete, observable assertion, before reporting setup as done. Try `testsprite test plan generate --project <id>` then `test plan accept` first; author plans by hand only if generation is unavailable.

**CI gate:** to run TestSprite on every pull request, use `testsprite ci init github --project <id> --env <name>`, finish the `REPLACE ME` step for how PRs deploy (platform-deployed previews → `on: deployment_status`; the workflow deploys → keep `pull_request`), set the `TESTSPRITE_API_KEY` secret, then prove it on one real, non-draft pull request. If the GitHub App already gates the project, ask before adding a second gate.
<!-- END TESTSPRITE AGENT SECTION -->
