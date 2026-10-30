---
version: 1
slug: "src-frontend-components-cashier-dashboard-php"
primary_target: "src/frontend/components/cashier/dashboard.php"
related_targets: ["src/frontend/assets/css/gcash.css"]
---

# GCash overview

Scope: dashboard.php#gcash only. Mode: Operate. Cashiers check expected balances, start Cash-In or Cash-Out, and review unresolved transactions during Store work. Preserve all API fields, action URLs, keyboard tabs, and shared theme support.

## Direction contract

THESIS: A balance-first service desk with shift activity alongside it and a review queue below; replace the repetitive full-width panel stack.

OWN-WORLD: Inherit RetailMind's navy ink, blue actions, cool neutral surfaces, system typography, restrained borders, and ten-pixel controls. No global system change.

STORY: Read service cash and wallet balances, start a transaction, then inspect shift totals and anything needing checking. Expected balances remain explicitly distinguished from actual checks.

FIRST VIEWPORT: A title and paired transaction actions above a wider balance panel and narrower shift ledger. A full-width review queue follows. Mobile stacks in reading order with full-width actions.

FORM: Local section redesign inside the established Cashier surface; no concept seed required. Signature interaction: native balance-details disclosure retains the last actual check.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

## Implementation evidence

- Copy distilled: removed the overview subtitle, redundant balance descriptions, initial queue placeholder, and repeated row status. Empty queues say “No transactions need checking”; row actions say “Review transaction”. Balance calculation help and the last actual check remain inside the disclosure.
- Local composition uses a 1.3:1 balance/shift split, a full-width review queue, and one column below 1100px. Below 600px, balances stack and transaction actions share the available width.
- Inherits shared blue actions, neutral surfaces, semantic warning tokens, system typography, 14px panels, and 10px controls. Section titles remain 1.05rem; expected balances receive 1.8rem emphasis. No new palette, font, or global tokens were introduced.
- Expected balances remain separate from the last actual check through native Balance details. Existing data IDs, transaction links, and tab markup remain in place.
- `docs/DESIGN.md` and `.impeccable/design.json` remain unchanged: this is a surface composition change within the incumbent system.

## Verification limits and inherited drift

- Existing browser suite passed, as reported by the implementation review. Finish review verdict: Ship; scoped warning fallback contrast was reported at approximately 6.4:1. Desktop and mobile captures in `.impeccable/review/` show the balance-first layout, stacked mobile reading order, and wrapped review metadata.
- Those captures use an isolated fixture without the shared theme and navigation shell. They do not establish live typography, dark-theme contrast, or shell alignment. The localhost application redirects to login; authenticated visual verification remains outstanding.
- Existing GCash panels use borders with no shadow, while `docs/DESIGN.md` specifies Quiet Panel elevation for dashboard panels. This inherited difference is recorded without changing the established system or repairing unrelated styles.
