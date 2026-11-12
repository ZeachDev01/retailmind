# Administrator welcome email

Confirmed on 2026-11-12 (Asia/Manila): when the Super Administrator creates an ordinary Administrator, require a valid email address and send a welcome email through the existing Brevo delivery path after the account is saved. Include the username, the issued Temporary Password, and the requirement to replace it at first sign-in. The existing Mandatory Password Change remains enforced.

If delivery fails, preserve the account and show the Super Administrator a warning. Do not report account creation as failed or encourage duplicate creation. Provider acceptance means submitted for delivery, not confirmed inbox receipt. No automatic resend or stored plaintext password is introduced. Ordinary Administrator-created staff accounts retain their existing behavior.

The email extends the existing password-reset email's navy header, white body, system fonts, inline styles and Outlook-compatible tables. Credentials remain readable on narrow screens; instructions refer to the Store link supplied by the Super Administrator instead of inventing an externally reachable login URL.

Temporary credentials remain visible in the recipient's inbox until deleted. Password hashes, audit records and delivery logs must not gain plaintext passwords.
