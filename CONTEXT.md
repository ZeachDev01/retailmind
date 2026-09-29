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
The operational owner responsible for Store performance, staff, approvals, compliance, Fiscal Periods, Store Settings, and operational exceptions. It has delegated authority to create, edit, disable, reset passwords for, and revoke sessions from Cashiers and Inventory Managers. It assigns only fixed operational role templates defined by the Super Administrator; it cannot create privileged administrators or arbitrary privilege combinations. It may create a Database Backup alongside the Super Administrator. Routine inventory execution belongs to the Inventory Manager; platform security, recovery, and technical model controls belong to the Super Administrator.
_Avoid_: System administrator, global administrator, inventory manager

**Database Backup**:
A complete, readable copy of the Store's database representing a consistent point in time, which either the Administrator or Super Administrator may create and download. Possession exposes all captured records, including credentials and restricted audit history.
_Avoid_: Parallel backup, report export

**Database Restore**:
The replacement of the entire active Store database, including accounts, settings, and audit history, with the state captured in a Database Backup. Only the Super Administrator may perform it; newer database records are not retained, while the restoration itself is recorded separately.
_Avoid_: Import report, undo backup

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

**Cashier**:
An individually authenticated Staff member who operates the point of sale only from the active Cashier workspace. Any number of Staff accounts may hold the Cashier role, including an Administrator who also needs to sell; the active workspace alone decides point-of-sale permission, and a Disabled Account keeps its historical attribution while losing sign-in.
_Avoid_: Shared cashier account, admin bypass of cashier controls

**Cashier Shift**:
The single open drawer-ownership session that authorizes a Cashier to sell from the active Cashier workspace. A Cashier holds at most one open shift at a time, an open shift is required before point-of-sale work, and closing reconciles counted cash against expected cash.
_Avoid_: Shared shift, inferred shift, silent sale without a shift

**Register**:
The named physical till that anchors drawer accountability for one open Cashier Shift at a time. It is created, renamed, or disabled without deleting its history, and unavailable Registers are excluded from new shifts.
_Avoid_: Shared drawer, inferred till, deleted register history

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
A Random Forest-produced estimate of future units demanded for one product in the Store, reported over 7-, 14-, and 28-day Forecast Horizons. It supports replenishment decisions but never places an order or changes stock. The Super Administrator governs Model Retraining, the Administrator reviews performance and operational exceptions, and the Inventory Manager uses the forecast for replenishment work.
_Avoid_: Branch forecast, guaranteed demand

**Forecast Horizon**:
One of the fixed future periods—7, 14, or 28 days—over which daily Demand Forecast values are summed for review and replenishment planning.
_Avoid_: 30-day forecast, arbitrary horizon

**Forecast Generation**:
Applying the currently approved forecasting model to Store data after close or through a manual request. The Administrator and Super Administrator may request it; it does not alter the model.
_Avoid_: Model training, automatic ordering, retraining

**Model Retraining**:
Replacing the currently approved forecasting model with a newly fitted Random Forest after chronological validation. It runs weekly after close or through a manual Super Administrator request and is recorded as a Protected Audit Record.
_Avoid_: Forecast generation, live request training, Administrator retraining

**Forecast Readiness**:
The evidence level attached to a product forecast: Insufficient below 56 history days, Low from 56–179, Medium from 180–364, and High from 365 onward only when validation error is acceptable. The interface marks lower-readiness forecasts instead of hiding them or presenting them as equally reliable.
_Avoid_: Guaranteed confidence, model probability, accuracy percentage

**Forecast Evaluation**:
A chronological backtest of the 7-, 14-, and 28-day forecasts, reported with MAE as the primary panel-facing metric, RMSE as the secondary metric, and WAPE as the business comparison metric.
_Avoid_: Random train/test split, FreshRetailNet accuracy test

**Scheduled Demand Driver**:
A future price or promotion already recorded for its effective date and therefore safe to use when generating a Demand Forecast. Unscheduled or assumed future changes are never inserted as model inputs.
_Avoid_: Guessed promotion, planned-but-unrecorded price

**Replenishment Recommendation**:
A non-binding quantity or attention marker derived from a Demand Forecast, current stock, and supplier lead time. A Store operator reviews it before any purchasing action.
_Avoid_: Automatic purchase order, guaranteed reorder

**Protected Audit Record**:
A record of a security-sensitive or operational action that application users cannot individually edit or delete; Database Restore returns these records to the backup's state. In-app visibility remains role-restricted, but a complete Database Backup exposes all captured records to either administrator role.
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
