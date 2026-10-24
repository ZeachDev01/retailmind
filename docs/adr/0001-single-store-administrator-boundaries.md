# Operate RetailMind as one Store with separate administrator authority

RetailMind will expose one Store and separate Super Administrator technical governance from Administrator Store operations. Multi-branch controls will be removed from the domain and interface while one internal singleton branch may remain temporarily for database compatibility; demand forecasting remains a Product + Day model for the Store. We chose this direction because multi-branch support would require substantial operational-data and ML redesign that the actual deployment does not need, while the current shared dashboard and broad role bypass blur security and operational responsibility.

## Consequences

- The Super Administrator and Administrator receive separate dashboards, navigation, query models, and backend authorization.
- Platform security, recovery, system health, ML operation, Super Administrator identity protection, Platform Settings, and the Recovery Account belong to the Super Administrator.
- Store performance, staff, approvals, Fiscal Periods, Store Settings, and operational exceptions belong to the Administrator; routine inventory execution remains with the Inventory Manager.
- Branch controls disappear from user-facing workflows, but destructive removal of compatibility tables and columns is deferred.
- Dashboard aggregation does not alter the Store's demand-forecasting inputs or prediction grain.

## Ordinary Administrator lifecycle (issue #300)

Administrators in the Administrator Workspace may create and promote ordinary Administrators and manage their profiles, Temporary Passwords, fixed operational roles, status, and sessions through Users. This replaces the original privileged-user boundary for ordinary Administrators: routine Store account continuity should not require platform-governance intervention.

- Administrator, Cashier, and Inventory Manager are the only assignable operational templates; arbitrary privileges and Super Administrator assignment remain unavailable.
- Complete primary and additional assignments protect Super Administrator identities from other accounts. Permitted Super Administrator self-profile maintenance remains available; Recovery Account management stays outside Users.
- Manual role/status changes retain at least one active ordinary account with assigned Administrator access, including additional access, with no Super Administrator bypass. Self-disable and self-demotion remain forbidden.
- ADR-0009 continues to govern the active Workspace: assigned roles never combine authority, and Cashier or Inventory Manager Workspaces cannot mutate Users.
- Profile-image authority, Mandatory Password Change, notifications, and session invalidation remain part of the existing lifecycle. Administrator lifecycle actions remain Protected Audit Records; application users cannot edit or delete them.
