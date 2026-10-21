# Registers UI audit

Date: 2026-10-22. Target: `src/frontend/components/administrator/registers.php`.
Mode: Operate. Source audit only; no application changes or data mutations.

## Follow-up implementation

The authorized harden → adapt → document → polish sequence is complete. The findings below retain the original audit baseline; they are not a fresh score of the revised page.

- Width changes now require the visible Save width button.
- Delete uses the existing shared confirmation dialog, naming the Register and explaining permanence.
- Failed create/rename forms retain escaped names; failed creation retains receipt width. Failed rename stays expanded and the name field references the feedback.
- Edit targets and their grid tracks are 44px; narrow-screen creation fields use 1rem text.
- DESIGN.md now includes the established 0.95rem control typography step through a targeted merge.
- PHP syntax checks and the in-memory Register lifecycle contract passed. The existing Chromium receipt suite now also checks explicit width submission, deletion cancellation, edit focus, escaped failed-form values, retained widths, disclosure state, target dimensions, and contained overflow at 1440px, 768px, and 390px.
- Light/dark fixture screenshots were generated at those widths; representative desktop/tablet/mobile captures were inspected. The final PHP/CSS detector returned no findings, and `git diff --check` passed.

Verification used synthetic records and the shipped template/styles/shared confirmation code, with no Store database writes. The fixture omits the authenticated navigation shell. Physical touch, Safari, screen readers, JavaScript-disabled deletion, real request failure handling, and the authenticated live page remain unverified. The shared deletion confirmation depends on JavaScript. No full design-system regeneration or sidecar creation was needed for the targeted typography merge.

## Implementation integrity verdict

**Pass with isolated issues.** Register identity, availability, receipt width, and historical-reference protection match the Store workflow. The page reuses shared controls, semantic tables, theme tokens, and Operator Alerts. The bundled detector reported one advisory: `.95rem` at `registers.css:20` is outside the documented typography ramp. This is verified documentation drift, not evidence of a rendering defect; shared forms also use this size.

## Audit health score

Scores are provisional source assessments, not WCAG certification or measured browser results.

| Dimension | Score | Key finding |
|---|---:|---|
| Accessibility | 2/4 | Unannounced context change; deletion lacks error prevention |
| Performance | 3/4 | Small page script; no layout-read loops found; timings unmeasured |
| Theming | 3/4 | Shared tokens and dark variants present; rendered contrast unverified |
| Responsive design | 3/4 | Stacked form and contained table scrolling; edit target is 32px |
| Implementation integrity | 3/4 | Coherent operational structure; isolated typography drift |
| **Total** | **14/20** | **Good: address weak dimensions** |

Issues: **0 P0, 2 P1, 2 P2, 1 P3**.

## Findings

### [P1] Paper-width selection submits without advance notice

- Location: `src/frontend/components/administrator/registers.php:164`; `src/frontend/assets/css/registers.css:44`.
- Category: Accessibility.
- Evidence: `onchange="this.form.requestSubmit()"` submits the setting while CSS hides the Save width button. No visible instruction says selection saves immediately. Shared navigation fetches the response and replaces page content.
- Impact: Changing a selection can unexpectedly save and replace the current task context, disrupting keyboard and assistive-technology users. With JavaScript disabled, the explicit save control remains hidden.
- Standard: WCAG 2.2 SC 3.2.2, On Input.
- Recommendation: Remove automatic submission and expose the existing Save width button. Preserve the selected value until explicit submission.
- Suggested command: `$impeccable harden`.

### [P1] Permanent deletion has no confirmation or recovery

- Location: `src/frontend/components/administrator/registers.php:183`; `src/backend/app/Services/RegisterService.php`, `delete()`.
- Category: Accessibility / Implementation integrity.
- Evidence: Delete submits directly; its form has no `data-confirm`. The service physically deletes an unreferenced Register. Shared `ui.js` supplies confirmation only to opted-in forms. Reference checking protects historical records, but does not prevent accidental deletion of an unused Register.
- Impact: A mistaken click removes stored configuration with no normal undo path.
- Standard: WCAG 2.2 SC 3.3.4, Error Prevention (Legal, Financial, Data), for deletion of user-controllable stored data.
- Recommendation: Reuse the existing `data-confirm` form mechanism, identify the Register, and explain that deletion is permanent. Keep backend reference protection.
- Suggested command: `$impeccable harden`.

### [P2] Validation failures discard attempted names

- Location: `src/frontend/components/administrator/registers.php:56`, `:95`, `:145`.
- Category: Implementation integrity / Accessibility.
- Evidence: The catch block renders an alert, but the create input has no submitted value; rename inputs use the persisted database name and render hidden again.
- Impact: A duplicate-name or other save failure forces users to re-enter their proposed name and reopen the rename control. The feedback is not associated with the affected field.
- Recommendation: Retain escaped submitted values for the failed form, keep the affected rename form open, and associate its error with the input using `aria-describedby` and `aria-invalid` where applicable.
- Suggested command: `$impeccable harden`.

### [P2] Rename control misses the 44px touch-target goal

- Location: `src/frontend/assets/css/registers.css:19`, `:21`.
- Category: Responsive design.
- Evidence: The identity grid reserves `2rem`; the edit button is `2rem × 2rem` (32px at a 16px root). No mobile override enlarges it.
- Impact: The frequently used edit affordance is harder to acquire on touchscreens.
- Standard: Below the audit's 44px target and WCAG 2.2 SC 2.5.5 enhanced target size. This alone does **not** establish a failure of the 24px AA minimum under SC 2.5.8.
- Recommendation: Increase both the button target and its grid track to at least 44px; keep the pencil icon visually compact.
- Suggested command: `$impeccable adapt`.

### [P3] Register-name size is outside the documented type ramp

- Location: `src/frontend/assets/css/registers.css:20`.
- Category: Implementation integrity.
- Evidence: The detector flags `.register-identity strong { font-size: .95rem; }`; DESIGN.md does not list that step. Shared form controls already use `.95rem`, so the mismatch may belong in the documentation rather than this component.
- Impact: Minor maintenance ambiguity when choosing or standardizing type sizes; no verified user-facing defect.
- Recommendation: Reconcile the documented ramp with the established shared control size before changing the register name in isolation.
- Suggested command: `$impeccable document`.

## Patterns and positive findings

- Shared form conventions are unevenly adopted: confirmation exists centrally but this destructive form omits it; save affordances mix explicit buttons and automatic submission.
- Visible labels, unique IDs, contextual action names, column scopes, ordered h1/h2 headings, and a named focusable table region are present.
- Rename uses a native button, `aria-expanded`, `aria-controls`, and moves focus into the revealed input.
- Statuses include text rather than relying on color alone. Alerts escape their contents and declare `role="status"`.
- The form adapts at 1180px and 640px. The 900px table scrolls inside a bounded region; that minimum width is intentional for a data table, not a verified page-overflow defect.
- Theme variables and shared dark-theme rules cover text, fields, status labels, surfaces, and danger text. Literal fallback colors are not counted as hard-coded theming failures.
- Shared reduced-motion handling preserves short color/opacity feedback and removes spatial animation; no blanket 0.01ms workaround was found.
- The page adds only a small disclosure listener, with no layout reads or animation loop. Register loading uses a single list query, without a per-row lookup in this template.
- CSRF checks, capability checks, server validation, transactional writes, and historical-reference guards are present. These observations are not a security audit.

## Recommended actions

1. **[P1/P2] `$impeccable harden`**: Use explicit width saving, reuse deletion confirmation, and retain failed form input.
2. **[P2] `$impeccable adapt`**: Enlarge the pencil target and matching grid track.
3. **[P3] `$impeccable document`**: Reconcile the established `.95rem` step with DESIGN.md.
4. **`$impeccable polish`**: Verify the resulting desktop/mobile layouts and interaction states.

Run fixes one at a time, together, or in any preferred order. Re-run `$impeccable audit` afterward.

## Verification limits

The browser inventory returned no available apps or browsers, and opening Chrome failed. No authenticated live page, screenshot, emulated viewport, synthesized touch, physical device, screen reader, zoom/reflow test, computed contrast, asset timing, or Core Web Vitals result was available. No claims of live rendering or gesture verification are made. No mutation flows or automated application tests were run. The mechanical detector ran once against the PHP template and page stylesheet; shared CSS and scripts were inspected to verify context and avoid false positives.
