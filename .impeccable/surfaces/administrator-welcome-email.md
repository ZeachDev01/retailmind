# Administrator welcome email

Primary target: `src/backend/includes/functions.php` (`administrator_welcome_email_html`).
Related targets: `src/frontend/components/user_manager/user_manager.php`, `src/frontend/components/user_manager/modals/add_user_modal.php`.
Mode: Operate. Audience: newly created ordinary Administrator.

## Direction contract

**THESIS:** Make the issued credentials and mandatory next step immediately readable, without marketing content.

**OWN-WORLD:** Inherit the password-reset email's navy brand header, white document, cool grey canvas, system type and restrained borders. No new tokens, imagery or visual identity.

**STORY:** The recipient learns who created the account, reads the username and Temporary Password, then signs in and replaces the password.

**FIRST VIEWPORT:** A 520px maximum-width email with a compact RetailMind header, 24px account-ready heading, personal greeting, and stacked credential rows. Password-change instructions follow directly. No fabricated public sign-in button.

**FORM:** Local extension of the existing transactional-email composition, code-led. Precisely scoped request; no concept roll needed.

**FINISH:** unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

## Constraints and decisions

Confirmed behavior: `docs/discussions/administrator-welcome-email.md`. Inline styles, presentation tables, escaped dynamic values, readable 320px layout, exact credentials, plain-text alternative. No animation or externally loaded assets. Live delivery and native email-client rendering require separate verification.

## Finished scope — 2026-11-12

The email uses the existing password-reset composition: 520px maximum width, navy header, white document, cool grey canvas, system fonts and inline presentation tables with an Outlook-width fallback. Stacked, labelled monospace credentials precede the required first-sign-in password change. Long names and credentials wrap; password whitespace is preserved. The instructions refer to the Store link supplied by the Super Administrator.

Super Administrator-created Administrator accounts require a valid email address. The handler submits HTML and plain-text welcome messages through the existing Brevo path after account creation. A delivery failure preserves the account and shows a warning that discourages duplicate creation. Provider acceptance is described as submitted for delivery. Mandatory Password Change and ordinary Administrator-created staff behavior remain intact; no automatic resend or stored plaintext password was added.

This is a local transactional-email extension. `docs/DESIGN.md` and `.impeccable/design.json` remain unchanged: no durable global token, component, visual-world rule, dependency or shipping raster was introduced.

## Comparison and finish verdict

**SHIP.** The fresh finish review found no material findings after inspecting `.impeccable/review/admin-welcome/desktop.png`, `mobile.png`, `long-mobile.png` and `incumbent.png`.

The incumbent reset email and welcome email share their navy/white/cool-grey identity, restrained borders, system/monospace pairing and document silhouette. The welcome email replaces the centered reset code with stacked credentials and a clear next-step heading. Desktop, 320px mobile and long-content mobile browser captures were checked for horizontal overflow. These are local review fixtures with synthetic credentials, not shipping image assets or proof of inbox rendering.

Detector advisories were non-blocking: the 13px inner header radius is inherited from the reset email; 24px/18px/13px typography is email-specific, and `#4b5563` strengthens supporting-label contrast. These local values were not promoted to global tokens. The incumbent reset email already uses its own 22px/14px/12px scale and 13px inner header radius; that pre-existing difference from the workspace scale was left unchanged.

## Verification and limits

The implementation pass reported these checks passing:

- `npm run check`.
- `administrator_welcome_email_contract.php`, `email_templates_contract.php`, `user_lifecycle_contract.php`, `staff_lifecycle_audit_contract.php`, `password_change_modes_contract.php`, `store_staff_email_notice_contract.php` and `store_staff_account_rules_contract.php`.
- PHP syntax checks and `git diff --check`.

No live mail was sent. Native email-client rendering, provider delivery and inbox receipt remain unverified. Temporary credentials remain visible in the recipient's inbox until deleted; password hashes, audit records and delivery logs must not gain plaintext passwords.
