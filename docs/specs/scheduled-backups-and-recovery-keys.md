# Scheduled Backups, Recovery-Key Prompts, and Keyring Management

## Problem Statement

Store operators must remember to create every Database Backup manually. When they forget, the only copy of Store history is the live database and its uploads. Previous backup packages also collect with no rule for which copy should remain, recovery-key copies are easy to forget, and a Recovery Key imported from another server cannot be listed, promoted, exported, or removed.

Restoring a package made on a different database structure fails during review with an incompatible-structure message, even when the package's data is valid. The destination's own backup history may also carry a stale structure that makes history queries fail. Operators are left with a dead end instead of a clear upgrade path.

## Solution

A Scheduled Backup runs automatically every week without an operator present, contains the database and referenced uploads, is written to a configurable Backup Destination outside the web document root, and replaces the previous Scheduled Backup only after the new package is verified. The schedule, destination, and next run are visible to the Super Administrator, and a missed run is caught up once when the Store PC returns.

After every manual backup download, a modal offers the exact Recovery Key, even when that account has exported it before. The operator can download it with their current password or choose “I already have a copy”. The large persistent banner is removed. Unattended backups retain a once-per-session reminder until that account exports the key. The Recovery Key manager becomes a Backup Keyring: importing a key saves it immediately without replacing the Primary Recovery Key, and the administrator can keep both, promote the imported key to primary while archiving the old primary, export any key, or delete a key after an explicit password-confirmed warning.

Restore accepts packages from known older database structures by upgrading their rows to the current structure, preserves the destination's own migration ledger, and rejects unknown or newer structures with a clear list of what the destination does not understand.

## User Stories

1. As a Super Administrator, I want Scheduled Backups to run every week without anyone pressing a button, so that a forgotten manual backup cannot leave the Store without a recent copy.
2. As a Super Administrator, I want to choose the weekday and local time of the weekly schedule, so that backups suit the Store's closing hours.
3. As a Super Administrator, I want the schedule to default to Sunday at 11:00 PM, so that a working schedule exists before I configure anything.
4. As a Super Administrator, I want to see the next scheduled run, so that I know when the next automatic copy should appear.
5. As a Super Administrator, I want a missed Scheduled Backup to catch up once when the Store PC is next available, so that a power interruption does not leave a long gap.
6. As a Super Administrator, I want catch-up to run at most once regardless of how many weeks were missed, so that the system does not create redundant packages.
7. As a Super Administrator, I want to choose the Backup Destination, with a sensible default, so that automatic copies live on the disk I intend.
8. As a Super Administrator, I want the Backup Destination to be rejected when it sits inside the application or web document root, so that packages cannot be downloaded by URL.
9. As a Super Administrator, I want the previous Scheduled Backup removed after the new one is verified, so that weekly disk use stays bounded.
10. As a Super Administrator, I want the previous Scheduled Backup kept when the new attempt fails, so that a failed run never leaves zero automatic copies.
11. As a Super Administrator, I want manual backups and restore safety copies unaffected by Scheduled Backup retention, so that recovery evidence is never deleted unexpectedly.
12. As a Super Administrator, I want replaced Scheduled Backups marked as removed in backup history rather than silently disappearing, so that the retention action is auditable.
13. As a Super Administrator, I want backup history to show whether a row is a manual, scheduled, or restore package and whether its file still exists, so that I can tell evidence from a stale link.
14. As a Super Administrator, I want a failed Scheduled Backup to raise an attention item and, when enabled, a Store Email Alert, so that failure is not discovered only by chance.
15. As a Super Administrator, I want a Scheduled Backup to skip when another backup or recovery is active, so that it cannot collide with a manual operation.
16. As a Super Administrator, I want a skipped Scheduled Backup recorded and retried on the next run, so that a busy system does not lose the week's copy.
17. As a Super Administrator, I want the weekly run to work from the Windows scheduler without a signed-in browser, so that automation does not depend on someone keeping a tab open.
18. As a Super Administrator, I want an in-app catch-up path when the Windows task did not run, so that a disabled or missed task still recovers.
19. As a Super Administrator, I want each Scheduled Backup to include the database and referenced uploads, so that restoring it returns a complete Store.
20. As a Super Administrator, I want the new package authenticated and validated before the previous one is removed, so that retention cannot delete a good copy for a damaged one.
21. As an Administrator, I want a prompt recommending that I download the Recovery Key after a backup, so that I am reminded before I forget.
22. As an Administrator, I want a modal prompt on my next sign-in for an unattended backup, so that an unattended Scheduled Backup still reaches a person.
23. As an Administrator, I want unattended-backup prompts at most once per session, with a fresh prompt after every manual backup download, so that each downloaded package is paired with its key.
24. As an Administrator, I want downloading the Recovery Key to require my current login password, so that possession of an open session alone is not enough.
25. As an Administrator, I want the system to acknowledge the specific key only after a successful download, so that a dismissed prompt is not mistaken for a saved key.
26. As an Administrator, I want unattended reminders to stop for me after I export the key, while another administrator who has not saved it still sees the recommendation, so that responsibility is personal.
27. As a Super Administrator, I want the same recommendation and acknowledgement behavior as an Administrator, so that either role can act.
28. As an Administrator, I want importing a Recovery Key from another server to save it immediately, so that the package can be opened without another step.
29. As an Administrator, I want importing to keep the destination's Primary Recovery Key by default, so that existing packages are not stranded.
30. As an Administrator, I want the option to use the imported key for new backups, so that a migrated installation can adopt its source key deliberately.
31. As an Administrator, I want the old primary key archived rather than deleted when I promote an imported key, so that packages made with it remain recoverable.
32. As an Administrator, I want to see every key in the Backup Keyring with its fingerprint, kind, and date, so that I can identify what I hold.
33. As an Administrator, I want to export any key in the Backup Keyring, not only the primary one, so that I can move a source key back to another installation.
34. As an Administrator, I want deleting a key to require an explicit warning and my current login password, so that I cannot erase the only way to open old packages by accident.
35. As an Administrator, I want the primary key protected from deletion until another key is promoted, so that new backups always have a usable encryption key.
36. As a Super Administrator, I want key import, export, promotion, and deletion recorded in the activity log, so that key custody changes are attributable.
37. As an Administrator, I want to restore a package made on a known older database structure, so that an older Store Migration copy is not rejected.
38. As an Administrator, I want an unknown or newer database structure rejected with the missing migration names, so that I know whether to upgrade the destination or use a newer RetailMind.
39. As an Administrator, I want the destination's migration ledger preserved during restore, so that restoring an old snapshot does not roll the installation backward.
40. As an Administrator, I want the destination's backup history structure repaired by the normal migration path, so that a partially upgraded installation stops failing history and restore review.
41. As an Administrator, I want review to tell me whether a package is current, upgradable, or unsupported before I confirm replacement, so that I am not surprised after choosing accounts.
42. As a Super Administrator, I want only my role to change the schedule, destination, and retention behavior, so that an ordinary Administrator cannot redirect Store evidence.
43. As an Administrator, I want to keep creating manual backups, so that I can capture a copy before a risky change.
44. As a Super Administrator, I want the Backup Keyring and compatibility reports to avoid secrets in logs, URLs, and filenames, so that recovery evidence is not leaked by metadata.
45. As a Super Administrator, I want Scheduled Backup history to record which key fingerprint encrypted the package, so that the download recommendation matches the actual package.

## Implementation Decisions

### Scheduling and execution

- Add a **Scheduled Backup** service that decides whether a run is due, delegates package creation to the existing `DatabaseBackupService`, applies retention after verification, and records outcome. It must be callable from both a CLI entry point and an authenticated in-app path.
- Schedule state lives in platform-level settings owned by the Super Administrator: weekday (0-6), local time (HH:MM), destination path, and last successful run. Default is Sunday 23:00 in the application timezone.
- The Windows scheduled-task installer gains one RetailMind weekly-backup task. The installer remains idempotent. Because settings can change after installation, the task runs frequently enough to notice the configured time (hourly) and the service decides if the week's run is due; this avoids rewriting the Windows task on every settings save.
- Catch-up runs once when the newest successful Scheduled Backup is more than seven days old. Multiple missed weeks collapse into one run. Catch-up is throttled so it cannot run on every page load.
- A run skips when recovery is paused, another backup holds the active-operation slot, or an ML job blocks capture. The skip is recorded, and the run may be retried by the next scheduled tick or catch-up check.
- Manual backups, restore safety copies, and restore evidence are outside retention. Retention targets only packages recorded as Scheduled Backups.
- Retention runs only after the new package passes its existing decode and structural verification. It removes the previous scheduled artifact, marks its history row as removed, and never removes the current package.
- Destination validation requires an absolute path outside both the application root and the web document root, writable access, and the existing free-space threshold. Invalid configuration fails closed with an actionable message.
- Automatic packages use a stable name that includes the local timestamp and keep a history row. The download endpoint resolves only artifacts recorded for the requester's installation and authorized role.
- Failure produces an attention item and, when Store Email Alerts are enabled, one email. Repeated failures do not send a message per retry within the same day.

### Recovery-key recommendation

- Every completed backup history row records the encryption key fingerprint that the package header already carries.
- A per-account acknowledgement record ties a user to a key fingerprint and acknowledgement time. The recommendation appears when the newest completed backup's fingerprint has no acknowledgement for that account.
- The recommendation is a modal, without a persistent banner. Each manual backup download waits for the complete attachment response, hides its consumed download link, then offers the fingerprint captured with that backup. Failed downloads keep the link available and do not open the modal. The browser save action remains the operator’s responsibility.
- Manual downloads always show the modal, including previously exported keys. “I already have a copy” only dismisses it; it does not record an export acknowledgement. Unattended-backup reminders remain limited to once per session per fingerprint and stop after the account exports that key.
- The modal exports through the existing password- and CSRF-protected key endpoint. Incorrect passwords remain actionable inside the modal; closing it clears the password.
- Acknowledgement is recorded only after the download stream completes successfully. Cancelled or failed downloads do not acknowledge.
- The existing current-password authorization and local-request checks remain in force. The recommendation links to the existing Recovery Key area rather than creating a second download path.

### Backup Keyring

- Extend the key store with list, promote, archive, delete, and export-by-fingerprint operations alongside the existing current-key generation, lookup, and additive import.
- Import remains additive and immediate. Importing the same key again is idempotent.
- Promotion writes the imported key as the Primary Recovery Key and archives the previous primary under its fingerprint, so old packages remain decryptable. Archiving is not deletion.
- Deleting an imported key is allowed only after explicit password-confirmed warning that its packages become unrecoverable. Deleting the Primary Recovery Key is blocked while it is primary.
- The key manager lists fingerprint, kind (primary or imported), and dates. Any listed key can be exported after current-password verification.
- Key import, export, promotion, archive, and deletion are activity-logged with fingerprints and filenames only; key material never appears in logs, URLs, or audit metadata.

### Database-structure compatibility

- The package manifest gains the set of applied migration keys and the encryption key fingerprint. Compatibility is decided from migration history plus the schema fingerprint, not from the fingerprint alone.
- Capture excludes `schema_migrations` rows while still recording the applied keys in the manifest. Restore preserves the destination's migration ledger instead of overwriting it from the package.
- Add a registered upgrade-adapter registry. Each known older migration range maps to a transformation that produces rows for the current destination structure. Adapters run on package rows before destination-table replacement.
- Review classifies packages as current, upgradable, or unsupported. Upgradable packages continue through the existing identity reconciliation, safety-copy, replacement, and reopening flow. Unsupported packages stop before destructive work and name the missing migrations or the newer format.
- Unknown newer structures are never guessed at. They are rejected with an actionable message.
- Replace the ad-hoc backup-schema bootstrap with the migration runner so that a partially upgraded installation repairs its backup history table through the normal path. Existing installations receive an idempotent migration adding the new history columns.
- Preserve the existing rule that destination security state remains authoritative. An older package may change Store records but may not weaken destination accounts, roles, or session policy.

### Settings and permissions

- Schedule, destination, and retention behavior are platform-governance settings. Only the Super Administrator changes them.
- Administrators retain manual backup creation, restore review, recovery-key actions, and read-only visibility of schedule status and backup history.
- Setting changes are activity-logged.

## Testing Decisions

Good tests assert observable behavior through stable seams: package creation, key files and HTTP responses, restore outcomes, CLI exit behavior, and rendered page affordances. Tests must not reach into private methods or assert exact internal file layouts beyond the documented contract.

Preferred seams, reusing existing ones:

1. **Backup creation seam** — `DatabaseBackupService` and the CLI backup entry point. Test package contents, history rows, retention, skip behavior, and failure preservation without a browser.
2. **Scheduled-run seam** — the new scheduled service with an injected clock and temporary destination. Test due, not-due, catch-up-once, busy-skip, and failed-run behavior.
3. **Key-management seam** — the key store plus the recovery-key HTTP endpoint. Test additive import, promotion with archive, blocked primary deletion, explicit delete, export by fingerprint, password requirement, and acknowledgement-on-complete-download.
4. **Restore-compatibility seam** — `SqlBackupFormat` and `DatabaseRestoreService` with generated packages from known older structures. Test current, upgradable, unknown-older, and newer rejection; verify the destination migration ledger survives.
5. **Browser seam** — authenticated Super Administrator and Administrator pages. Test schedule settings visibility, key manager actions, post-download modal ordering, retry after failed downloads, repeated-key prompts, responsive layout, and per-account acknowledgement.

Prior art to follow:

- Package-format and cross-install key tests in the existing store-package test suite.
- Backup workflow and safety-copy integration tests using the MySQL test database.
- Restore browser tests that create a package, download it, and re-upload it.
- Recovery-tools browser tests that render backup tabs and key forms.
- Route-contract tests for authorization and local-request behavior.

Verification should run the existing style, view, and smoke checks plus the backup-specific unit, integration, and browser suites.

## Out of Scope

- Cloud storage, offsite replication, or third-party backup services.
- Hosted-cron deployment examples; this iteration covers the Store PC Windows scheduler plus in-app catch-up.
- Configurable retention counts other than one; keeping the newest Scheduled Backup is fixed for now.
- Backing up application code, configuration, or the private recovery store itself.
- Automatic restore or automatic Store Reopening.
- Key escrow, password-based key derivation, or recovery without holding a key.
- Legacy `RMSTORE1` passphrase packages.
- Redesigning the backup screen beyond what the new controls require.
- Multi-store or multi-tenant backup scheduling.

## Further Notes

- The reported incompatible-structure failure was assumed to occur during restore review for a package from an older backup on the same installation. If it also occurs during backup creation, the destination-schema repair work in this spec must be expanded first.
- `docs/BACKUP_RECOVERY.md` and `docs/STORE_PACKAGE_FORMAT.md` still describe legacy passphrase packages that the current code rejects. Documentation should be corrected alongside implementation.
- The dashboard description of a backup as a plain SQL Store copy is stale and should be corrected.
- Windows permissions are best effort: the code requests private modes, but the host filesystem may not reflect them. The Backup Destination must still be outside the web root.
- The application timezone is Asia/Manila in the current environment; schedule evaluation uses the application timezone, not the operating system's.
- The current private recovery store already sits outside the web root; the Backup Destination is a separate, user-chosen retention location for Scheduled Backups.
- Related decisions: ADR-0021 (portable schemas without replaying source DDL), ADR-0023 (password-authorized restoration), ADR-0024 (administrator self-authorized restore), and ADR-0028 (scheduled weekly backups and managed key custody).
