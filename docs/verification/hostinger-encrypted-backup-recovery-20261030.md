# Local backup to Hostinger recovery drill — 2026-10-30

Result: **passed on an isolated Hostinger database**. The existing Hosted Demo was inspected read-only and was not restored, migrated, or redeployed.

## Source and destination

| Item | Verified value |
| --- | --- |
| Source | Local `inventory_system`, MySQL 8.4.3 |
| Snapshot | 2026-10-30 08:21:56 Asia/Manila |
| Package | `retailmind-backup-10-30-2026-082154-a0754d.rmpkg` |
| SHA-256 | `e8995c92452b6425bee8bb7c2d714dc52588968746aea0c22451118dc42c232b` |
| Contents | 76 tables, two required uploaded images, 458,772 encrypted bytes |
| Hosting | `antiquewhite-cattle-865506.hostingersite.com`, PHP 8.3.36, MariaDB 11.8.9 |
| Isolated destination | `u125532033_rmtb4138933`, separate credentials and private recovery storage |

The test ran through a one-shot CLI job, with test inputs denied to HTTP clients. It used the updated backup services alongside the deployed application's remaining services. The destination was seeded separately, given different operator credentials, additional stock, and destination-only security evidence. This established explicit test identity links without guessing whether real source and hosted staff are the same people.

## Passed checks

- Authenticated decryption and validation of the original encrypted local package, including all 76 table definitions and both uploaded images.
- Incorrect destination password and incorrect package passphrase rejected before maintenance or replacement.
- Administrator-initiated encrypted replacement reached operator review with a fresh verified destination safety checkpoint.
- Destination privileged credentials, security settings, and security evidence recorded after identity approval survived incoming replacement.
- Imported stock matched the local snapshot rather than the deliberately changed destination quantities; required images remained present.
- Automated stock, receipt, upload, and attribution checks, human checklist confirmation, fresh operator authentication, and controlled reopening completed.
- After deliberately dropping the isolated destination's `users` table, complete encrypted safety recovery restored database access and uploads and passed verification and reopening again.

The first run exposed MySQL/MariaDB `SHOW CREATE TABLE` differences. [ADR-0021](../adr/0021-validate-portable-backup-schemas-without-replaying-source-ddl.md) records the bounded compatibility fix. Existing encrypted packages retain their producer schema fingerprint; incoming replacement uses the destination's DDL and parameterized validated rows. Regression tests cover both engine directions and reject substantive schema differences.

Local package-format, SQL-format, portability, backup route/capability contracts, recovery integration, and full browser restore/reopen tests for both administrator roles passed. The existing unrelated smoke-check failures recorded in the implementation session were not fixed by this work.

## Existing Demo prerequisites and limits

The active Demo uses `u125532033_retailmind`: 75 tables, 76 applied migrations, one Product, one user, and one Sale. Its private backup storage is available. The current local source has 76 tables and 83 migration records. The Demo's original deployed code still disables ordinary encrypted replacement.

The current local package is correctly rejected against that active database. `refund_correction_reviews` is missing. Cashier Shift and GCash review, responsibility, and evidence columns are also missing; several other tables differ in constraints or definitions. These are genuine schema differences and are not bypassed by the MySQL/MariaDB formatting compatibility fix.

Before an actual Demo replacement, deploy the reviewed application changes, reconcile and apply the missing schema changes, capture a verified destination safety copy, and explicitly resolve real account identities. This drill does not authorize a live cutover or prove the older Demo's browser workflow has been upgraded. The Hosted Demo's records, credentials, configuration, and uploaded files were not changed.

Raw reports and the original encrypted package are retained privately under `C:/Users/Mike/AppData/Local/RetailMind/backup-tests/hostinger-20261030-082154/`. Package and test passphrases are retained separately in its restricted `secrets/` directory and are not recorded here. The isolated test database is retained for inspection; temporary cron jobs and uploaded test credentials are removed after the drill.
