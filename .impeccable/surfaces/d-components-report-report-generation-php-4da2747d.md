---
version: 1
slug: "d-components-report-report-generation-php-4da2747d"
primary_target: "src/frontend/components/report/report_generation.php"
related_targets: ["src/frontend/assets/css/report-generation.css"]
---

# Report generation

Mode: Operate. Store staff select a report, set its scope, review records, and export or print the same filtered result. Existing authorization, financial calculations, and report options remain authoritative.

## Direction contract

THESIS: One reporting workspace with grouped controls and a single results surface, replacing the disconnected filter, metric-card, and export blocks.

OWN-WORLD: Inherit RetailMind's navy shell, blue actions, semantic light/dark surfaces, system typography, 14px panels, and 10px controls. Quiet dividers carry hierarchy.

STORY: Choose the report and period, narrow by product or category, run it, then read and export the result. Financial caveats remain adjacent to totals.

FIRST VIEWPORT: Compact page heading, a two-row filter panel with one blue Run Report action, then a result header with secondary export controls and a continuous metrics strip. Gross profit retains a table-left, summary-right split, stacking summary first on phones.

FORM: User-confirmed grouped reporting workspace within the established visual world; code-led implementation, no new identity or generated assets.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

## Implementation and verification — 2026-11-02

Implemented the grouped reporting workspace: one settings panel, a continuous results/metrics surface, secondary exports, and a gross-profit table/summary split that stacks on narrow screens. The build retains the incumbent navy/blue theme, system typography, semantic light/dark surfaces, 14px panels, and 10px fields; financial caveats remain adjacent to totals. No new system identity or shipping raster assets.

Visual evidence: `.impeccable/review/report-generation/desktop.png`, `mobile.png`, and `profit-dark-1440.png` confirm the desktop grouping, single-column mobile controls, horizontally scrollable records, and dark profit hierarchy. These screenshots are QA artifacts only. Finish review verdict: **Ship** after resolving dark selected-period contrast (5.17:1) and moving empty recovery guidance outside the scroll wrapper; error-state recaptures are valid. Browser checks passed both themes at seven widths; PHP syntax, layout, and report-access contracts passed.

No durable system change requires updating `docs/DESIGN.md`; it remains preserved. Existing documentation is light-token focused despite the incumbent semantic dark theme, and `.impeccable/design.json` is absent; neither gap was repaired by this page-scoped change.
