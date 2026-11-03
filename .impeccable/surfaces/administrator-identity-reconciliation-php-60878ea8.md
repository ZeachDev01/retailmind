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

FIRST VIEWPORT: A compact title and return action lead into a three-stage progress strip. Package and password fields occupy the main column; a quieter adjacent explanation states what identity review preserves. Primary review action follows the form. Later states prioritize source decisions with a compact destination reference.

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
