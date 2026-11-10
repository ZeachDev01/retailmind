---
version: 1
slug: "reset-data"
primary_target: "src/frontend/components/administrator/reset_data.php"
related_targets: ["src/frontend/assets/css/reset-data.css"]
---

## Direction contract

THESIS: Two destructive tasks, read as a sequence. The operator removes one product or reviews exactly what the full reset deletes, then confirms with a password and typed phrase.

OWN-WORLD: The change-password language inside the Super Administrator workspace: flat cool canvas, white 16px cards carrying the blue-to-cyan rule (red for destructive cards), a tinted icon tile, a compact step pill, Action Blue primary controls, and one outlined danger action for single-product removal.

STORY: Step 1 removes one product. Step 2 opens the retained-record review and its confirmation. A finished reset surfaces the pre-reset backup as the next thing to take.

FIRST VIEWPORT: Page heading with the Danger zone pill, then Step 1's card with its header, product field, password, typed phrase, and outlined delete action. Step 2 waits collapsed, with a blocker chip when the Store cannot reset.

FORM: Local refinement of an existing operational screen. Existing shell, sidebar, authorization, nonce, CSRF, password and confirmation behavior are unchanged. No new tokens, dependencies or images.

## Implementation and verification

### Redesign with clarify and distill — 2026-11-11

Mode: Operate. The page now uses the change-password card language: 16px white cards with a gradient top rule, tinted icon tiles, inline step pills, pill badges, 46px fields with the Action Blue focus ring, and one primary, one outlined danger, and one solid danger action. The previous two-column intro/form split became a single card header above a full-width form, so the product, password, and typed confirmation read top to bottom on every width.

Copy was cut to one line per task: the Step 1 header carries no helper paragraph (the amber flag beside the action states the permanence), the backup card states its one sentence, and Step 2 keeps its single warning line. The disclosure now reads Review / Hide so its state is visible before opening. Disabled destructive actions show a quiet neutral surface instead of a washed-out red.

Preserved for the browser suite: `#store-reset` details and summary, four `.reset-record-group` disclosures with nineteen `.reset-record` totals, `#delete-product` search dropdown with `#delete-product-search`, `#delete-product-empty`, `#delete-password`, `#delete-confirmation`, `#reset-password`, `#reset-confirmation`, `.reset-delete`, the `p.alert` error output, `p[role="status"]` success output, nonce and CSRF fields, and the exact "Download pre-reset backup" link. The delete button opts out of the dark submit override with the house `btn-quiet` class; the reset button uses `btn-danger`.

Verification: `npm run check` passes (stylesheet bundle, 117 views with 0 errors and 0 warnings, 113 smoke checks), PHP lint and `git diff --check` pass. `operational_reset_browser_test.js` reached its capture stage and passed the dark outlined-deletion assertion, then stopped at the pre-existing fixture failure `Unknown column 'branch_id' in 'INSERT INTO'` (`theme_fixture.php:162`), before the reset, backup, rollback, and empty-catalog steps. Captures in `C:/Users/Mike/AppData/Local/Temp/opencode/shots/reset/` cover 1440×960 and 390×960, collapsed and expanded, plus dark at 1440. Backup-ready, success, and empty-catalog states were reviewed against a static mirror of the same markup in both themes. No reset was performed against the live Store.
