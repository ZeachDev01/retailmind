# Products and Inventory Counts supplemental verification (#469)

Independent worktree, disposable `retailmind_theme_test_*` MySQL, PHP loopback `theme_router.php`, Chromium synthesized touch. No live Store data.

Red/green evidence:
- Existing recovery fixture failed on retired `users.branch_id` / `users.full_name`; now uses `display_name`.
- Recovery then failed because routine alert conversion hid interactive Discard controls. Persistent recovery panels now use the existing `role="note"` exemption. #470 owns refreshed full recovery evidence.
- Real Category tap after required validation/name entry failed at 640x360: Food existed but popup closed before selection. A queued focus scroll dismissed the new picker. Shared picker now dismisses on scroll only when the input anchor actually moves.
- Count reconciliation failed on `notifications.resolved_at`; reuse of `notification_test_schema()` restores all passing approval, batch, stale guard, rollback and duplicate approval checks.

Checks:
- `RUN_DB_TESTS=1 node src/backend/tests/products_counts_viewport_browser_test.js`: PASS 640x360, 800x480, 960x600, 1024x768, 1280x800, 1366x768. Real Product empty search, required validation, Category tap, encrypted explicit Resume/Discard, no Product POST on Resume, long name retained after240px height reduction, Count found-batch long entry and table-local scroll. Artifacts `C:/Users/Mike/AppData/Local/Temp/rm-products-counts-ndTxii`.
- `php src/backend/tests/inventory_count_reconciliation_test.php`: PASS.
- `node src/backend/tests/search_dropdown_browser_test.js`: PASS.
- `node src/backend/tests/shared_overlay_viewport_browser_test.js`: PASS six viewports.
- `npm run check:contracts`: PASS.
- `npm run check`: styles/views PASS; existing duplicate `open-register` warning; smoke FAIL pre-existing Cashier Shift `$service->availableRegisters()` needle.

Supplemental Chromium only. Parent owns primary TestSprite; no TestSprite viewport pass claimed. #470 owns updated full recovery/browser-to-HTTP checks. Successful Product create/edit/count and persisted exactly-once effects require actual workflow evidence, not geometry inference. Physical tablet, keyboard, final trusted HTTPS and LAN checks remain unverified. #469 stays open.
