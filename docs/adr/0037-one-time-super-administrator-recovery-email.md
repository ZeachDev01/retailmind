# One-time Super Administrator recovery email

The seeded primary Super Administrator has no email and therefore cannot use the existing email password recovery flow. Allow only that account's signed-in owner to register its own missing email, after confirming the current password and verifying a code sent to the proposed mailbox. Save and permanently lock the address only after verification, so a typo or failed delivery does not consume the one-time setup.

This deliberately retains immutable account email rather than adding routine privileged email replacement. Setup remains optional so an email-service outage does not block platform work. If the locked mailbox is lost and the account cannot be accessed, the existing offline recovery procedure remains the fallback; ordinary accounts and the sealed Recovery Account gain no email-editing path. See the [discussion and verification](../discussions/super-administrator-recovery-email.md).
