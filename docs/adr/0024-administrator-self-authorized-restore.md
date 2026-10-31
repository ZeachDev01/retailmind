---
status: accepted
---

# Administrator self-authorized backup restoration

On 2026-10-31, the user explicitly chose a simpler restore and Store Migration workflow: an ordinary Administrator may restore an encrypted Database Backup using their own current login password and final replacement confirmation, without separate Super Administrator approval. This supersedes the separate technical approval boundary in ADR-0017 and ADR-0023; it does not grant platform governance or permission to change destination privileged accounts.

Both administrator roles use the existing backup review and account-choice workflow. Final confirmation records package-bound reconciliation evidence automatically and runs the existing integrity, identity, destination-state, and authority checks. Account conflicts still require explicit resolutions; matching numeric IDs or login details never silently identify people. Either administrator role may download its installation's recovery key or import a source key after current-password verification, so transfers do not depend on Super Administrator assistance. Keys remain separate from encrypted packages.

Verified destination safety copies, preservation of destination privileged access and security evidence, local-access and CSRF restrictions, paused Store writes, recovery authentication, verification, and explicit Store Reopening remain required. This deliberately gives an ordinary Administrator authority to replace newer Store transactions with an older snapshot. No live restore or deployment is authorized by implementing this workflow.
