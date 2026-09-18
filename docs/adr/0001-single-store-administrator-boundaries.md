# Operate RetailMind as one Store with separate administrator authority

RetailMind will expose one Store and separate Super Administrator technical governance from Administrator Store operations. Multi-branch controls will be removed from the domain and interface while one internal singleton branch may remain temporarily for database compatibility; demand forecasting remains a Product + Day model for the Store. We chose this direction because multi-branch support would require substantial operational-data and ML redesign that the actual deployment does not need, while the current shared dashboard and broad role bypass blur security and operational responsibility.

## Consequences

- The Super Administrator and Administrator receive separate dashboards, navigation, query models, and backend authorization.
- Platform security, recovery, system health, ML operation, privileged users, Platform Settings, Emergency Access, and the Recovery Account belong to the Super Administrator.
- Store performance, staff, approvals, Fiscal Periods, Store Settings, and operational exceptions belong to the Administrator; routine inventory execution remains with the Inventory Manager.
- Branch controls disappear from user-facing workflows, but destructive removal of compatibility tables and columns is deferred.
- Dashboard aggregation does not alter the Store's demand-forecasting inputs or prediction grain.
