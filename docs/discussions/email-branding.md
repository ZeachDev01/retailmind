# Shared email branding

Confirmed on 2026-11-15 (Asia/Manila): all outgoing RetailMind emails use a white header with the existing RetailMind logo, blue accents (`#2563eb`), the Store name as a separate label, system fonts, and one shared footer. This includes password resets, Administrator welcome messages, Daily Stock Briefs, account-status and dormancy alerts, queued operational notifications, remote-access notices, and test emails.

The shared Brevo boundary applies the frame. Purpose-specific templates supply body fragments; callers without HTML receive escaped HTML generated from their subject and plain-text message. The plain-text alternative, recipients, opt-in rules, retry behavior, and credential guidance remain intact. Table layout, inline styles and Outlook conditional tables are retained; the Stock Brief retains its small-screen column rules.

The password-reset subject is `RetailMind password reset code`. The PIN appears only in the message body, preventing new PINs from entering the subject stored in `email_delivery_log`. Existing log records are not changed.

## Logo setup

Use the Brevo image library to host `src/frontend/assets/img/retailmind-web-assets dark mode/logos/light/logo-header.png`. Set `MAIL_LOGO_URL` in the installation's `.env` to the resulting permanent HTTPS image URL. Do not use `localhost`, private network addresses, or a changing tunnel URL. The transactional API's documented attachment fields do not provide CID image controls, so no unverified inline-attachment behavior is used. See [Brevo transactional email reference](https://developers.brevo.com/reference/send-transac-email).

The user uploaded the logo to Brevo and supplied [the hosted image](https://img.mailinblue.com/12150953/images/content_library/original/6af92f9d4ba171fc965746a2.png). This installation's `MAIL_LOGO_URL` and `.env.example` use that URL.

If the image URL is absent or invalid, emails retain the shared design with a RetailMind text wordmark. Image-blocking email clients receive `RetailMind` alt text; essential instructions and codes remain text. Actual inbox delivery/rendering remains untested.

No new glossary term or ADR is needed: this is a reversible presentation change, not a new domain concept.

## Compact email composition

The 2026-11-15 redesign keeps the existing RetailMind identity and changes only email composition. The selected direction is a compact Store letter: one white reading sheet, flat credential rows, a reset-code band, a stock summary strip, and a quiet footer. These are email-specific patterns, not new rules for the application UI. The direction contract is in [email notifications](../../.impeccable/surfaces/email-notifications.md).

### Typography and colors

- Main font stack: `-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif`. Codes and credentials use `ui-monospace,SFMono-Regular,Consolas,'Liberation Mono',Menlo,monospace`.
- Message headings are 24px with approximately 1.25–1.3 line height; reset and generic headings explicitly use weight 700. Body copy is 15px with 1.65 line height. Instruction and stock section headings are 16px; supporting guidance is 13px; labels and table headers are 12px; the shared footer is 11px.
- The page ground is cool slate (`#f1f5f9`) around white paper (`#ffffff`). Headings and credentials use near-black (`#0f172a`); frame body text uses `#334155`, normal supporting text uses `#475569`, and secondary guidance uses `#64748b`. Rules use `#e2e8f0`, with stock table header rules at `#cbd5e1`.
- Existing brand blue (`#2563eb`) remains in the text fallback wordmark. The reset band uses pale blue (`#eff6ff`) and a darker blue code (`#1d4ed8`). Stock counts use red (`#b91c1c`) for out of stock, brown (`#92400e`) for low stock, and burnt orange (`#9a3412`) for expiring; text labels identify each status alongside color.

### Layout and spacing

- The fluid sheet has a 600px maximum width and an Outlook conditional 600px table wrapper. Outer padding is 24px vertically and 12px horizontally. The masthead uses 10px vertical and 20px horizontal padding, with the 156px-wide logo on the left and a separate right-aligned Store label. Its two columns use 58% and 42% widths.
- The white message area uses `28px 20px 32px` padding. Main content aligns to the same 20px horizontal inset as the masthead. The footer sits on the slate ground below the white sheet, with `16px 20px 0` padding. The frame uses rules and background changes rather than shadows or rounded cards.
- The reset code is 34px bold monospace, 1.2 line height, 4px letter spacing, and no wrapping. Its band uses `22px 12px 8px` code padding and `0 12px 20px` expiry-label padding. Safety guidance follows with a 24px top margin.
- Welcome credentials form full-width flat rows separated by thin rules. Labels use `16px 0 6px` padding; values use `0 0 16px`. Monospace values are 15px with 1.6 line height and wrap long strings; temporary passwords preserve whitespace. First-sign-in guidance follows after 24px.
- The Daily Stock Brief uses a single pale summary strip (`#f8fafc`) with three equal columns. Each count uses 24px bold type and `16px 10px` padding around its count-and-label block. Section headings have `28px 0 12px` margins; table cells use `12px 6px` padding, with 13px body values and 12px monospace identifiers. Numeric values align right with tabular numerals. Optional columns hide at viewport widths of 480px or less; table layout remains fixed and long text wraps.

## Review and verification scope

Finish review disposition: **ship**. Eight Chromium captures cover password reset, Administrator welcome, Daily Stock Brief, and a generic alert at 390px and 1440px viewport widths: [capture directory](../../.impeccable/review/email/). They match the compact reading sheet, flat credentials, code band, stock summary strip, and quiet footer. These are browser previews, not delivered-inbox evidence.

Implementation verification covered PHP email/stock contracts and `npm run check` (styles, views, smoke checks, and remote-access checks). Sender/routing behavior and message strings remain unchanged by this composition pass. Gmail and Outlook inbox delivery/rendering were not tested; retained inline styles, table markup, and the Outlook conditional wrapper do not establish actual inbox compatibility.

The source of these observations is `branded_email_html`, `password_reset_email_html`, and `administrator_welcome_email_html` in `src/backend/includes/functions.php`, plus the HTML builder in `src/backend/app/Services/StockBriefService.php`. No root `PRODUCT.md`, `DESIGN.md`, or global design sidecar was created or repaired for this surface-only change.
