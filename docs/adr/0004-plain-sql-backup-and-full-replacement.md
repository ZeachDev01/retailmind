# Use readable SQL backups and full database replacement

Supersedes ADR-0003. Both administrator roles may download complete, unencrypted `.sql` Database Backups; only the Super Administrator may restore, after verifying their current password. We choose simpler recovery without encryption keys or audit reconciliation, explicitly accepting that possession of a backup exposes password hashes and restricted records, and that restoration rolls back accounts, settings, and audit history.

## Consequences

- Accept only the current RetailMind SQL format with a compatible schema. Format checks are not proof of provenance: plaintext files must come from trusted storage. Old `.rmbak` files are not accepted; retain old files and keys until replacement SQL backups are verified.
- Block Store activity during replacement. Create and retain the latest private safety backup first; abort before replacement if it cannot be saved. An incomplete restore keeps the Store blocked while offline recovery remains available.
- Keep an append-only restore log and session invalidation state outside the database. After success, everyone signs in again with credentials from the restored snapshot.
- Do not reconcile newer database audit records. The external restore log records the initiating identity, time, and outcome without relying on possibly replaced database tables.
- Keep SQL artifacts and recovery state outside web-accessible storage. Temporary downloads remain requester-bound; the latest safety backup is reserved for Super Administrator recovery.
