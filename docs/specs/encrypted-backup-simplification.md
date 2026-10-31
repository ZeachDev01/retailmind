# Simplify encrypted Database Backup and Database Restore

Update 2026-10-31: [ADR-0023](../adr/0023-password-authorized-backup-restoration.md) supersedes this document's independent user-entered backup/safety passphrases and prohibition on server-held backup keys for new packages. Routine packages and safety copies use private generated keys; the operator supplies their current login password. Older packages retain optional original-passphrase support. Existing permissions, approvals, validation, and recovery safeguards remain required.

## Problem Statement

The Store owner must navigate separate interfaces for backup validation, Identity Reconciliation, Database Restore, manual safety capture, and recovery review. Review and restoration repeatedly request the same package and credentials. Optional safety tools appear to be prerequisites although restoration already captures and verifies a fresh destination safety copy automatically. Some guidance still says ordinary replacement is disabled although the supported route enables it.

“New backup passphrase” implies each Database Backup needs a different passphrase, which existing policy does not require. These difficulties obscure recovery and the distinct purposes of login credentials, the incoming backup passphrase, and the destination safety-copy passphrase.

## Solution

Provide one guided flow: select an encrypted backup, enter required credentials, review, obtain Super Administrator approval, restore, verify, and explicitly reopen. Present same-installation Database Restore first and Store Migration separately, with comparable attention to ease of use and the same restoration engine.

Upload once during an uninterrupted attempt. Reduce repeated credential entry wherever existing security checks permit. Keep explicit Identity Reconciliation and exact-package Reconciliation Approval for both choices. Automatically capture and verify the destination safety copy before replacement; place optional standalone validation and manual safety capture under Advanced.

Label backup creation's field “Backup passphrase.” Allow reuse of a strong passphrase under existing validation and retain off-server recovery-copy guidance. Existing encryption, authorization, and recovery safeguards remain in force.

## User Stories

1. As a Store owner, I want an encrypted Database Backup of Store records and required uploaded images, so that I can recover after data loss.
2. As an Administrator, I want to create and download backups within existing permissions, so that routine backup does not require technical-owner intervention.
3. As a Super Administrator, I want the same clear backup workflow, so that both administrator experiences follow the same policy.
4. As a Store owner, I want the label “Backup passphrase,” so that I understand a different passphrase is not mandatory for every package.
5. As a Store owner, I want to reuse a strong backup passphrase, so that backup custody is manageable without weakening validation.
6. As a Store owner, I want off-server passphrase recovery guidance, so that server loss does not also remove my backup access.
7. As a restoring administrator, I want same-installation Database Restore presented first, so that ordinary recovery is easy to find.
8. As a migrating administrator, I want Store Migration presented separately, so that I understand I am changing the active installation.
9. As a restoring administrator, I want one package upload during an uninterrupted attempt, so that review and replacement use the same backup.
10. As a restoring administrator, I want each credential's purpose explained, so that destination authorization and the two decryption credentials are understandable.
11. As a restoring administrator, I want unnecessary repeated credential entry removed, so that recovery takes fewer repeated actions.
12. As an Administrator, I want required Super Administrator review shown within the flow, so that I know when another person's approval is needed.
13. As a Super Administrator, I want explicit review of people, conflicts, Preservation Closure, and resulting access, so that identity decisions remain deliberate.
14. As a Super Administrator, I want approval bound to the exact package and destination state, so that a substituted package or stale decision cannot authorize replacement.
15. As a restoring administrator, I want the snapshot date and replacement warning before confirmation, so that I understand newer Store records will disappear from the active database.
16. As a restoring administrator, I want automatic creation and verification of an encrypted destination safety copy, so that replacement has a usable fallback.
17. As a restoring administrator, I want to provide the safety-copy passphrase at the relevant step, so that automatic capture does not imply server custody of my secret.
18. As a Store owner, I want safety-copy failure to stop replacement, so that destination data remains unchanged without verified fallback protection.
19. As an advanced operator, I want standalone validation and manual safety capture under Advanced, so that these tools remain available without cluttering ordinary recovery.
20. As a restoring administrator, I want understandable progress and failures with the next safe action, so that I know whether to retry preparation or enter recovery.
21. As a restoring administrator, I want clear handling of cancellation, expiry, and reload, so that I can restart safely without relying on retained secrets.
22. As an authorized approving Super Administrator, I want controlled access to the prepared operation, so that approval handoff avoids another upload.
23. As a Store owner, I want unrelated operators denied package and operation access, so that knowing an operation reference grants no authority.
24. As a Store owner, I want invalid credentials and damaged or incompatible packages rejected safely, so that failed preparation cannot replace Store data.
25. As a Store owner, I want destination privileged access and protected evidence preserved, so that restoration retains the installation's security authority.
26. As a Store owner, I want correct source and destination identity attribution, so that restored history does not grant unintended access.
27. As a recovering administrator, I want interrupted replacement to keep the Store closed, so that incomplete records or uploads cannot be used for transactions.
28. As a recovering administrator, I want the Recovery Console available when database tables are unavailable, so that replacement failure does not remove my recovery route.
29. As a recovering administrator, I want required recovery material retained after destructive work begins, so that cleanup cannot delete the only usable fallback.
30. As a restoring administrator, I want verification followed by explicit authorized reopening, so that Store operations resume only after checks finish.
31. As a mobile administrator, I want readable steps, accessible controls, and clear errors on narrow screens, so that I can complete the same authorized workflow.
32. As a migrating Store owner, I want one active installation after verified migration, so that the workflow does not encourage independently operating Store copies.

## Implementation Decisions

- Reuse the shared restore form and existing DatabaseBackupService, DatabaseRestoreService, IdentityReconciliationService, SafetyCopyService, BackupCoordinator, RecoveryAuthService, and RecoveryStore conventions. Update both administrator backup screens and affected recovery instructions together. Do not introduce another restoration engine for migration.
- Keep encrypted packages, existing passphrase validation, role restrictions, and independent login, incoming-backup, and safety-copy credentials. Changing wording does not change credential policy.
- Connect validation, identity review and approval, automatic safety capture, replacement, verification, and reopening. Remove obsolete “replacement disabled” guidance where the supported route enables replacement. Move optional tools under Advanced.
- Retain the uploaded encrypted package in private operation storage. Bind access to the initiating operator and explicitly authorized approving Super Administrator. Validate role, CSRF, operation ownership or explicit approval authority, and package integrity on every action. A package reference grants no access by itself.
- Expire abandoned preparation with the authenticated session and clean it on cancellation/completion. After destructive work begins, retain operation-bound material needed by durable recovery; cleanup must not delete the only required recovery material.
- Keep secrets transiently in the active page/request when needed. Never place login passwords, passphrases, or decrypted packages in persistent browser storage, server sessions, hidden HTML fields, logs, or URLs. Clear transient values on completion/cancellation. Re-entry after expiry, reload, or operator takeover is acceptable.
- Keep explicit identity decisions and exact-package Reconciliation Approval for both restoration choices. Ordinary Administrators cannot approve or alter reconciliation. Recheck destination state and required authority immediately before destruction; matching identifiers never establish identity.
- Preserve Preservation Closure, destination Super Administrator and Recovery Account authority, and historical attribution. Maintain portable-schema validation under ADR-0021: validate supported equivalences, use destination schema definitions and validated rows, and never execute incoming producer schema definitions.
- Show the snapshot date and loss of newer operational records before confirmation. Capture and verify a complete encrypted destination safety copy before changing destination data; capture failure aborts replacement without changing destination data.
- Reuse Durable Recovery Stages, Store write blocking, operation-bound recovery authentication, failure handling, and the Recovery Console when database tables are unavailable. Verification and explicit authorized reopening remain required.
- Reduce repeated credential entry only where existing checks permit. Do not promise one authentication across multiple people or secret retention after reload. Approval, destructive replacement, and reopening retain their required authorization checks.
- Preserve existing Store Migration requirements for source pause, one active installation, destination verification, and forecasting readiness. This experience change does not change cutover policy.
- Preserve existing uncommitted backup work as the implementation baseline and unrelated working-tree changes. No new dependency, encryption format, domain term, or ADR is required.

## Testing Decisions

- User-confirmed primary seam: the guided administrator workflow through browser interactions and its public routes, on disposable Store installations using the existing engine. Test observable permissions, downloads, prompts, state transitions, recovery availability, and reopened Store behavior rather than private helpers or incidental markup.
- Extend the existing encrypted restore and backup package browser tests for both administrator roles, both restoration choices, one upload across review and approval handoff, passphrase wording/reuse, Advanced tools, warnings, mobile layout, accessible controls, and explicit reopening.
- Cover wrong credentials, tampered/incompatible packages, changed approvals or destination state, missing CSRF, denied roles, unauthorized references, expiry, cancellation, reload, and takeover. Failed preparation must not start destructive replacement. Check that secrets do not enter persistent storage, hidden HTML fields, logs, or URLs.
- Reuse backup workflow route contracts and Identity Reconciliation database tests for authorization, exact-package approval, destination watermarks, explicit identity decisions, Preservation Closure, and Historical Actor attribution. Add focused cases only where the workflow seam cannot reliably exercise the boundary.
- Reuse existing real-database backup/restore, interrupted-recovery, and reopen integration tests for safety verification, failure before replacement, interrupted database/uploads replacement, recovery with unavailable tables, safe cleanup, verification, and reopening. Use existing failure injection rather than creating a second restoration harness.
- Retain format and portable-package tests for authenticated encryption and schema compatibility. Reuse migration verification in both deployment directions; extend only where the simplified flow changes behavior.
- Destructive tests use disposable installations only. The user confirmed the primary seam and supporting-suite reuse on 2026-10-30.

## Out of Scope

- Live database encryption, new encryption formats, dependencies, or cloud backup services.
- Server-saved passphrases, login-password-only decryption, or merging incoming and safety-copy credentials.
- Automatic identity matching, approval bypass, relaxed destination-state checks, or new recovery authority.
- Another restoration engine, Store synchronization, merging newer records, or simultaneous active installations.
- Infrastructure purchases, live deployment, or destructive live migration drills.
- Recovery without the applicable passphrase, retained secrets after reload, or shared authentication across different people.

## Further Notes

Experience direction agreed on 2026-10-30. This spec synthesizes that plan; it does not claim the simplification is implemented. Existing security policy remains in force. Existing uncommitted backup implementation work must be preserved.

This is an experience follow-up to [issue #282](https://github.com/ZeachDev01/retailmind/issues/282), preserving its security and migration requirements. ADR-0017 governs encrypted portable backups and Administrator restoration; ADR-0021 governs portable schema validation. Vocabulary follows the project context and glossary.

Losing all copies of a passphrase makes its packages unrecoverable. Reusing one passphrase means its compromise affects every package encrypted with it. Snapshot restoration replaces newer operational Store records.

Planning inspection did not rerun tests or independently audit cryptography. Implementation must pass applicable checks before completion. The user confirmed the testing approach and authorized publication on 2026-10-30. Published as [issue #353](https://github.com/ZeachDev01/retailmind/issues/353) with `ready-for-agent`.
