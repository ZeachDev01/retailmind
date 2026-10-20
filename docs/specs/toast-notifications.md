# Routine notification feedback

Confirmed 2026-10-19:

- Routine success, information, and retryable failure feedback uses the existing toast component across page loads, URL notices, and sidebar navigation.
- Routine toasts last 4.3 seconds. Warnings retain twenty seconds; explicit durations remain supported.
- The Cashier's normal product-added confirmation lasts 2.5 seconds; low-stock warnings retain their existing timing.
- Confirmation choices, required input, and payment-recovery safeguards retain their dialogs.
- Persistent notices marked as already processed or as notes remain inline. Other inline errors, warnings, and instructions remain readable after a toast expires; success banners are replaced by toasts.
- Toasts preserve keyboard focus, provide a dismiss button, and use the existing Operator Alert sanitization and debug policy from ADR-0002.

Shared entry points: `RetailMindUI.toast` and `RetailMindUI.queueAlert` in `src/frontend/assets/js/ui.js`. Explicit acknowledgment dialogs continue through `RetailMindUI.alert`.

Additional routine callers: product form reset, missing-product validation for Stock Issues, replenishment PDF-download failures, and saving while a backup is in progress.

Verification: focused toast browser coverage, Inventory Overview, login welcome, POS desktop/tablet/mobile, full-page/sidebar Stock Issue navigation with a disposable database, wrapper safety-net behavior, backup-pause behavior, inventory alert contract, and PHP syntax checks pass. The cashier and friendly-alert contracts retain the same checkout/shift source-assertion failures reproduced against `HEAD`; these are unrelated to this change.
