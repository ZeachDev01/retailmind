---
version: 1
slug: "reset-data"
primary_target: "src/frontend/components/administrator/reset_data.php"
related_targets: ["src/frontend/assets/css/reset-data.css"]
---

## Direction contract

THESIS: Two destructive tasks, read as a sequence. The operator removes one product or reviews exactly what the full reset deletes, then confirms with a password and typed phrase.

OWN-WORLD: The shared Super Administrator workspace grammar: `.main-content`, the `.page-heading` title with its Danger zone chip, and `.dashboard-section` panels at the house 14px radius and quiet shadow. A compact step pill marks each task, Action Blue owns the primary control, and single-product removal stays an outlined danger action.

STORY: Step 1 removes one product. Step 2 opens the retained-record review and its confirmation. A finished reset surfaces the pre-reset backup as the next thing to take.

FIRST VIEWPORT: The standard page heading with the Danger zone chip in its action slot, then Step 1's panel with the product field, password, typed phrase, and outlined delete action. Step 2 waits collapsed, with its blocker line when the Store cannot reset.

FORM: Local refinement of an existing operational screen. Existing shell, sidebar, authorization, nonce, CSRF, password and confirmation behavior are unchanged. No new tokens, dependencies or images.

## Implementation and verification

### Redesign with clarify and distill — 2026-11-11

Mode: Operate. The page now uses the change-password card language: 16px white cards with a gradient top rule, tinted icon tiles, inline step pills, pill badges, 46px fields with the Action Blue focus ring, and one primary, one outlined danger, and one solid danger action. The previous two-column intro/form split became a single card header above a full-width form, so the product, password, and typed confirmation read top to bottom on every width.

Copy was cut to one line per task: the Step 1 header carries no helper paragraph (the amber flag beside the action states the permanence), the backup card states its one sentence, and Step 2 keeps its single warning line. The disclosure now reads Review / Hide so its state is visible before opening. Disabled destructive actions show a quiet neutral surface instead of a washed-out red.

Preserved for the browser suite: `#store-reset` details and summary, four `.reset-record-group` disclosures with nineteen `.reset-record` totals, `#delete-product` search dropdown with `#delete-product-search`, `#delete-product-empty`, `#delete-password`, `#delete-confirmation`, `#reset-password`, `#reset-confirmation`, `.reset-delete`, the `p.alert` error output, `p[role="status"]` success output, nonce and CSRF fields, and the exact "Download pre-reset backup" link. The delete button opts out of the dark submit override with the house `btn-quiet` class; the reset button uses `btn-danger`.

### Harden and match the Super Administrator shell — 2026-11-11

Mode: Operate. The page stopped carrying its own visual world and now uses the classes every Super Administrator page already renders: `<body class="reset-data-page">`, `.main-content` at the shell's full width (the 1080px centered column is gone), `.page-heading` with the Danger zone chip in `.page-heading-actions`, `.dashboard-section` panels (14px, shared border and quiet shadow), `.btn` / `.btn-primary` / `.btn-danger` / `.btn-quiet`, `.form-group` fields, and `.tag-warning` for the permanence flag. The gradient card rule, tinted icon tiles, gradient primary button, and per-page alert/input/button overrides were deleted; `reset-data.css` now holds only this page's disclosure, record-list, and two-column structure.

Hardening in the same pass: blocker text wraps on any width instead of pushing the summary sideways; record labels and product options wrap rather than clipping; the dark outlined delete action keeps a transparent surface without losing its danger colour; the destructive warning stays inline beside the confirmation instead of being promoted to a transient toast; and keyboard focus on both disclosures keeps a visible ring. Empty-catalog, disabled-action, and reduced-motion states are unchanged.

The stale test fixture no longer inserts the removed `branch_id` column, so the whole reset suite now runs to completion.

Verification: `npm run check` passes (stylesheet bundle, 0 errors and 0 warnings, 113 smoke checks) and PHP lint passes. `operational_reset_browser_test.js` passes end to end against a disposable database: keyboard disclosures, nineteen retained totals, 320–1440px fit, empty catalog, authorization, confirmation, rollback, backup download, full reset, preserved definitions and audit, sessions, and isolated model cleanup. Captures at `C:/Users/Mike/AppData/Local/Temp/opencode/reset-captures2/` cover 1440×960 light collapsed and expanded, 1440×960 dark expanded, and 390×960 light collapsed and expanded. The browser was Chromium headless; physical phones, Safari, Firefox, and screen readers remain unverified. No reset was performed against the live Store.
