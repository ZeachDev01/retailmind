# Protected Audit Records: technical UI audit

Date: 2026-10-23 (Asia/Manila)

Implementation follow-up: the four findings below were addressed locally, in harden → optimize → typeset → polish order. The original audit is retained as the baseline. See the follow-up at the end for verification and limits.

Target: https://antiquewhite-cattle-865506.hostingersite.com/src/frontend/components/system_administrator/audit_logs.php

Scope: local PHP implementation, audit stylesheet, shared styles, theme setup, and audit query/export service. The web tool could not access the URL and no browser was connected. Findings concern the local implementation; deployed parity is unverified. No application files changed.

## Implementation integrity verdict

**Fail for record-discovery and export consistency; visual-system coherence passes source inspection.** The page uses product-specific review language, role-aware titles, shared theme tokens, and native disclosures. However, an undisclosed history cap and differing client/server search semantics undermine the audit workflow. The mechanical detector found only two typography advisories; these do not prove functional or accessibility correctness.

## Audit health score

Scores are provisional source-review assessments, not live WCAG or performance certification. Untested behavior does not earn a perfect score.

| Dimension | Score | Key evidence |
| --- | --- | --- |
| Accessibility | 3/4 | Labels, required controls, status announcements, native details, and focus styles exist; runtime keyboard and contrast checks pending. |
| Performance | 2/4 | Up to 1,000 complete rows and detail payloads rendered before client pagination. |
| Theming | 3/4 | Shared variables and explicit dark popup overrides; rendered vendor states unverified. |
| Responsive design | 3/4 | Scrollable tables, mobile stacking, and major 44px targets; viewport, zoom, and touch checks pending. |
| Implementation integrity | 2/4 | History cap and CSV search mismatch; two minor detector advisories. |
| **Total** | **13/20** | **Acceptable: significant work needed; provisional.** |

## Executive summary

Four findings: **P0: 0, P1: 2, P2: 1, P3: 1**. Address history coverage and export consistency first, then measure row-payload cost. Typography alignment is low priority.

## Findings

### [P1] History search silently excludes older records

- Location: `src/frontend/components/system_administrator/audit_logs.php:138`, `:205`, `:373`.
- Category: Implementation integrity.
- Evidence: the page queries `records(... ['limit' => 1000])`, displays `count($logs)` as “Matching Records,” and initializes client-side DataTables without server-side retrieval. No visible truncation notice or controls to retrieve older results are rendered.
- Impact: when the matching database history exceeds 1,000 entries, older records cannot be found through the table. The count and empty search result can imply completeness. Unfiltered CSV export can contain more records than the table because `exportRows()` applies no limit.
- Standard: task completeness and accurate scope communication; no specific WCAG violation asserted.
- Recommendation: disclose the loaded range and total, provide visible server-backed date filtering, and make older records reachable. Reuse the service's existing `count()` and date-filter support. Keep table and export scope explicit.
- Suggested command: `$impeccable harden`.

### [P1] CSV export applies a different search after selecting matched rows

- Location: `src/frontend/components/system_administrator/audit_logs.php:438–463`; `src/backend/app/Audit/ProtectedAuditRecordService.php` search clause in `where()`.
- Category: Implementation integrity.
- Evidence: export sends both DataTables' matched row IDs and its global search string. The service combines the IDs with SQL clauses requiring the entire string to occur within a single database field. The table uses DataTables' default smart search, which can match separate words across different columns.
- Impact: a query such as `Jane refund` can match a row with user Jane and action Refund in the table, then omit it from CSV because no single field contains that phrase. Exported evidence can be incomplete despite a nonzero matching count.
- Standard: consistent filtering and data export behavior; no specific WCAG violation asserted.
- Recommendation: for a snapshot export, use the authorized matched IDs as the search result rather than reapplying incompatible text search. If moving to server-side pagination, share the same search semantics between table and export. Verify with a multi-word query spanning user and action columns.
- Suggested command: `$impeccable harden`.

### [P2] Client pagination does not bound initial detail payload

- Location: `src/frontend/components/system_administrator/audit_logs.php:138`, record-history row loop, and DataTables configuration at `:373`; `src/backend/app/Audit/ProtectedAuditRecordService.php:33`.
- Category: Performance.
- Evidence: PHP fetches and renders up to 1,000 rows, including previous/new values and applicable reviewed evidence/log excerpts. `pageLength: 25` and `deferRender: true` are applied after that HTML already exists. CSS `content-visibility` can reduce rendering work, but does not remove response bytes or initial parsing/search indexing.
- Impact: large evidence values increase response size and initialization work, especially on slower devices. This is a verified payload pattern; actual latency, memory cost, and frame rates were not measured.
- Standard: performance engineering; no measured Core Web Vitals failure asserted.
- Recommendation: measure representative large-history payloads, then paginate on the server and load heavy detail content on demand if necessary. Coordinate this with the history-coverage fix.
- Suggested command: `$impeccable optimize`.

### [P3] Two font sizes fall outside the documented type ramp

- Location: `src/frontend/assets/css/audit-logs.css:58` and `:492`.
- Category: Implementation integrity / design-system consistency.
- Evidence: the detector reports `0.9rem` for review paragraphs and `0.8rem` for mobile stat labels. Both literals are present and absent from the documented typography steps.
- Impact: minor maintenance drift; no demonstrated readability failure.
- Standard: repository DESIGN.md, not a WCAG failure.
- Recommendation: use the existing control/body and label steps where appropriate, or document these sizes if they are intentional shared conventions.
- Suggested command: `$impeccable typeset`.

## Detector assessment

Ran `impeccable.cmd detect --json` against the page PHP and audit CSS. Result: two advisory font-size findings, both verified and grouped above. Neither is an automatic accessibility defect. No reported detector findings were rejected as false positives. Token fallbacks, monospaced record IDs, and deliberate horizontal table scrolling were not treated as defects.

## Patterns and strengths

- Search scope and filtering are split between SQL and client-side state; this causes the two highest-priority findings.
- Full detail payloads are coupled to initial row rendering.
- The page consistently uses semantic theme variables, including success/danger surfaces and dark popup overrides.
- Review fields have explicit labels and native required validation; feedback uses `role="status"` and `role="alert"`.
- Native `details`/`summary` retains built-in disclosure interaction; summary labels include event context.
- Dedicated focus styles, a focusable history scroll region, reduced-motion handling, and 44px controls are present.
- Review/export POSTs include CSRF verification. HTML output is escaped and CSV cells guard formula prefixes. These are positive source observations, not a complete security audit.

## Recommended actions

1. **[P1] `$impeccable harden`**: align table/export filtering and make complete history reachable with explicit scope.
2. **[P2] `$impeccable optimize`**: measure representative payloads and remove unnecessary initial detail rendering.
3. **[P3] `$impeccable typeset`**: resolve the two documented type-ramp deviations.
4. **`$impeccable polish`**: finish with a bounded desktop/mobile check after functional fixes.

You can ask me to run these one at a time, all at once, or in any order you prefer. Re-run `$impeccable audit` after fixes to see your score improve.

Not checked: authenticated live rendering, deployed-source parity, computed contrast in both themes, screen-reader output, keyboard popup behavior, 200% text scaling, narrow viewport overflow, synthesized touch, physical devices, network timings, or export execution against actual records. Static responsive rules do not establish working touch gestures. No tests or application changes were made.

## Implementation follow-up

- Harden: DataTables now searches, sorts, filters, and paginates the full authorized history on the server. ColumnControl date/text/number conditions and CSV export use the same query filters. Export waits for applied results, and failed requests disable export and show a recovery message. Role scope, CSRF protection, HTML escaping, and spreadsheet-formula protection remain in place.
- Optimize: initial record loading is bounded to a 25-row page, with a maximum requested page size of 100. A disposable 1,034-record fixture measured serialized record-data payloads of 339,957 bytes for the previous 1,000-row load versus 8,538 bytes for 25 rows, approximately 97.5% less. This measures record data, not network-compressed HTML or Core Web Vitals.
- Typeset: review prose now uses the documented 1rem Body size and retains 75ch/1.6 reading measure; mobile metric labels use the documented 0.95rem Control size. The system font stack is unchanged. Separate typography and detector assessments informed the changes.
- Polish: reviewed light mobile and dark desktop captures. Automated Chromium checks cover seven viewport sizes (320–1440px) in both themes, including overflow, control targets, labels, and measurable text contrast. Focused interactions cover keyboard horizontal scrolling, native detail expansion, full-history search, CSV matching/no matches, ColumnControl filtering, and failed network requests. These are local automated browser checks, not physical-device validation.

Regression coverage also checks stable pagination, role restrictions, quoted cross-column searches, literal SQL wildcard characters, date validity, numeric record comparisons, and allowed sorting. The shared browser fixture now runs migrations before creating an open cashier shift, as required by the existing fiscal lifecycle migration.

The final mechanical detector returned no findings. PHP syntax and whitespace checks passed. The full smoke suite still reports five unrelated failures in unchanged files: discount authorization, system-health maintenance copy, stock-issue rejection copy, register administration markup, and cashier-shift ownership copy.

No deployment performed. Hosted behavior, physical devices, assistive-technology output, 200% text scaling, and production latency remain unverified. Full-table search can still require database scans, and CSV export retains its existing all-results memory use; large production histories need representative performance measurements before further optimization.
