# Domain Docs

How engineering skills should consume this repo's domain documentation when exploring the codebase.

## Before exploring, read these

- **`GLOSSARY.md`** at the repo root: the canonical vocabulary. Read it for Store
  operations, roles, sales, inventory, and forecasting terms.
- **`docs/CONTEXT.md`**: supplementary legacy context covering backup/restore,
  recovery, identity, and account concepts. Read the sections relevant to your topic.
- **`docs/adr/`**: read ADRs that touch the area being worked on.

There is no `GLOSSARY-MAP.md`; this is a single-context repo.

## Precedence

Where the two vocabulary files overlap, **`GLOSSARY.md` wins**. Surface any
conflicting definition explicitly rather than silently choosing one:

> _`docs/CONTEXT.md` defines X as…, but `GLOSSARY.md` defines it as… — using the glossary term._

Continue editing a term in the file that defines it. Move terms only via `/domain-modeling`.

## File structure

Single-context repo:

```text
/
├── GLOSSARY.md
├── docs/CONTEXT.md
├── docs/adr/
│   ├── 0001-single-store-administrator-boundaries.md
│   └── ...
└── src/
```

## Use the glossary's vocabulary

When output names a domain concept — issue title, refactor proposal, hypothesis, test name —
use the term as defined in `GLOSSARY.md`. Do not drift to synonyms it explicitly avoids.

If a needed concept is missing, either reconsider invented language, or note the real gap for
`/domain-modeling`.

## Flag ADR conflicts

If an output contradicts an existing ADR, surface it explicitly rather than silently overriding it:

> _Contradicts ADR-0007 (manual failed sign-in review), but worth reopening because…_
