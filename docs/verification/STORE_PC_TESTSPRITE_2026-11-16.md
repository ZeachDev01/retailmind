# Store PC acceptance — TestSprite execution, 2026-11-16

**NOT READY alang sa full Store-PC acceptance.** Nahuman ang **23 ka TestSprite cases: 10 PASS, 6 FAIL, 7 BLOCKED**, walay timed-out run. Kini case verdicts; ang 6 failed cases dili 6 confirmed application bugs. Usa ka klarong app defect ang napamatud-an: backup timestamp nga 8 oras ulahi sa Manila. Wala giusab ang app code o source checklist checkboxes.

## Test environment

- Petsa/oras: 16 Nov 2026, 17:54:01 → 16 Nov 2026, 18:17:39 (`Asia/Manila`).
- Revision: `3775518788644428e6ad60ce8711a55aaa213660`; root working tree clean sa pagsugod.
- Temporary app copy: `C:/Users/Mike/AppData/Local/Temp/retailmind-testsprite-0527ba7eaf64`; PHP 8.5.12 built-in server, dili Apache deployment acceptance.
- Origin: `http://127.0.0.1:18765`; database `retailmind_theme_test_551ea3817c9f`. Existing disposable theme fixture ug current migrations gigamit.
- Sessions/storage/uploads/logs sa temporary copy; recovery storage sa sibling directory gawas sa app/web root. Test accounts ra; walay real Store records, configured provider/email recipients o real wallet transfers.
- TestSprite CLI 0.16.0, authenticated V3; 11.5 credits used (400 → 388.5). Remote runner browser/OS version wala independently verified.
- [TestSprite project](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f). Full IDs/verdicts: [results.json](evidence/testsprite-store-pc-20261116/results.json). Private raw plans/steps/videos retained sa temporary folder; passwords, keys ug signed artifact URLs wala sa repo evidence.

## Confirmed defect: backup timestamp

`BAK-01` / `SET-04`: backup `retailmind-backup-11-16-2026-175637-a55bc4.rmpkg` gihimo sa **17:56:37 Manila**. Ang Latest backup ug Backup history nagpakita og **Nov 16, 2026 · 9:56 AM**, imbes **5:56 PM**. History record `Completed`, 138.4 KB. Focused case 17 / run `67bb376d-4d27-47cb-b008-d0d8975a8b49` failed sa exact timestamp assertion. [Recorded video frame](evidence/testsprite-store-pc-20261116/backup-timestamp.png) ug captured HTML nagtugma.

Source review: `DatabaseBackupService.php` sets zone-less `snapshot_at` pinaagi sa `gmdate`; backup views pass it to `PhilippineTime::format`, nga nagtan-aw sa zone-less SQL values isip Manila time. Source-offset mismatch kini sa snapshot display; wala magpamatuod nga sayop ang tanang transaction timestamps. Ayuhon ang snapshot offset handling ug i-retest ang Latest backup ug Backup history.

Ang final history frame nagpakita usab og file `Missing`; wala mapamatud-i nga ang package natipigan sa browser/device. Dili kini proof nga corrupted ang backup, ug dili usab backup/restore PASS.

## Money ug stock evidence

TestSprite created sale 3 for `TEST-A acceptance`: `2 × PHP25 = PHP50`, cash PHP100, change PHP50; refund 2 returned 1 unit for PHP25. Focused case 18 failed kay wala makompleto ang visible stock/drawer/reload checks. Wala kini gi-overwrite og PASS.

Bulag nga read-only PDO assertions human sa UI runs milampos: product 3 stock **99** (`100 − 2 + 1`), exactly 1 matching sale line ug 1 refund line; independent-count product 2 stock **11**. `CashierShiftService::calculateShift(1)` returned expected cash **PHP175**: `100 opening + 25 seeded sale + 50 sale2 − 25 refund1 + 50 sale3 − 25 refund2`. [Reconciliation](evidence/testsprite-store-pc-20261116/reconciliation.json). Dili kini physical cash count o complete concurrency/idempotency certification.

Ang unang count/checkout tests nakasagol sa Theme Test Item: `10 − 2 sale + 3 approved count + 1 refund = 12`. Runner ignored the product correction sa test metadata. Ang +3 count gikan sa recorded system 8 ug physical 11; reviewed ledger walay duplicate movement. Independent count retest 19 passed. Ang original case failure dili proven stock bug.

## Run verdicts ug reviewed scope

PASS limitado sa observed scoped plan. Ang related checkbox adunay ubang branches/origins nga wala ma-cover. Automatic TestSprite root-cause suggestions wala giila nga facts: ang empty-forecast-report ug refund-caching hypotheses wala gipamatud-an.

| # | Case | Verdict | Reviewed evidence / limitation |
| --- | --- | --- | --- |
| 1 | [AUTH-01 all four workspaces](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/162491b9-82ed-4c45-a567-fd282dde48bc) | PASSED | Upat ka Workspace login/landing verified. |
| 2 | [AUTH-02 AUTH-06 invalid login and logout](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/b1e1e574-ef61-40c0-ac4d-8277bd082a87) | PASSED | Invalid/blank login ug logout/direct protected-page checks passed. |
| 3 | [ACL-01 cashier direct-link restrictions](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/b1eb61fa-e850-4000-9a1e-c05cc1054459) | PASSED | Cashier protected direct links denied; exact-route retest #20 passed usab. |
| 4 | [ACL-02 ACL-03 Inventory Manager boundaries](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/1c62ee0e-5559-498e-8c07-462be134eb50) | BLOCKED | Route discovery incomplete; exact financial GET URLs passed sa #21. Exports wala verified. |
| 5 | [ACL-04 Administrator governance boundary](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/c2b71640-0ed7-433f-b774-1c2523b39122) | BLOCKED | Route discovery incomplete; exact platform GET URLs passed sa #22. Schedule mutation wala verified. |
| 6 | [ADM-04 ADM-05 ADM-07 Store setup persistence](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/d837255e-8e25-4efd-9ff4-29b3ac415435) | FAILED | Registers label wala mailhi; Store phone/threshold persisted, negative threshold rejected. Checkout Stations retest #23 passed. |
| 7 | [INV-01 INV-02 INV-06 product and receiving](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/3851f472-a2dd-41a7-acf7-e58f4d2f5ac3) | PASSED | Scoped product creation, duplicate/invalid input, 100-unit receiving ug zero-quantity validation passed. |
| 8 | [CTL-01 CTL-02 inventory counts](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/84566244-c3ca-4c08-aade-fc46662bc0d5) | FAILED | Shared-product test contamination: system8, counted11, variance+3. Concurrent refund added1. Independent retest #19 passed; dili proven double-adjustment bug. |
| 9 | [POS-04 POS-05 REF-01 checkout and refund](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/67bc2e0f-da77-4346-9f5b-a41235f12407) | FAILED | Sale50/change50 ug refund25 completed; stock/drawer/reload UI assertions incomplete. Supplemental ledger reconciles. |
| 10 | [GCS-01 GCS-03 GCS-04 GCash test records](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/e41f6186-8cc3-4a02-8b0d-0631caada9f0) | BLOCKED | Service preview reached; completion stopped at required real-world confirmations. Walay real transfer. |
| 11 | [RPT Administrator catalog and source totals](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/ce85c45d-2406-44b7-ba1a-a1faac858e29) | FAILED | 16-report/range/export/source comparison incomplete. Empty fresh-Store forecast report dili proven defect. |
| 12 | [FCT-01 FCT-07 forecasting cold start](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/37388187-5b41-4df0-9582-e4426342a74f) | BLOCKED | Cold-start pages reached; full filters/details/history/model verification incomplete; walay training launched. |
| 13 | [BAK-01 encrypted manual backup and review](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/bf267b34-199f-4458-8898-e0cb618bebaf) | BLOCKED | History Completed138.4KB; device download/key/restore validation unverified. Timestamp defect confirmed sa #17. |
| 14 | [AUTH-09 AUTH-12 AUTH-13 shared UI](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/06493029-dd45-4f3c-a1ef-bbf143673bdd) | BLOCKED | Theme/mobile/keyboard/recipient verification incomplete; walay proven theme-persistence defect. |
| 15 | [AUTH-03 mandatory first-login password](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/863e15c3-ea7c-4204-842b-a9fda296ad7b) | BLOCKED | Password change/new login observed; mandatory-route bypass check incomplete. Old-password rejection retest #16 passed. |
| 16 | [AUTH-03 focused old-password rejection](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/970e84b5-5cfb-48fd-99d2-1ddc62b1d31e) | PASSED | Old temporary password rejected with exact Invalid username or password message. |
| 17 | [BAK-01 focused backup timestamp and history](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/69376873-f175-4f2a-88a1-1fbdfc84fe27) | FAILED | CONFIRMED DEFECT: backup timestamp9:56AM imbes5:56PM Manila; existing Completed138.4KB record. |
| 18 | [POS-05 REF-01 deterministic TEST-A ledger](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/9298576b-7d34-4865-ab5f-5b8c3e13825a) | FAILED | TEST-A sale50/refund25 completed; UI stock/drawer/reload verification incomplete. PDO proves stock99, one sale, one refund, drawer175. |
| 19 | [CTL-01 focused independent count approval](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/167f20b7-be3f-4038-aec2-2e63c9f725ba) | PASSED | Independent count stock10→11 after approval; no repeat-approval control; PDO stock11 verified. |
| 20 | [ACL-01 focused exact Cashier routes](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/d252603f-4494-4d9b-81c5-530a25288c78) | PASSED | Cashier denied sa5 exact protected GET routes. |
| 21 | [ACL-03 focused exact financial report routes](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/27210293-36c3-4d99-9143-29e271f92cfb) | PASSED | Inventory Manager denied sa4 exact financial-report GET routes. POST exports not tested. |
| 22 | [ACL-04 focused exact Administrator platform routes](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/4541117b-ae4e-4648-8b7d-84dcf2a97cd4) | PASSED | Administrator denied sa3 exact platform/reset/technical-model GET routes. POST writes not tested. |
| 23 | [ADM-04 focused Checkout Stations direct route](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/42d35791-e42f-4c52-8968-67f227988f7f/test-cases/6bfce47b-b9b0-4cef-b1c7-014538f85ab3) | PASSED | Checkout Stations new58mm test station persisted; duplicate/blank name rejected; active seeded station preserved. |

## Checklist coverage ug remaining work

Ang source adunay **219 numbered rows**, dugang ang final sign-off checklist. **61 rows** adunay plan targets (daghan partial); **158 rows** wala ma-execute niini nga suite. Walay claim nga 219 rows nasulayan o pasar. [Complete ID coverage matrix](evidence/testsprite-store-pc-20261116/coverage.md).

- Full 16-report dates/filters/exports/source-total reconciliation incomplete.
- Full GCash completion/handover/correction, account/profile/multi-role isolation, promotions, packs, pullouts, complete refund branches ug form-recovery drills need dedicated fixtures/plans.
- Forecast import/training/approval lifecycle wala gi-execute. No-history collecting state dili defect o proof nga production forecasting ready.
- Invalid/missing CSRF, tampered IDs, disabled-account sessions, tanang restricted POST/export endpoints ug actual Apache/private-path protection wala fully tested. GET denials dili substitute.
- Scheduled jobs, actual reboot/startup, WAN-off fallback, physical LAN/mobile-data tunnel, scanner/thermal printing ug actual devices wala gi-execute.
- Downloaded encrypted-package validation, recovery-key export/fingerprint matching, older-package compatibility, actual restore/reopening ug interruption drill wala napamatud-i.
- Final live Store configuration ug owner/technician sign-off wala gihimo.

## Evidence limitations ug cleanup

`test plan generate` returned `PRECONDITION_FAILED / local-origin-project`; agent-supplied plans gigamit. Broad cases usahay mihunong sa partial verification. Focused exact-route retests nagbulag sa route-discovery blockers gikan sa actual permission denial.

`testsprite test artifact get` sa sampled failures returned `NOT_FOUND`, reason `environment_changed`, human matangtang ang temporary local environments. Pinned `test steps --run-id` ug result records downloaded; captured HTML ug terminal video frames sa tanang 13 failed/blocked cases reviewed. Full-resolution backup frame retained sa repo; raw videos/frames private. Original verdicts ug retest run IDs preserved.

TestSprite tunnel inventory empty human sa runs. Test PHP server stopped human sa evidence collection. Disposable database/app/evidence retained privately for reproduction; wala gi-drop/reset ang existing Store databases. App/config wala gi-edit. `npm run check` ug contracts wala gipadagan: testing/report task kini, ug dili sila kapuli sa requested TestSprite runs.

Sunod: ayuhon/retest ang backup timestamp; kompletuhon ang blocked/untested software rows gamit ang deterministic fixtures; dayon actual-device/network/recovery rehearsal ug owner sign-off. Final Store decision: **NOT READY**.

