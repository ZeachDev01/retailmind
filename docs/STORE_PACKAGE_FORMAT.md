# Encrypted Store package contract

Implemented capture/preflight boundary for #283, part of #282. Ordinary exports are encrypted `.rmpkg` files. New replacement is deliberately not enabled at this stage; Super Administrator preflight is read-only and never authorizes replacement. Privileged reconciliation, encrypted safety recovery, and cutover remain subsequent tickets.

## Runtime and bounds

Use maintained PHP 8.2+ with OpenSSL AES-256-GCM, hash/PBKDF2, fileinfo, PDO MySQL, fsync, and a 512 MB PHP memory limit. Local verification: PHP 8.5.1, OpenSSL 3.5.4, MySQL 8.4.3. No sodium/ZIP dependency. Verify these capabilities and private filesystem/locking access on each destination; local support does not prove a hosting deployment supports recovery. MySQL preflight requires CREATE TEMPORARY TABLES privilege and compatible exact table definitions.

The uncompressed package permits at most 48 MiB encrypted input, 32 MiB total decoded SQL plus uploads, 100,000 records, 2,000 image members, and 5 MiB per image. The smaller PHP upload/post limit applies to browser uploads. A capture requires at least 96 MiB free private disk after snapshot writing; allow additional space for existing retained artifacts. Increase neither package nor crypto limits from uploaded metadata. Larger Stores require an explicitly reviewed bounded/streamed format upgrade, not a silent plaintext fallback.

Format `RMSTORE1`: eight ASCII bytes `RMSTORE1\n`, random 16-byte salt, random 12-byte nonce, fixed 16-byte authentication tag, then AES-256-GCM ciphertext. The complete prefix (magic, salt, nonce) is authenticated additional data. PBKDF2-HMAC-SHA256 uses a fixed 600,000 iterations and derives exactly 32 binary key bytes from the independent passphrase. Parameters are version-fixed rather than attacker-selected. OpenSSL encryption does not derive keys implicitly. See [PHP openssl_encrypt](https://www.php.net/manual/en/function.openssl-encrypt.php) and [PHP hash_pbkdf2](https://www.php.net/manual/en/function.hash-pbkdf2.php).

Authenticated JSON contains the exact schema fingerprint, constrained `RMSQL2` SQL, producer PHP/OpenSSL versions, UTC snapshot time, random package ID, stable private installation origin ID, and an ordered member list of paths/declared byte counts/base64 contents. Every member is checked for uniqueness, canonical base64, exact size and allowed destination. There is no compression, link type, general filesystem extraction, or uploaded crypto configuration. Reject old encrypted formats and plaintext SQL in the ordinary browser path. A valid authentication tag establishes knowledge of a passphrase, not trustworthy business data.

## Consistent capture and private delivery

The existing BackupCoordinator and StoreWriteGate capture database and required referenced uploads together. An exclusive RecoveryStore request lease first drains admitted HTTP requests, including image changes outside the SQL gate; conflicting requests fail and can retry. Queued/running ML jobs prevent capture. Operators must stop independent workers/schedulers and drain work before capture: direct Python/SQL writers are not intrinsically stopped by the HTTP lease. The persistent migration pause is a later cutover boundary.

Only referenced `storage/images/<filename>` Product images and private `profile-images/<filename>` user images are included. JPEG/PNG/GIF/WebP signatures/extensions must agree. Paths are allowlisted; links, missing files, executable/unexpected content, unsafe references and unreferenced uploads reject. No application code, `.env`, mail/database credentials, dependencies, sessions, recovery state, private keys, or unrelated directories are bundled. Database `backup_operations` and `store_write_gate` rows are transient and omitted, while their schema remains compatible. Replacement must create a fresh default gate row and fresh operation/session state.

The encrypted saved artifact is decrypted and validated before completion metadata is published. Temporary SQL and interrupted encrypted captures remain private and are cleaned on failure/expiry. Cleanup targets only random-token `.capture` and `.rmpkg` files, retaining legacy `.sql`/`.rmbak` recovery files. Creation means ready on the server; successful response streaming cannot prove the browser saved the file. Downloads are bound to the requesting active administrator account and active Workspace, consumed after streaming, and expire with the temporary artifact.

Both administrator roles enter a separate 16–1024-byte backup passphrase. Save its recovery copy outside both servers and separately from the package. A login password reset cannot decrypt a package. Losing every copy of its passphrase makes that package irrecoverable. Never put either credential in filenames, URLs, audit metadata, command arguments or logs.

The existing CLI exporter also requires a passphrase; feed one newline-terminated line through protected standard input to `php -d memory_limit=512M src/backend/scripts/backup_database.php`. Use a protected secret input/pipe managed by the operator, not a literal passphrase in shell history or command arguments. The printed path is temporary private storage: archive the encrypted file separately and verify custody. No scheduling, cloud, or retention product was added.

## Read-only preflight

Super Administrator must supply their **current destination login password**, separately from the package's **backup passphrase**. Active session/account, selected Workspace, secondary membership, capability and CSRF checks precede validation. Cashier/Inventory Manager receive no package access. Administrator package access does not grant governance/restricted audit visibility.

Preflight authenticates/decrypts, checks compatibility, SQL whitelist, complete uploads, exact sizes, uniqueness and resource bounds. Private MySQL temporary tables validate data types and unique keys using actual database collation; foreign keys plus explicit unfenced historical actor columns are checked without replacing permanent records or uploads. Identity conflicts use destination MySQL comparisons, not PHP case folding. Every colliding source/destination ID, username or email is reported with both IDs and conflict kind; numeric equality never proves the same person. Preparation requires the later explicit Super Administrator reconciliation workflow. No preflight result is a replacement authorization.

## Explicit security preservation inventory

This is the required replacement boundary, not a heuristic based on table names/password columns. #284/#285 implement and verify reconciliation before enabling replacement.

| Destination-authoritative state | Required dependent closure |
| --- | --- |
| `users` with primary/secondary `super_admin`, Recovery Account flag or `recovery_accounts` membership | Complete auth fields/status/locks/session versions/profile identity; all `user_roles` memberships and applicable `user_privileges`; users needed to attribute preserved evidence, kept distinct without revived login authority |
| `recovery_accounts` | Sealed activation/rotation/use hashes and dates, identity and role memberships |
| `roles`, `privileges`, `role_privileges` | Fixed destination policy; map operational memberships by supported role name; no source privileged grants |
| `platform_settings`; `attention_settings` platform-scope rows | Updating actors and platform dependencies |
| `activity_log` security/recovery/platform-setting/recovery-account evidence; `restore_preserved_activity`; `login_attempts` | Distinct origin/original log IDs, source and destination actors/targets; failed-sign-in review watermark attempt IDs and payloads; no evidence overwritten by numeric collisions |
| `emergency_access_sessions`, protected-user `password_reset_tokens` | Destination session actors/revokers and expiration/revocation; never import source tokens/emergency authority |
| `notification_preferences`, `notifications`, `attention_states` protected-user/platform rows | Workspace/platform keys, notification ID allocation and actor closure |
| `email_delivery_log`, `backup_history` | Destination evidence and `performed_by`, collision-free evidence provenance |
| External restore log, recovery state, session epoch, application logs, environment/configuration/session storage | Retained outside the imported database; never included as source recovery authority |

Operational replacement covers Store sales, stock, Products, operational users/memberships, business settings, and source historical records. Source privileged actors remain attributable but cannot import privileged access; secondary memberships and sealed Recovery Accounts count. Every retained/imported historical actor must resolve correctly. Declared user FKs plus non-FK `branches.created_by`, `sales.discount_authorized_by`, `restore_preserved_activity.user_id` require validation. Polymorphic audit record IDs and embedded snapshots retain origin evidence; never blanket-rewrite arbitrary numeric JSON. Protected destination profile files and historical actor dependencies survive operational upload replacement. Provenance must distinguish both installations, including colliding log/user IDs. No source identity is silently combined with destination identity.

## Verification

Use disposable databases/uploads only:

- `php -d memory_limit=512M src/backend/tests/store_package_format_test.php`
- Set `RUN_DB_TESTS=1`, then `php -d memory_limit=512M src/backend/tests/database_backup_workflow_integration.php`.
- Set `RUN_DB_TESTS=1`, then `node src/backend/tests/backup_package_browser_test.js`.

The workflow test covers a complete migrated schema, Unicode/binary rows, actual image bytes, requester binding, both roles, wrong independent credentials, identity conflicts, and existing legacy recovery failure injection. Format tests exercise authenticated hostile manifests and tampering. Browser coverage uses actual screens/HTTP/session/CSRF. These checks do not substitute for the later supervised non-production destination restore/recovery drill. No live Store or Hosted Demo import is authorized.
