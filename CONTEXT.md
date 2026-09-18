# RetailMind

RetailMind supports one Store's inventory, purchasing, sales, and demand-forecasting operations.

## Language

**Store**:
The single retail location operated through RetailMind. RetailMind does not divide operations or records among multiple branches.
_Avoid_: Branch, location assignment, tenant

**Super Administrator**:
The single technical owner responsible for platform security, Platform Settings, recovery, access policy, system health, and demand-forecasting model operation. It owns privileged-account lifecycle and may create, maintain, secure, or revoke any user account. One primary account is used routinely; a sealed Recovery Account exists only for lockout or disaster recovery. It may inspect Store operations for oversight but does not perform routine operational work.
_Avoid_: Superadmin, unrestricted operator, Store operator

**Recovery Account**:
A sealed privileged identity used only when the primary Super Administrator cannot recover access. It is activated through an offline recovery procedure requiring separately stored credentials, never through ordinary login or email reset. Its activation and every action performed through it are recorded as Protected Audit Records.
_Avoid_: Backup administrator, shared admin account, routine login

**Administrator**:
The operational owner responsible for Store performance, staff, approvals, compliance, Fiscal Periods, Store Settings, and operational exceptions. It has delegated authority to create, edit, disable, reset passwords for, and revoke sessions from Cashiers and Inventory Managers. It assigns only fixed operational role templates defined by the Super Administrator; it cannot create privileged administrators or arbitrary privilege combinations. Routine inventory execution belongs to the Inventory Manager; platform security, recovery, and technical model controls belong to the Super Administrator.
_Avoid_: System administrator, global administrator, inventory manager

**Disabled Account**:
A retained user identity that cannot authenticate and whose active sessions have been revoked. Its historical sales, approvals, and Protected Audit Records remain attributed to it.
_Avoid_: Deleted user, removed history

**Inventory Manager**:
The Store operator responsible for routine inventory execution, including stock monitoring, replenishment, receiving, and inventory control. It does not own Store-wide staff, compliance, or access oversight.
_Avoid_: Administrator, stock administrator

**Emergency Access**:
A temporary, reason-bound, fully audited elevation that permits the Super Administrator to perform otherwise-isolated Store operations.
_Avoid_: Role bypass, master access

**Fiscal Period**:
A time-bounded operational accounting window for the Store. The Administrator reviews and governs its closure; the Super Administrator may intervene only through Emergency Access.
_Avoid_: Branch fiscal period, system period

**Platform Setting**:
A technical configuration affecting platform security, recovery, integrations, demand-forecasting model operation, or their attention thresholds. It is governed exclusively by the Super Administrator and may impose safety limits on Store Settings.
_Avoid_: Store setting, admin setting

**Store Setting**:
An operational configuration affecting the Store's day-to-day work, including sales, cash, and inventory attention thresholds. It is governed by the Administrator and cannot weaken Platform Settings.
_Avoid_: Platform setting, branch setting

**Demand Forecast**:
A model-produced estimate of future product demand for the Store. The Super Administrator governs model operation, the Administrator reviews performance and operational exceptions, and the Inventory Manager uses the forecast for replenishment work.
_Avoid_: Branch forecast, guaranteed demand

**Protected Audit Record**:
An immutable record of a security-sensitive or operational action that application users may view or export but never edit or delete. The Administrator may inspect Store-operational records; security, recovery, Platform Setting, and Recovery Account records are visible only to the Super Administrator.
_Avoid_: Editable log, activity note

**Supplier Product Terms**:
The purchasing relationship between a supplier and a product, including unit cost, minimum order quantity, lead time, and whether the supplier is preferred for that product.
_Avoid_: Supplier mapping, supplier directory
