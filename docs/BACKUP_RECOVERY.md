# Database Backup and recovery runbook

Covers the manual, encrypted Database Backup shared by the Administrator and the
Super Administrator, and the Super Administrator-only Database Restore
(ticket #69, ADR-0003).

## What a Database Backup is

An encrypted, consistent, point-in-time copy of the Store database in a
versioned envelope (`RMBAK1`, AES-256-GCM). Opening the file reveals nothing;
only the recovery key can. Either the Administrator or the Super Administrator
may create one, and the browser downloads it to their own device.

The file on the application server is temporary. It exists only long enough to
be delivered, then it is removed. **The server copy is not an archive.**

## What the owner must do

1. Sign in, open **Database Backup** (Administrator) or **Backup & Restore**
   (Super Administrator), and press **Create backup**.
2. Wait for the confirmation, then press **Download**.
3. Confirm in the browser's download list that the file arrived.
4. Move the file to storage you control — removable media or your own cloud
   folder. Do not leave the only copy on the application server.
5. Keep the file. A completed history entry means the server created the backup;
   it does **not** prove the file reached a safe place.

Saving changes pauses briefly while the snapshot is captured. Everyone signed in
sees a short notice, can keep browsing, keeps unsaved form input, and can save
again once the notice clears.

## Recovery key handling

`BACKUP_ENCRYPTION_KEY` in the server `.env` holds 32 random bytes, written as
64 hex characters or standard base64. Generate one with:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Rules:

- The key is server configuration only. It is never stored in the exported
  database, committed to source control, written to logs, returned in a
  response, or bundled with a backup file.
- The key is independent of every login password, so resetting or rotating a
  staff password never invalidates an existing backup.
- Without a usable key, encrypted backup creation **fails closed**. There is no
  plaintext fallback.
- The developer team acting as Super Administrator keeps **two** copies: one in
  the server `.env`, one in the team password manager, ideally in a second
  password-manager vault controlled by a different person.

### After server loss

Both the encrypted file and the matching key are required. A file alone cannot
be decrypted. This means an old backup stays usable only while its matching key
is retained, so do not retire a key while backups made with it are still needed.

## Recovery procedure (Super Administrator only)

1. Obtain the encrypted `.rmbak` file from the owner.
2. Confirm `BACKUP_ENCRYPTION_KEY` on the server matches the key that created
   the file. A mismatch is rejected before anything changes.
3. Rebuild the application on a fresh server and load `src/backend/sql/schema.sql`.
4. Apply migrations:

   ```bash
   php src/backend/scripts/migrate.php
   ```

5. Sign in as the Super Administrator, open **Backup & Restore**, confirm the
   destructive-restore checkbox, and upload the `.rmbak` file.

Restore order is deliberate: the file is verified and decrypted first, so a wrong
key, a truncated file, or tampered content is rejected while the database is
still untouched. A safety backup is created before the destructive statements run.

### Supported sizes

The restore page reports the largest file this server can accept, derived from
PHP's `upload_max_filesize` and `post_max_size`. A larger backup is refused with
an explicit message rather than failing silently. Raise both PHP settings, and
`post_max_size` above `upload_max_filesize`, to accept bigger files.

## If a restore stops part way

MySQL DDL is **not** transactionally rollback-safe. RetailMind does not claim
that a restore is all-or-nothing. A stopped restore is reported as incomplete
with the number of statements that actually ran.

To finish:

1. Read the incomplete-restore entry in the Super Administrator backup history
   for the failing statement.
2. Restore into a scratch database and compare, or re-run the same verified
   backup against a freshly rebuilt server.
3. Current Protected Audit Records are preserved in `restore_preserved_activity`
   before the destructive statements run and reconciled back afterwards, so
   evidence that postdates the snapshot is re-applied rather than lost. Those
   records are the authoritative audit trail for what the restore did.

## Bounded cleanup

Temporary artifacts are deleted after delivery or failure. Artifacts left by an
interrupted request are removed by the bounded sweep that older than an hour, so
a file that is still being streamed is never deleted. This feature deliberately
keeps **no** permanent server-side archive and adds **no** schedule.
