# Review and clear failed sign-in warnings

The Super Administrator opens **Repeated failed sign-ins** from the dashboard, or opens **Protected Audit Records → Review failed sign-ins**. The default warning threshold is five unreviewed failed attempts in the last 24 hours; Platform Settings may change it.

1. Check the listed account, time, and IP address. Confirm with the account owner whether they made these attempts. Shared IP addresses and repeated failures are clues, not proof of intent.
2. Search **Login failure** in Record history and open **View details** to see the attempted identifier, failure reason, and IP address.
3. If unauthorized access is suspected, use User Manager to reset the affected account's password, revoke its sessions, or disable it as appropriate. Clearing the warning does not block sign-ins.
4. Choose **Confirmed user mistake**, **Suspected unauthorized attempt**, or **Unable to confirm**. Enter a short note describing the evidence and any security action taken.
5. Check **I reviewed the listed attempts**, then select **Save review and clear listed attempts**. Refresh the dashboard to see its updated warning state.

Each batch contains up to 100 oldest unreviewed failures. Only the displayed batch is cleared; attempts arriving after it remain unreviewed. Remaining or new failures trigger a warning when they reach the configured threshold. Clearing applies to the platform warning across Super Administrator sessions, and marks the reviewer's existing warning notifications read.

Manual clearing preserves the login-attempt rows and adds a Protected Audit Record with the reviewer, time, result, note, and a snapshot of the reviewed attempts. This snapshot survives the existing successful-login cleanup of matching login-attempt rows. Other warnings and ordinary account-security controls retain their behavior.
