---
version: 1
slug: "administrator-identity-reconciliation-php-60878ea8"
primary_target: "src/frontend/components/system_administrator/identity_reconciliation.php"
related_targets: ["src/frontend/assets/css/backup_restore.css"]
---

## Direction contract

THESIS: Make identity reconciliation a clear, sequential recovery task: inspect a package, decide who each account belongs to, then confirm replacement.

OWN-WORLD: Inherit RetailMind's navy shell, cool neutral canvas, white working surfaces, Action Blue controls, system typography, and semantic warning and success states.

STORY: The operator supplies credentials, compares destination people against incoming identities, explicitly resolves conflicts, then checks preservation and access before restoration.

FIRST VIEWPORT: A compact title and return action lead into a three-stage progress strip whose completed, current and upcoming states are visible. One full-width package panel holds the upload and password fields side by side, with a single non-destructive-review note. Primary review action follows the form. Later states prioritize source decisions with a compact destination reference.

FORM: User-confirmed sequential review within the established operational world. Local page redesign; no replacement brand or concept roll required.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

## Implementation and verification

### Simplification — 2026-11-03

Mode: Operate. User confirmed that each backup person should lead, with only fields relevant to their choice visible. Package selection now has one short explanation and the actual upload limit. Account decisions precede an expandable server-account and matching-details reference. Existing-account choices reveal only the destination selector; separate-account choices reveal username, optional email and Administrator designation. Inactive fields are disabled without clearing their values; required validation follows the selected path. JavaScript-disabled submissions retain all controls and backend validation. No identity matching is automatic.

The confirmation step keeps projected access and destructive consent visible, expands preservation details on request, and uses readable classification labels. Review authorization no longer presents a restore warning; actual restore authorization and final consent retain their warnings. Dark secondary controls use the existing surface and ink tokens.

Verification: `identity_review_ui_browser_test.js` renders the real PHP template with fixture accounts, checks the AJAX transition, conditional validation and submitted fields, retained choices, Historical Actors, confirmation consent, JavaScript-disabled controls and viewport overflow. Captures in `.impeccable/review/identity-review/` cover all three steps at 1440px/390px, both decision paths and dark theme. The existing backup-review cache browser test and PHP lint pass. Manual render review found and corrected dark secondary-button contrast. Verdict: UI ready; live end-to-end verification remains limited because the local application entered maintenance and redirected to Recovery Console. No database restore was performed for this refinement. Previous verification below describes the earlier implementation, not a new restore test.

Implemented the confirmed sequence with an active-stage progress strip, adjacent package guidance, destination/conflict references, flat source-person rows with stable two-column fields, and mobile stacking. Screen-reader legends remain available; restoration uses the established destructive red action, and the active indicator has a dark-theme foreground. Inline review transitions load missing response styles before replacing content.

Verification evidence: initial, conflict, and ready-state captures at desktop/mobile and in dark theme under `.impeccable/review/`; encrypted restore and reopened-database browser flows passed for Administrator and Super Administrator using disposable databases, as recorded in the implementation handoff.

Finish-review verdict: Ship. The reviewer inspected all nine final captures and the code; both role-specific restore/reopen flows passed.

The retained navy shell, Action Blue, neutral surfaces, system typography, semantic state colors, existing controls, and restrained borders remain consistent with `docs/DESIGN.md`. No product or global design documentation changed. Existing documentation gaps remain outside this local task: `.impeccable/design.json` is absent; `docs/PRODUCT.md` still describes readable SQL backups and Super Administrator-only restoration despite the implemented encrypted-package and Administrator flows. Existing compact typography also includes sizes outside the documented global ramp; this task does not redefine that ramp.

### Distill and clarify — 2026-11-11

Mode: Operate. The page follows one action per stage: choose the package, decide each sign-in, then confirm. The progress rail marks completed steps in semantic success and the current step in Action Blue; step 2's primary button now reads "Review resolutions" and only reaches confirmation after the choices validate. The package aside was removed: upload and password share one full-width panel with the upload limit under the field, and a reviewed package becomes a single summary row with Change backup. Each source person asks one question, "How they sign in", with the matching field revealed below it. Helper copy was cut to one line per task; projected access, preservation details, the destructive consent and the red restore action remain unchanged.

Verification: `identity_review_ui_browser_test.js` passes with its fresh captures in `.impeccable/review/identity-review/` at 1440px and 390px, both decision paths and dark theme. `backup_review_cache_browser_test.js`, PHP lint and `npm run check` pass. The encrypted restore end-to-end browser test was not rerun: it replaces a disposable database and the local application was not put through a restore for this pass. No tokens, dependencies or images were added.
