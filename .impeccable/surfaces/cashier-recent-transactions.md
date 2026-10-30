---
version: 1
slug: "cashier-recent-transactions"
primary_target: "src/frontend/components/cashier/dashboard.php#cashier"
related_targets: ["src/frontend/assets/css/cashier.css"]
---

# Cashier recent transactions

Scope: Recent transactions inside `dashboard.php#cashier` only. Mode: Operate. Cashiers scan recent sales and open receipts during Store work. Preserve transaction data, escaping, receipt destinations, tabs, and the hourly sales chart.

## Direction contract

THESIS: A quiet sales ledger with stable columns and a clear heading immediately above its records, below the existing hourly chart.

OWN-WORLD: Inherit RetailMind's navy ink, blue actions, cool neutral surfaces, system typography, restrained borders, and shared theme variables. No new world, palette, fonts, or global tokens.

STORY: Identify the receipt, compare its peso total and item count, then check payment and time or open the receipt. Payment methods are neutral facts, not semantic statuses.

FIRST VIEWPORT: A heading and View all action lead into six proportional columns. Right-aligned numeric values use tabular figures. At 1050px and below, each transaction becomes a two-column ledger with visible field labels and quiet row rules.

FORM: Local table redesign using existing PHP data and CSS conventions. Keep explicit table roles and column headers through responsive display changes, a keyboard-focusable overflow region, and calm centered empty-state guidance. No shipping raster assets.

FINISH: Ship verdict after correcting empty-state centering specificity and recapturing desktop and mobile evidence. Verification scope remains the fixture and live accessibility tree described below.

## Implementation and system fit

- Heading placement, alignment, quiet rules, neutral payment badges, and shared blue receipt links follow the established operational world. The mobile ledger is a local adaptation of the system's dense-table guidance; it does not redefine tables globally.
- Data cells use `.875rem`, column headers and payment badges `.8125rem`, and mobile field labels `.75rem`. These compact sizes remain below the documented `1rem` Body and `.95rem` Control roles; record the local density choice and inherited compact-typography advisories without repairing unrelated typography or changing system tokens.
- Existing panel geometry and elevation remain inherited. `docs/DESIGN.md`, `.impeccable/design.json`, and the separate GCash surface brief are unchanged.

## Verification and limits

- PHP lint, targeted `git diff --check`, and populated/empty fixture checks passed across seven viewport widths from 320px to 1600px, including readability and alignment assertions. The live accessibility tree confirmed the table structure.
- Finish review verdict: **Ship** for the fixture-rendered surface. Captures: `.impeccable/review/cashier-transactions/{desktop,mobile,empty,empty-mobile}.png`.
- Captures load the real CSS but omit `theme_head` and the navigation shell and use a chart stub. Full live theme appearance, dark-theme contrast, and shell alignment remain unverified; these captures do not establish those properties.
