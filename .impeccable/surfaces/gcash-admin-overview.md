---
version: 1
slug: gcash-admin-overview
primary_target: src/frontend/components/administrator/gcash_overview.php
related_targets: [src/frontend/assets/css/gcash-admin.css]
---

# Administrator GCash Overview

Mode: Operate. Audience: Administrator and Super Administrator reviewing Store-wide GCash balances, activity, and reconciliation exceptions.

Direction confirmed by the user: balances first, then activity and exceptions. Retain RetailMind's navy/blue workspace and existing operational contracts. Present expected service cash and GCash wallet funds as a divided balance ledger, with last-check context and session status. Explain that date filters do not change current expected balances. Visually join activity filters and filtered totals, while explicitly distinguishing period totals from current wallet funds. Follow with reconciliation differences, unresolved activity, and transaction history; in-page anchor navigation provides direct access to each area.

First viewport is intended to prioritize orientation and current expected balances before historical review. The PHP includes labeled date/Cashier filters, explanatory headings, text-based states, column header scopes, and named, keyboard-focusable table overflow regions. The scoped stylesheet specifies visible focus, 44px minimum controls, tabular numerals, quiet separators, and flat 14px panels. Four activity totals reduce to two columns at 1180px; balances stack and section padding tightens at 600px. Tables retain horizontal overflow through the existing GCash styles rather than compressing their columns. These are source-level observations, not verified rendered or assistive-technology behavior.

Documentation comparison: `docs/PRODUCT.md` and `docs/DESIGN.md` remain authoritative. The surface retains the system type stack, theme variables, blue interaction, neutral structure, and semantic status colors. Local dense-workflow departures are 1.125rem section headings, .875rem labels/body/table data, .8125rem badges/table headers, and 1.5rem activity amounts. Balance figures use 2rem, reducing to 1.75rem on narrow screens. These local sizes do not replace global typography roles or tokens. No global design document or token change; no shipping rasters.

Evidence recorded 2026-10-21: this documentation pass inspected the PHP view, dedicated stylesheet, product/design documents, and existing surface-brief format. The implementation pass reports PHP lint and `git diff --check` passing; detector feedback contained only off-ramp typography advisories, retained above as local departures. Backend and JavaScript were not changed by this extension. This documentation pass did not rerun implementation checks or verify those contracts at runtime.

Verification limit: browser inventory contained no available apps or browsers. No screenshots or live desktop/mobile, theme, filtering, pagination, anchor, waiver-dialog, keyboard, or screen-reader checks were performed. Responsive rules and accessibility markup describe implementation intent; visual fit, contrast, and live behavior remain unverified.
