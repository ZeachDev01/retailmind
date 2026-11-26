# Scheduled Backup folder selection

Confirmed 2026-11-26.

The Super Administrator chooses the Backup Destination from Store PC drives and folders using Choose folder in Platform Settings → Scheduled Backup. The selector is available only through a direct localhost connection on the Store PC. Another computer sees the destination read-only and cannot browse folders or change the destination, including through a direct POST. LAN and tunnel access do not qualify as Store PC access. Existing schedule weekday/time controls remain available.

The request check requires a loopback peer and localhost/loopback host, and rejects forwarding/proxy headers. A proxy that strips all origin metadata and rewrites the host to localhost is indistinguishable from a direct local request; deployed proxies must preserve remote-origin metadata. TestSprite's opaque tunnel exhibited this limitation, so its read-only verification uses a test router that explicitly models a LAN peer. The router is restricted to PHP's CLI test server and the existing disposable-database fixture.

Use this folder fills the destination field. Save schedule applies it through the existing destination validation. Cancel keeps the previous field value. On the Store PC an absolute path can still be entered directly. Blank keeps the existing private default.

Only directory names are listed. Application/web-root directories and private recovery storage are hidden. Existing schedule authorization, CSRF protection, writable-folder/free-space checks and retention remain in force. The weekly task continues without an open browser. A copy on another PC is obtained through the existing backup download flow.

This clarifies Backup Destination as already defined in GLOSSARY.md; it introduces no new domain term or architectural decision.

## Verification

- `npm run check`: passed; existing duplicate `open-register` ID warning in `cashier/shifts.php` remains.
- `php src/backend/tests/backup_destination_browser_test.php`: passed directory-only listing, private/public-root exclusions, invalid paths and parent navigation.
- Scheduled backup settings, schedule and service contracts: passed.
- `node src/backend/tests/backup_destination_browser_test.js`: passed real HTTP selection, navigation, cancellation, save/reload, CSRF and role checks against a disposable database; 1440px, 390px and 320px in light/dark themes. Also checks read-only remote rendering, rejected remote browse/save requests, unchanged stored destination and restored local selection. Rendered local dialog and remote read-only view inspected.
- [TestSprite read-only run](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/761cacbd-1005-4637-927b-730721bf1035/test/63b06da3-d546-4f22-82eb-f7f550e41b8b): passed all 8 executed steps with a simulated LAN peer. The first unmodified TestSprite tunnel run failed because its request metadata was indistinguishable from localhost; this is a harness limitation, not evidence of physical remote-device verification.

No actual weekly backup or physical second-PC/network-drive test was run. Store settings and backups were not changed during verification.
