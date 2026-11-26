# Cashier concurrent login

Design confirmed and implementation authorized on 2026-11-26 Philippine time. Implemented locally; see the [staff login policy](../specs/session-policy.md) for the final behavior.

## Confirmed decisions so far

- Restrict each Staff Account assigned the cashier Workspace to one active login, including Multi-role Accounts while using another assigned Workspace.
- A new login on Device B waits for explicit approval from the existing login on Device A. Approval transfers access to Device B and ends Device A's access.
- Retain automatic logout after one hour without deliberate activity. Once the previous login expires, a fresh authenticated login does not require its approval. The user emphasized keeping the system simple rather than expanding the login flow.
- Keep checkout stock protection across different cashier accounts: when two sales compete for the last available stock, the first successful completed sale gets it; the other sale must not oversell. A cart alone does not reserve stock.

## Existing policy inspected

- The staff login policy specifies expiry after one hour without deliberate activity and an absolute limit of twelve hours since login. Background polling does not extend inactivity.
- Expiry ends authenticated access but leaves the Cashier Shift open and retains its register ownership.
- The device-transfer flow checks recorded activity on the server, so an offline or broken old device need not respond once its login expires.
- Super Administrator login replacement is a separate discussion with a different flow; it is not approval for cashier login replacement.

## Implementation boundaries

- Retain confirmation on Device A. If it breaks, wait for the one-hour inactivity expiry; no new recovery flow.
- A separate browser or private window is a separate login. Tabs sharing the same login remain together.
- Only one pending request is accepted, valid for five minutes. Rejection leaves Device A signed in. Expired or rejected requests require a fresh login attempt.
- Approval ends Device A's server access and allows Device B to complete authentication. Active online pages normally discover requests within thirty seconds. Offline or suspended pages must reconnect.
- Existing Cashier Shift and register ownership remain with the same account. Unsaved browser work is not transferred; the cashier should finish or hold work before approving.
- Existing stock guards remain necessary across different cashier accounts. A request already accepted before transfer may finish.
- Accounts without the cashier Workspace retain their existing policy, including the separate Super Administrator replacement rule.
- Existing cashier logins issued before this change require signing in again, establishing tracked ownership.

References: [Staff login policy](../specs/session-policy.md), [Super Administrator concurrent login](super-administrator-concurrent-login.md), and [domain glossary](../../GLOSSARY.md).
