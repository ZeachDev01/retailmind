---
version: 1
slug: "d-components-administrator-reset-data-php-0162f155"
primary_target: "src/frontend/components/administrator/reset_data.php"
related_targets: ["src/frontend/assets/css/reset-data.css"]
---

# Reset Data

Mode: Operate. Audience: Administrator and Super Administrator managing Store data.
Scope: reset_data.php and reset-data.css; existing reset service and security contracts remain authoritative.

## Direction contract

THESIS: Make the difference between one-product removal and whole-Store reset obvious. Replace the always-expanded record list with deliberate inline review.

OWN-WORLD: Retain RetailMind's existing navy shell, blue interaction voice, white or dark theme surfaces, system font, restrained borders, and semantic danger controls.

STORY: Choose a product, understand deletion versus deactivation, then confirm. For whole-Store reset, expand the review, inspect deleted and preserved records, read the backup/sign-out/retraining consequences, then enter password and exact phrase.

FIRST VIEWPORT: Page title and one plain instruction; single-product consequences left, labeled form right; separate whole-Store disclosure below, with exact blockers visible while collapsed. Mobile stacks these regions. Four record disclosures retain all 21 totals.

FORM: Direct task clarification inside an established Operate surface. No concept seed or comp: this narrowly requested simplification inherits the existing visual world and uses native disclosures, exempt from the concept tournament under new-work section 3.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

## Quality bar

Distinguish action scope immediately; preserve all consequences and authentication safeguards; expose exact blockers; keyboard-operable native disclosure and visible focus; fields at least 44px high; no overflow at 320–1440px; readable light and dark themes. Screenshots are test evidence, not shipping imagery.

## Finished documentation and evidence

Documentation comparison completed 2026-10-14 against `docs/PRODUCT.md`, `docs/DESIGN.md`, the two target files, and shared `global.css`, `forms.css`, `theme.css`, and `backup_restore.css`. This is an existing-system extension; global product/design documents remain unchanged.

- System font inherits the documented stack from `global.css`. Page title uses the headline range's 1.8rem endpoint and 1.2 line height; section titles use 1.05rem/1.3; body and fields use 1rem/1.5.
- Panels retain the documented 14px radius; shared controls retain 10px corners. Local 1.5rem panel padding and flat bordered surfaces support deliberate review, following Border Before Shadow without creating new system tokens.
- Routine disclosure/focus uses `--primary`; destructive controls use existing danger semantics; surfaces, text, borders, and focus rings use shared theme variables. One Action Voice and State Means Something remain intact.
- Native `details`/`summary` separates whole-Store review and four record groups containing all 21 totals. Passwords, exact phrases, capability gate, CSRF, nonce, and blocker disabling remain in source. Layout stacks at 1180px, then tightens controls and disclosure headings at 760px.
- Independent finish review disposition: ship, no material UI/code defect. Render evidence: `.impeccable/review/reset-after/{desktop.png,desktop-expanded.png,mobile.png,mobile-expanded.png,desktop-dark.png}`. No shipping rasters were added; these captures are test evidence.

Pre-existing drift, not canonized or repaired: DESIGN.md records light palette values while shared CSS already provides dark-theme overrides, and its muted token is #6b7280 while `global.css` uses #626b7a. No `.impeccable/design.json` sidecar exists at the project root; none was created because this route does not change the global system. Destructive-operation verification remains with the implementation checks; documentation pass compared source and recorded finish-review evidence.
