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
_Avoid_: Deleted user, removed history, inactive account

**Dormant Account**:
A user account with no successful login for the configured dormancy period; an account that has never logged in is measured from its creation date.
_Avoid_: Inactive account, unused account, stale login

**Login Identifier**:
The single Staff login field value: a Username OR an Email Address. Username is primary and never contains `@`; Email Address is an alternate co-option, optional and unique where present. The Recovery Account is excluded from email matching and stays reachable by Username only.
_Avoid_: Login name, email-only login, second login field

**Username**:
The primary Staff sign-in name and the fallback for accounts with no Email Address on file. It never contains `@` and never contains whitespace; letters, numbers, `.`, `_`, and `-` keep working. The Recovery Account signs in by Username only.
_Avoid_: Login name, email-style username

**Email Address**:
The alternate Staff sign-in co-option typed into the same single login field. It is optional and unique where present; an empty value stays stored as NULL and simply means email sign-in fails generic for that person. Matching is case-insensitive, and changing it immediately invalidates the old address. The Recovery Account is excluded from email matching and stays reachable by Username only.
_Avoid_: Second login field, email-only login

**Dormancy Policy**:
The Platform Setting that disables Dormant Accounts after a configured number of days, warns Administrators beforehand, and never leaves the Store without an active Administrator. Disabling retains historical attribution, revokes sessions, and emails the holder when an address is on file.
_Avoid_: Auto-disable rule, inactivity timeout, session timeout

**Inventory Manager**:
The Store operator responsible for routine inventory execution, including stock monitoring, replenishment, receiving, and inventory control. It does not own Store-wide staff, compliance, or access oversight.
_Avoid_: Administrator, stock administrator

**Stock Issue**:
A Cashier-submitted report that units were Damaged, Missing/Lost, Expired, or Other, moving through pending, returned, cancelled, approved, or rejected. Inventory Managers decide reports; only an approval deducts stock through a linked stock movement. Approved and rejected reports are immutable, and an erroneous approval is corrected only through a separate Inventory Manager inventory count linked back to the report. Administrators hold read-only oversight.
_Avoid_: Damage report, damage claim, reopen an approval

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

**Temporary Password**:
An Administrator-issued credential for a Cashier or Inventory Manager that must be replaced by the holder before Store access. Creating Store staff and resetting a staff password both issue one; completing a Mandatory Password Change or an email-link reset clears it.
_Avoid_: Default password, live credential

**Mandatory Password Change**:
The forced gate that blocks all Store access until the holder replaces a Temporary Password. It triggers solely on the Temporary Password flag and always permits Logout.
_Avoid_: Voluntary change, password expiry

**Voluntary Password Change**:
A Profile-initiated password rotation available at any time when no Temporary Password flag is set. It verifies the current password and revokes other sessions without blocking navigation.
_Avoid_: Forced reset, recovery flow

**Administrator Password Reset**:
The Administrator action that issues a Temporary Password for a Cashier or Inventory Manager, revokes the holder's other sessions, and forces replacement on next login. It is recorded as a Protected Audit Record.
_Avoid_: Self-service reset, Recovery Account rotation
