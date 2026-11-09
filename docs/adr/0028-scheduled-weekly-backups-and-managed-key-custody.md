# Scheduled weekly backups and managed recovery-key custody

RetailMind will create one verified encrypted Scheduled Backup every week without an operator, keep only the newest automatic package, write it to a configurable Backup Destination outside the web root, and catch up once after missed weeks. This accepts bounded backup history in exchange for predictable disk use and removes reliance on someone remembering a manual copy. The run is driven by the Windows scheduler with an in-app catch-up fallback rather than hosted cron because the Store PC is the supported deployment.

Recovery Key custody becomes a Backup Keyring. Importing another installation's key stays additive by default, promoting an imported key archives rather than deletes the old Primary Recovery Key so old packages remain recoverable, and deleting a key requires a password-confirmed warning. Administrators are prompted to save the specific key that encrypted the newest backup, and acknowledgement is recorded only after a successful download. This deliberately favors recoverability over a minimal single-key model.

Restore accepts packages from known older database structures through registered upgrade adapters while preserving the destination migration ledger. Unknown or newer structures are rejected with the missing migrations named. This keeps the existing portable-schema rule of never replaying a source package's DDL and avoids silently guessing at future structures.

Consequences: the destination accumulates retained automatic packages until the next successful run replaces them; the keyring can hold multiple keys indefinitely; and compatibility depends on maintaining an adapter for each older structure that must remain restorable.
