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

Implemented the confirmed sequence with an active-stage progress strip, adjacent package guidance, destination/conflict references, flat source-person rows with stable two-column fields, and mobile stacking. Screen-reader legends remain available; restoration uses the established destructive red action, and the active indicator has a dark-theme foreground. Inline review transitions load missing response styles before replacing content.

Verification evidence: initial, conflict, and ready-state captures at desktop/mobile and in dark theme under `.impeccable/review/`; encrypted restore and reopened-database browser flows passed for Administrator and Super Administrator using disposable databases, as recorded in the implementation handoff.

Finish-review verdict: Ship. The reviewer inspected all nine final captures and the code; both role-specific restore/reopen flows passed.

The retained navy shell, Action Blue, neutral surfaces, system typography, semantic state colors, existing controls, and restrained borders remain consistent with `docs/DESIGN.md`. No product or global design documentation changed. Existing documentation gaps remain outside this local task: `.impeccable/design.json` is absent; `docs/PRODUCT.md` still describes readable SQL backups and Super Administrator-only restoration despite the implemented encrypted-package and Administrator flows. Existing compact typography also includes sizes outside the documented global ramp; this task does not redefine that ramp.
