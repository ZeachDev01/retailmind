---
status: accepted
---

# Current-password authorization for encrypted backup restoration

On 2026-10-31, the user confirmed that downloaded Database Backups must remain encrypted and unreadable or unimportable as ordinary SQL, including required uploaded images. Restoration should require the signed-in operator's current login password once, remain usable after login-password changes or resets, and automate package validation and verified destination safety-copy creation while retaining the final snapshot/replacement warning.

The user then accepted a generated server-held encryption key with a separately downloaded recovery-key file, retained restore permissions and approvals, and instructed direct implementation during development. This supersedes ADR-0017's requirement for a user-entered independent passphrase for new backups and the corresponding credential exclusions in the encrypted-backup-simplification specification. The operator's current login password authorizes restoration; it is never the backup encryption secret, so login-password changes do not strand old backups.

## Scope and consequences

- Generate a stable random key in private recovery storage outside the database and public/application directories. A Super Administrator downloads its recovery copy or imports another installation's key after current-password verification. Import preserves existing destination keys. Keep key files separate from packages; possession of both permits decryption. Losing all copies of a required key makes its packages unrecoverable.
- Reuse AES-256-GCM, package validation and the existing restore engine. Automatically encrypted packages identify their key in an authenticated header. New routine backup creation and safety capture do not ask for a passphrase. Existing passphrase-encrypted packages remain readable with their original passphrase through optional older-backup fields; no bulk conversion is required.
- Retain existing role, local-access, session, CSRF, exact-package approval, identity reconciliation, verified safety-copy, and recovery boundaries. The Super Administrator workflow carries the selected package and password through review and approval in the active page, then presents snapshot information and final replacement confirmation. Reload clears the selection and credentials. No browser/server session stores the plaintext password.
- Interrupted restoration uses the same private keys without additional decryption prompts for new packages. Recovery-console authentication, verification, and explicit Store Reopening remain required. No live Store restore or deployment is authorized by this design discussion.

This changes key custody deliberately: backup-file theft alone remains insufficient, but compromise of the server's private key storage can also expose its backups. The owner accepts a one-time recovery-key custody step in exchange for removing routine passphrase entry.
