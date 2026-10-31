# Encrypted Store package contract

Ordinary exports are encrypted `.rmpkg` files. Both administrator screens offer encrypted replacement through the shared restore endpoint, backed by Identity Reconciliation, a fresh verified safety checkpoint, and the Recovery Console. Super Administrator preflight remains read-only and never authorizes replacement by itself.

## Runtime and bounds

Use maintained PHP 8.2+ with OpenSSL AES-256-GCM, hash/PBKDF2, fileinfo, PDO MySQL, fsync, and a 512 MB PHP memory limit. Local verification: PHP 8.5.1, OpenSSL 3.5.4, MySQL 8.4.3. No sodium/ZIP dependency. Verify these capabilities and private filesystem/locking access on each destination; local support does not prove a hosting deployment supports recovery. Database preflight requires CREATE TEMPORARY TABLES privilege and compatible table definitions.

Package operations raise a smaller effective PHP memory limit to 512 MB when the host permits it. If the host forbids the change, the operation fails before capture or replacement; there is no plaintext fallback. Ordinary requests retain their configured memory limit.

The uncompressed package permits at most 48 MiB encrypted input, 32 MiB total decoded SQL plus uploads, 100,000 records, 2,000 image members, and 5 MiB per image. The smaller PHP upload/post limit applies to browser uploads. A capture requires at least 96 MiB free private disk after snapshot writing; allow additional space for existing retained artifacts. Increase neither package nor crypto limits from uploaded metadata. Larger Stores require an explicitly reviewed bounded/streamed format upgrade, not a silent plaintext fallback.

Legacy format `RMSTORE1`: nine ASCII bytes `RMSTORE1\n`, random 16-byte salt, random 12-byte nonce, fixed 16-byte authentication tag, then AES-256-GCM ciphertext. The complete prefix (magic, salt, nonce) is authenticated additional data. PBKDF2-HMAC-SHA256 uses a fixed 600,000 iterations and derives exactly 32 binary key bytes from the independent passphrase. Parameters are version-fixed rather than attacker-selected. OpenSSL encryption does not derive keys implicitly. See [PHP openssl_encrypt](https://www.php.net/manual/en/function.openssl-encrypt.php) and [PHP hash_pbkdf2](https://www.php.net/manual/en/function.hash-pbkdf2.php).

New format `RMSTORE2`: nine ASCII bytes `RMSTORE2\n`, the 64-character lowercase SHA-256 fingerprint of the stored hex recovery key, random 16-byte salt (reserved for layout consistency), random 12-byte nonce, fixed 16-byte authentication tag, then AES-256-GCM ciphertext. The whole prefix is authenticated additional data. The 32 random key bytes are used directly; no password derivation is needed for this high-entropy key. The encrypted manifest remains `RMSTORE1` and the SQL payload remains `RMSQL2`. Crypto parameters and size bounds remain fixed.

Cross-engine decoding retains and verifies the producer's original schema fingerprint. It compares table definitions using a narrow allowlist of observed MySQL/MariaDB representation differences, preserving string literals and substantive schema differences. MariaDB's JSON alias is equivalent only with its matching `utf8mb4_bin` collation and `JSON_VALID` check; plain `longtext` is rejected. See [MariaDB JSON documentation](https://mariadb.com/docs/server/reference/data-types/string-data-types/json) and [ADR-0021](adr/0021-validate-portable-backup-schemas-without-replaying-source-ddl.md). Replacement executes destination DDL and parameterized validated rows; producer DDL is not executed by encrypted incoming restoration. The legacy plaintext SQL recovery path retains exact-definition checks.

Authenticated JSON contains the exact schema fingerprint, constrained `RMSQL2` SQL, producer PHP/OpenSSL versions, UTC snapshot time, random package ID, stable private installation origin ID, and an ordered member list of paths/declared byte counts/base64 contents. Every member is checked for uniqueness, canonical base64, exact size and allowed destination. There is no compression, link type, general filesystem extraction, or uploaded crypto configuration. Accept supported `RMSTORE1` and `RMSTORE2` envelopes and reject other formats and plaintext SQL in the ordinary browser path. A valid authentication tag establishes possession of the encryption secret, not trustworthy business data.

## Consistent capture and private delivery

The existing BackupCoordinator and StoreWriteGate capture database and required referenced uploads together. An exclusive RecoveryStore request lease first drains admitted HTTP requests, including image changes outside the SQL gate; conflicting requests fail and can retry. Queued/running ML jobs prevent capture. Operators must stop independent workers/schedulers and drain work before capture: direct Python/SQL writers are not intrinsically stopped by the HTTP lease. The persistent migration pause is a later cutover boundary.

Only referenced `storage/images/<filename>` Product images and private `profile-images/<filename>` user images are included. JPEG/PNG/GIF/WebP signatures/extensions must agree. Paths are allowlisted; links, missing files, executable/unexpected content, unsafe references and unreferenced uploads reject. No application code, `.env`, mail/database credentials, dependencies, sessions, recovery state, private keys, or unrelated directories are bundled. Database `backup_operations` and `store_write_gate` rows are transient and omitted, while their schema remains compatible. Replacement must create a fresh default gate row and fresh operation/session state.

The encrypted saved artifact is decrypted and validated before completion metadata is published. Temporary SQL and interrupted encrypted captures remain private and are cleaned on failure/expiry. Cleanup targets only random-token `.capture` and `.rmpkg` files, retaining legacy `.sql`/`.rmbak` recovery files. Creation means ready on the server; successful response streaming cannot prove the browser saved the file. Downloads are bound to the requesting active administrator account and active Workspace, consumed after streaming, and expire with the temporary artifact.

Both administrator roles create automatically encrypted packages without entering a passphrase. The stable 256-bit key is generated in private RecoveryStore storage outside the application and document root. A local Administrator or Super Administrator verifies their current login password to export its recovery-key file or import another installation's key. Imported keys do not replace the destination key. Save a separate off-server copy for each installation; possession of both key and package permits decryption, and loss of every key copy makes its packages irrecoverable. Login-password changes do not affect decryption. Older `RMSTORE1` packages still need their original 16-1024-byte passphrase through Older backup options. Never put secrets in filenames, URLs, audit metadata, command arguments, or logs.

The CLI exporter uses the same automatic encryption: `php -d memory_limit=512M src/backend/scripts/backup_database.php`. Run it with the same private storage configuration as the web application. The printed path is temporary private storage: archive the encrypted file separately and verify custody. No scheduling, cloud, or retention product was added.

## Read-only preflight

The restoring Administrator or Super Administrator supplies their **current destination login password**; new-package decryption uses the private recovery key automatically. Active session/account, selected Workspace, secondary membership, capability and CSRF checks precede validation. Cashier/Inventory Manager receive no package access. Administrator package access does not grant governance/restricted audit visibility.

Preflight authenticates/decrypts, checks compatibility, SQL whitelist, complete uploads, exact sizes, uniqueness and resource bounds. Private MySQL temporary tables validate data types and unique keys using actual database collation; foreign keys plus explicit unfenced historical actor columns are checked without replacing permanent records or uploads. Identity conflicts use destination MySQL comparisons, not PHP case folding. Every colliding source/destination ID, username or email is reported with both IDs and conflict kind; numeric equality never proves the same person. Preparation requires explicit account choices in the shared backup review workflow. No preflight result is a replacement authorization.

## Explicit security preservation inventory

Replacement uses explicit reconciliation and preservation rules, not heuristics based on table names or password columns. Security rows are replayed from the fresh destination safety checkpoint, including evidence recorded after identity approval.

| Destination-authoritative state | Required dependent closure |
| --- | --- |
| `users` with primary/secondary `super_admin`, Recovery Account flag or `recovery_accounts` membership | Complete auth fields/status/locks/session versions/profile identity; all `user_roles` memberships; users needed to attribute preserved evidence, kept distinct without revived login authority |
| `recovery_accounts` | Sealed activation/rotation/use hashes and dates, identity and role memberships |
| `roles`, `user_roles`, code-owned `RoleCapabilityPolicy` | Fixed destination policy; map operational memberships by supported role name; no source privileged grants |
| `platform_settings`; `attention_settings` platform-scope rows | Updating actors and platform dependencies |
| `activity_log` security/recovery/platform-setting/recovery-account evidence; `login_attempts`; external recovery evidence | Distinct origin/original log IDs, source and destination actors/targets; failed-sign-in review watermark attempt IDs and payloads; no evidence overwritten by numeric collisions |
| Protected-user `password_reset_tokens` | Preserve valid destination reset state; never import source tokens or sessions |
| `notification_preferences`, `notifications`, `attention_states` protected-user/platform rows | Workspace/platform keys, notification ID allocation and actor closure |
| `email_delivery_log`, `backup_history` | Destination evidence and `performed_by`, collision-free evidence provenance |
| External restore log, recovery state, session epoch, application logs, environment/configuration/session storage | Retained outside the imported database; never included as source recovery authority |

The complete migrated schema includes `202610250001_retire_unused_storage`: `privileges`, `role_privileges`, `user_privileges`, `restore_preserved_activity` and `emergency_access_sessions` are retired. Do not recreate them for preservation; capabilities are code-owned and external restore evidence stays in RecoveryStore. Exact-schema checks require both installations to have compatible migrations.

Operational replacement covers Store sales, stock, Products, operational users/memberships, business settings, and source historical records. Source privileged actors remain attributable but cannot import privileged access; secondary memberships and sealed Recovery Accounts count. Every retained/imported historical actor must resolve correctly. Declared user FKs plus non-FK `branches.created_by` and `sales.discount_authorized_by` require validation. Polymorphic audit record IDs and embedded snapshots retain origin evidence; never blanket-rewrite arbitrary numeric JSON. Protected destination profile files and historical actor dependencies survive operational upload replacement. Provenance must distinguish both installations, including colliding log/user IDs. No source identity is silently combined with destination identity.

## Verification

Use disposable databases/uploads only:

- `php -d memory_limit=512M src/backend/tests/store_package_format_test.php`
- `php src/backend/tests/portable_store_package_test.php`
- Set `RUN_DB_TESTS=1`, then `php -d memory_limit=512M src/backend/tests/database_backup_workflow_integration.php`.
- Set `RUN_DB_TESTS=1`, then `node src/backend/tests/backup_package_browser_test.js`.
- Set `RUN_DB_TESTS=1`, then `node src/backend/tests/encrypted_restore_browser_test.js`.
- Set `RUN_DB_TESTS=1`, then `php src/backend/tests/recovery_restart_workflow_integration.php`.

## Restore and recovery

1. Create and download an encrypted package. Save the installation recovery key once, separately and off-server.
2. In either destination administrator workspace, select the backup and enter your current login password. Review the snapshot and explicitly resolve account choices, then confirm Restore and verify. The restoring operator records reconciliation evidence automatically; separate Super Administrator approval is not required. Matching IDs never establish that accounts represent the same person.
3. Original passphrases are only needed for older backups; new safety copies use automatic encryption. If transferring from another server, import its separately saved recovery key first. Confirm that newer Store records will be replaced. Stop source sales and background workers before cutover.
4. The server rechecks the plan under the Store request lock, captures and verifies a fresh safety package, records recovery operators outside the database, then replaces operational data and uploads. Failure before preparation leaves Store records unchanged; after preparation, access stays paused for recovery.
5. The browser opens `/components/backup/recovery.php`. Authenticate with retained destination credentials. Review automated stock, receipt, upload, and attribution checks; confirm the human checklist; re-enter the operator password and confirm one active Store to reopen. Everyone signs in again afterward.

Incoming replacement clears previous model approval and activation pointers and suspends forecasting recommendations. Rebuild and approve models on the destination before resuming forecasting; verified sales and inventory may reopen earlier.

If replacement fails, use **Complete Safety Recovery** or **Restart Approved Incoming Replacement** with the original incoming package. Private keys are loaded automatically for new packages; older packages still need their applicable original passphrases. These actions work with partially missing database tables. Keep the original safety artifact and recovery state; never remove the failure marker to reopen. The CLI legacy SQL recovery procedure is separate from this encrypted browser workflow. Verify hosting runtime, storage and upload limits on a disposable destination before a real migration.

The workflow test covers a complete migrated schema, Unicode/binary rows, actual image bytes, requester binding, both roles, wrong independent credentials, identity conflicts, and existing legacy recovery failure injection. Format tests exercise authenticated hostile manifests and tampering. Browser coverage uses actual screens/HTTP/session/CSRF. These checks do not substitute for the later supervised non-production destination restore/recovery drill. No live Store or Hosted Demo import is authorized.
