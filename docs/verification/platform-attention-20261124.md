# Super Administrator attention verification

Verified on November 24, 2026 (Asia/Manila). Rules are documented in [Super Administrator attention](../specs/platform-attention.md).

## Results

- `npm run check`: passed. The view audit retains an unrelated duplicate `open-register` ID warning in `cashier/shifts.php`.
- `npm run check:contracts`: passed. Existing PHP 8.5 deprecation notices in older SQLite fixtures do not fail the suite.
- `system_health_artifact_contract.php`: passed, including cold start, demo isolation, missing active-model storage, blocked work, real worker failure, idle completed work, and missing/initialized migration setup.
- `system_health_backup_contract.php`: passed, including unused Store, first receiving, opening stock, verified backup, and missing recovery key.
- `application_log_review_contract.php`: passed, preserving optional review permissions, evidence, stale-form protection, rotation, and audit failure handling without health warnings from log entries.
- `attention_rules_contract.php`: passed, including resolution of old settings notices and cancellation of their queued emails.
- `super_administrator_dashboard_workspace_test.php` and `super_administrator_dashboard_route_test.php`: passed, including fresh Store, first operational activity, source severity, independent forecasting failures, and approved Store model age despite more recent demo training.
- Local Playwright: real PHP routes against a disposable MySQL database, at widths 1366, 768, and 390 pixels. Both Dashboard and System Health loaded their styles without page overflow. A nonempty synthetic application log and a routine settings audit record produced no attention items. Screenshots were visually inspected. The unused Store displayed “Gathering sales data,” “No backup yet,” and “Store not started.”
- [TestSprite run](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/57d6a277-2137-429f-aa9f-df1b6d424c20/test/f5e7cb13-24b3-47e6-b638-8b33dbbba6d4): **passed, 7/7 plan steps**. Login, empty attention queue, cold-start status, first-backup status, initialized database checks, and informational diagnostics were verified. TestSprite used its default viewport; device widths were checked separately with local Playwright.

No production database changes, model training, backup/restore, email delivery, or log acknowledgement were performed. Temporary test data used a disposable database.
