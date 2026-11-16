# Shared overlay viewport verification (#467)

Date: 2026-11-17. Implementation branch: `implement/tablet-overlays-467`.

## Change and scope

Reuse the existing bounded overlay bodies and `RetailMindUI` focus/event lifecycle. Shared close controls now have 44px targets; modal/drawer bodies wrap long unbroken text; actions share a row at heights up to 480px so stacked mobile actions leave room for fields. No business, persistence, permissions, or submission code changed.

## Supplemental browser evidence

Command: `node src/backend/tests/shared_overlay_viewport_browser_test.js` (Playwright Chromium, headless, touch enabled). This renders the real Store Calendar editor and Product drawer PHP fragments without a database, with the generated house stylesheet and shared `ui.js`. It is a component browser check, not an authenticated application workflow.

Verified CSS viewports: 640x360, 800x480, 960x600, 1024x768, 1280x800, 1366x768. Each additionally reduces height to 240px and checks retained values/focus, reachable Save, unclipped Cancel, native required-date validation/focus, invalid-field visibility within the body, close/reopen value retention, focus return, wrapped long drawer errors, and shared confirmation acceptance/focus return.

Red observations before their respective fixes:

- `Shared close control has a 44px touch target` failed (36px).
- `Long error text wraps inside drawer` failed (unbroken text overflow).
- `Cancel is not clipped by modal bounds` failed at 640x240 (stacked actions).

Final result: all six viewport rows passed. Inspected captures: `output/playwright/shared-overlays/calendar-640x240.png` and `drawer-640x360.png` (generated locally, ignored by Git). The reduced-height capture demonstrates emulated layout and native validation, not a physical on-screen keyboard.

## Repository checks

- `npm run check:contracts`: passed.
- `npm run check`: stylesheet and view audit passed, then stopped at existing smoke failure: `Cashier Shift opening is owned by the Cashier workspace: missing $service->availableRegisters()`. The unchanged shift implementation already derives `$availableRegisters` from `$registerOptions`; the source needle is absent at the branch base too.
- View audit retains the existing `open-register` duplicate-ID warning in `cashier/shifts.php:224,226`.
- Remaining `npm run check` commands run individually: `tunnel_setup_block_contract.php`, `tunnel_parity_contract.php`, and `remote_access_setup_test.php` all passed.

## Pending acceptance

Primary TestSprite authenticated workflows, successful authorized writes against the independent disposable database, and their run IDs/dashboard/artifacts are recorded by the integration workflow separately. This change supplies no TestSprite pass claim. Physical tablet, actual browser chrome/keyboard, touch gestures, and network acceptance remain unverified here. Keep #467 open until required evidence is complete.
