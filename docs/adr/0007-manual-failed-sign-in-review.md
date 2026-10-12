# Clear failed sign-in warnings through recorded review

Only the Super Administrator may clear reviewed failed sign-ins, after recording a result and note. Clearing excludes the displayed attempts from the platform warning's rolling 24-hour count, while new failures remain eligible to trigger a fresh warning. Intent is assessed by checking the evidence and confirming with account owners; it is never automatically inferred from an IP address or failure count.

Use immutable Protected Audit Records as the review checkpoint and preserve a snapshot of the reviewed attempts. Deleting attempts or silencing the entire warning would lose evidence or hide ongoing activity. Snapshots retain that evidence even when the existing successful-login cleanup later removes matching attempt rows.
