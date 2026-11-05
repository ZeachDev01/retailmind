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

## Compact reporting workspace — 2026-11-05

This iteration supersedes the earlier first-viewport layout while preserving its Operate mode and established visual world. The user confirmed compact filters at the top. One continuous bordered workspace now contains the filters, result heading, secondary CSV/print actions, summary strip, and records. Quiet internal dividers replace the gap between separate filter and result panels. Wide filters use two compact rows; intermediate layouts use two columns, and phones stack controls above the results. Gross profit retains its table/summary arrangement and financial caveats.

The same workspace applies across report types. Sales is labeled **Sales report**, with **Group sales by** offering Daily, Weekly, and Monthly; the result identifies the chosen grouping. Start date and End date appear only where applicable, and grouping appears only for Sales report. **Generate report** remains the single primary action. Empty results offer concise recovery guidance and an **Adjust report filters** link. Invalid-date and load failures remain visible through the house `data-rm-alert-processed="1"` attribute rather than being removed by automatic alert processing. Existing authorization, calculations, filter submission, CSV and print behavior remain authoritative.

Implementation scope: `src/frontend/components/report/report_generation.php` and `src/frontend/assets/css/report-generation.css`; browser coverage is maintained in `src/backend/tests/gross_profit_report_browser_test.js`. Documentation is limited to this surface brief. No durable design-system change, new dependency, identity, token, or shipping raster was introduced. `docs/PRODUCT.md`, `docs/DESIGN.md`, global token sources, and unrelated work remain outside this change.

Incumbent comparison: `docs/PRODUCT.md` supplies the single-Store, role-aware reporting purpose and calm failure language; `docs/DESIGN.md` supplies The Working Stockroom, restrained blue actions, semantic surfaces, system UI type, bordered panels and scrollable tables. The earlier surface brief and incumbent report code establish reporting behavior and gross-profit caveats. Current synthetic `desktop.png` and `mobile.png` evidence under `.impeccable/review/report-generation/` shows the joined workspace and responsive control hierarchy; `profit-dark-1440.png`, `error-light-1440.png`, and `error-dark-375.png` cover profit and failure presentation. These files are QA artifacts generated from fixtures, not live Store evidence or shipping assets. Live IAB verification was unavailable.

Verification supplied by the implementation pass: `gross_profit_report_browser_test.js` passes sales, gross profit, empty and error states in both themes at seven widths from 320–1440px. All 14 ordinary catalog variants pass checks at 1440px, 768px and 375px, including applicable controls and print. PHP lint and layout integration pass. The additional Inventory Manager access contract passes report authorization, then fails an unrelated receipt fixture with `no such column: u.display_name`; that broader check is not a full pass and the fixture was not repaired here.

Finish review verdict: **Pending independent review for this iteration.** The 2026-11-02 Ship verdict above records the prior iteration only. Pre-existing documentation drift remains: design guidance is light-token focused despite semantic dark-theme support, and `.impeccable/design.json` is absent. This page-scoped change records those gaps without repairing global documentation or tokens.
