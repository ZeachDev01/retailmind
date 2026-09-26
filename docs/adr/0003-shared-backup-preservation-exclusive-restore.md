---
status: superseded by ADR-0004
---

# Share Database Backup creation while Database Restore stays exclusive

The Administrator and the Super Administrator share one manual Database Backup workflow: either role may create a consistent, encrypted, point-in-time Database Backup and download it to their own device, and the Super Administrator alone holds the recovery key and performs Database Restore. This deliberately revisits ADR-0001's recovery boundary, which previously left backup inside platform governance. We chose it because the Store owner is accountable for preserving their own records and cannot currently do so without the developer team, while a readable full-database copy and destructive restoration would undo the role separation and the Protected Audit Record visibility rules that same ADR established. Authorization is enforced on every create, download, history, and restore entry point, including the legacy reachable route, so hiding the restore control is never the only protection. See ADR-0002 for why owner-facing responses never carry technical or key detail.

## Consequences

- The role capability policy gains one narrowly scoped capability for backup creation, download, and limited history that both administrator roles hold. It grants no platform governance, no restoration authority, and no recovery key visibility; Database Restore stays behind Super Administrator platform governance.
- Administrator-visible backup history carries operational metadata and safe messages only. Security, recovery, and Recovery Account Protected Audit Records remain Super Administrator-only, and a completed history entry never claims the file was retained on the owner's device.
- One shared exporter and one shared coordination path serve both roles. There is no per-role exporter, no job queue, and no parallel export worker; a single active capture is coordinated in the database rather than in application state.
- Backups are written only in the versioned authenticated-encryption envelope. There is no owner-facing plaintext download, and missing or unusable key configuration fails closed instead of degrading.
- The recovery key is server configuration, independent of every login password, stored outside the exported database, never committed, and never bundled with a backup file. The Super Administrator retains a separate copy; possession of a backup file alone does not permit decryption.
- Server-side backup artifacts are temporary and requester-bound, are removed after delivery, and are not a permanent archive. Recovery evidence that postdates a restored snapshot is preserved and reconciled, so restoring older data cannot silently erase Protected Audit Records.
