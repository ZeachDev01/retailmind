# Agent Guidelines

This repository uses **Caveman** (high-density, token-efficient voice) and **Ponytail** (minimalist, anti-overengineering engineering) disciplines.

## Working Tree Guardrail
- Preserve user WIP in the root working tree (`c:\laragon\www\retailmind`): run multi-ticket integration merges in a dedicated `.worktrees/integration-*` worktree, and never run `git stash`, `git checkout --`, `git restore`, or `git reset` against the root working tree.

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

## Available Skills & Hooks
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

Hooks are configured in `.agents/hooks.json` and run automatically on session start and pre-invocation.
Mode switchers:
- `node .agents/hooks/caveman-activate.js [caveman|ultracave|megacave|off]`
- `node .agents/hooks/ponytail-activate.js [full|lite|ultra|off]`
