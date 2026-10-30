# RetailMind

RetailMind supports one Store's inventory, receiving, sales, and demand-forecasting operations.

## Language

**Stock Batch**:
A separately tracked quantity of one Product with its own receiving provenance, remaining quantity, and expiration date when recorded. Different batches may share the same expiration date.
_Avoid_: Product expiration, unique expiration date

**Opening Batch**:
A Stock Batch representing stock already on hand when a Product is first entered into RetailMind.
_Avoid_: Default stock, default expiration

**Expiry Warning Lead Time**:
The number of calendar days before a Stock Batch's expiration when it should receive an expiring-soon warning. It describes when attention is needed, not how long the goods last.
_Avoid_: Shelf life, expiration date

**Supplier Pullout**:
The supplier's collection of a recorded quantity from an existing Stock Batch. It is distinct from receipt of Replacement Stock and from a customer Cash Refund.
_Avoid_: Cash Refund, stock count, refreshed batch

**Replacement Stock**:
New goods supplied in relation to a Supplier Pullout, with their own quantity and expiration. The replacement quantity and arrival time may differ from the pullout.
_Avoid_: Expiration extension, overwritten stock

Design status, workflow, and unresolved policy choices: [batch expiration context](specs/batch-expiration-context.md), [ADR-0013](adr/0013-batch-expiration-and-supplier-replacements.md).

**Notification**:
A user-addressed update about Store activity or a condition requiring attention. Reading it does not resolve the underlying condition.
_Avoid_: Resolved issue, global announcement

**Notification Category**:
The subject classification of a Notification, such as low stock or replenishment. Selecting a category narrows the updates being viewed; it does not enable or disable their delivery.
_Avoid_: Notification preference, severity

**Application Log Acknowledgment**:
The Super Administrator's recorded investigation of a specific version of the application log, including its result and verification note. Later log entries can require another review; acknowledgment does not resolve other service failures.
_Avoid_: Log deletion, service repair, automatic fix verification

**Failed Sign-in Review**:
The Super Administrator's recorded assessment of unsuccessful sign-in attempts, including the review result and supporting note. Reviewed attempts remain part of the security history while further unsuccessful attempts can require another review.
_Avoid_: Warning deletion, automatic intent verification

**Store**:
The single retail location operated through RetailMind. RetailMind does not divide operations or records among multiple branches.
_Avoid_: Branch, location assignment, tenant

**Super Administrator**:
The single technical owner responsible for platform security, Platform Settings, recovery, access policy, system health, and demand-forecasting model operation. Its ordinary-user management is limited to creating ordinary Administrator accounts and disabling ordinary accounts with Administrator access; routine staff management belongs to the Administrator Workspace. Other accounts cannot manage a Super Administrator identity, and Recovery Account management stays outside ordinary Users workflows. One primary account is used routinely; a sealed Recovery Account exists only for lockout or disaster recovery. While in the Super Administrator workspace it may inspect Store operations for oversight but does not perform operational mutations; stepping into Store administration, inventory execution, or point-of-sale work requires switching into the corresponding assigned workspace, apart from explicitly permitted per-action Administrator credential approvals.
_Avoid_: Superadmin, unrestricted operator, Store operator

**Recovery Account**:
A sealed privileged identity used only when the primary Super Administrator cannot recover access. It is activated through an offline recovery procedure requiring separately stored credentials, never through ordinary login or email reset. Its activation and every action performed through it are recorded as Protected Audit Records.
_Avoid_: Backup administrator, shared admin account, routine login

**Administrator**:
The operational owner responsible for Store performance, staff, approvals, compliance, Fiscal Periods, Store Settings, and operational exceptions. It has delegated authority to create, edit, disable, reset passwords for, and revoke sessions from other ordinary Administrators, Cashiers, and Inventory Managers, including accounts with additional Administrator access. It may promote staff using only the fixed Administrator, Cashier, and Inventory Manager role templates; it cannot assign Super Administrator, arbitrary privileges, or technical governance. Manual changes preserve at least one active ordinary account with assigned Administrator access, without a Super Administrator bypass; self-disable and self-demotion remain forbidden. Account management requires the active Administrator Workspace. It may create a Database Backup alongside the Super Administrator. Routine inventory execution belongs to the Inventory Manager; platform security, recovery, and technical model controls belong to the Super Administrator.
_Avoid_: System administrator, global administrator, inventory manager

**Database Backup**:
An encrypted, consistent package of the Store database and required uploaded Product/profile images, which either administrator role may create and download using a separate backup passphrase. A decrypted complete package includes restricted captured records. See STORE_PACKAGE_FORMAT.md for the current capture/preflight boundary.
_Avoid_: Parallel backup, report export

**Identity Reconciliation**:
The destination Super Administrator's explicit read-only review of source people, destination accounts, conflicts, preservation closure, and resulting access. Matching identifiers never establish identity. Links retain destination account authority; distinct ordinary people use deliberately selected unused login details. Source privileged-only and Recovery people remain Historical Actors with no usable access.
_Avoid_: Automatic account matching, general account merging

**Preservation Closure**:
The complete destination security and state boundary preserved intact during reconciliation: protected accounts, credentials, profiles, sealed recovery state, fixed capability policy, platform settings, platform-scoped attention settings, technical forecasting configuration, classified audit evidence, login tracking, review watermarks, protected reset state, preferences, notifications, backup and email-delivery history, and dependent logical actors preserved as evidence-only accounts without usable access.
_Avoid_: Partial import, blind overwrite, security reset

**Reconciliation Approval**:
The Super Administrator's cryptographic verification and policy endorsement of explicit reconciliation resolutions, recorded in destination-owned preparation evidence under RecoveryStore with exact package integrity, identity/file maps, administrator designations, and a destination watermark. Ordinary Administrators cannot approve or alter reconciliation; immediately before destructive use, strict rechecks invalidate approval if package, state, or authority changed.
_Avoid_: Unattended restore, silent promotion, unapproved migration

**Historical Actor**:
An identity retained only for attribution, represented as a disabled roleless user with an unusable password and no memberships, reset authority or recovery state. It cannot be enabled or assigned a role. Installation-aware provenance preserves original names, login details, roles and embedded historical IDs without changing frozen receipt or audit JSON.
_Avoid_: Disabled Account, deleted user, active staff

**Database Restore**:
The replacement of operational Store records and required uploaded files with the state captured in an encrypted Database Backup, while preserving destination privileged access and security evidence. Either administrator role may initiate it after the Super Administrator approves Identity Reconciliation; newer operational records are not retained, and the Store remains closed until verification and explicit reopening.
_Avoid_: Import report, undo backup

**Recovery Console**:
The browser interface that renders and authenticates against retained state in RecoveryStore without querying database tables during restore or incomplete maintenance. It provides role-restricted evidence and operation-bound operator authentication.
_Avoid_: Admin login, emergency backdoor

**Durable Recovery Stage**:
The persisted progress milestone (safety_verified, destructive_start, database_replacement, uploads, automated_verification, operator_review_pending, recovery_required, reopened_completed) tracked in RecoveryStore and restore-log.jsonl outside replaceable tables. Destructive start is persisted before any destructive writes.
_Avoid_: Transient progress, uncoordinated restore

**Pre-restore Operator Authentication**:
The independent verification of initiating Administrator and active destination Super Administrator credentials against pre-restore password hashes retained in RecoveryStore. Sealed Recovery Accounts retain separate rules; imported users, unrelated Administrators and ordinary sessions cannot authorize recovery.
_Avoid_: Decrypted password cache, generic session bypass

**Complete Safety Recovery**:
The restoration of the destination database, uploads, and security state directly from the original verified safety copy checkpoint. Requires the destination safety passphrase and operates completely without incoming package material.
_Avoid_: Partial rollback, unverified destination recovery

**Approved Incoming Restart**:
The deterministic, from-the-beginning re-execution of an approved incoming replacement. Requires both the incoming and safety passphrases, revalidates package and plan integrity against the destination checkpoint independently of live tables, and enforces security preservation closure.
_Avoid_: In-place resume, statement offset continuation, guessed mapping

**Controlled Verification Check**:
The narrowly scoped read-only preview (sign-in preview, stock check, receipt lookup, image preview, report check) permitted in the Recovery Console while Store writes and background jobs remain fenced.
_Avoid_: Unfenced preview, test write, unverified read

**Review Checklist Evidence**:
The durable record (`review_checks`) of successful automated verification and operator human confirmations (stock, receipt, upload, attribution) in RecoveryStore. Missing or stale checks prevent reopening.
_Avoid_: Oral sign-off, transient review, unrecorded check

**Explicit Store Reopening**:
The intentional operator action requiring fresh destination password verification, operation binding, CSRF token, and checklist confirmations. Reopening invalidates existing sessions via session-epoch bump, unpauses write coordination, records reopening evidence, and admits ordinary traffic.
_Avoid_: Automatic reopening, implicit unpause, passwordless resume

**One-Active-Store Constraint**:
The operational rule that completing recovery reopens this Store as the sole operating RetailMind instance. Recovery completion does not authorize dual active stores running parallel operations alongside the origin store.
_Avoid_: Dual active stores, split-brain operation, multi-master sync

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

**Cashier Shift Variance Review**:
The Administrator's recorded investigation of a closed Cashier Shift's cash shortage or excess, with an explanation and reviewer identity and time. Review ends pending attention while preserving the original amounts and history; it does not establish that missing money was recovered.
_Avoid_: Cash correction, recovered shortage, GCash discrepancy waiver

**Held Sale**:
A cart a Cashier suspended at the till. It belongs to that Cashier and to the Cashier Shift that was open when it was held, is listed and resumed only by its owner, and stays unresolved — owed a decision — from the moment it is held until it either becomes a sale or is discarded with a structured reason. Resuming does not resolve it, and no Cashier Shift closes while one is unresolved. Discarding moves no cash and is recorded as a Protected Audit Record. Time alone never resolves a Held Sale; an orphan — a Held Sale belonging to no open Cashier Shift — is the only one that expires on its own.
_Avoid_: Floating cart, silent cancel, self-expiring cart, cancel button on a held sale

**Register Lock**:
The break-time state of an open Cashier Shift that leaves the drawer owned and the Register claimed while the point of sale refuses to transact. Only the owning Cashier resumes it, with their own account password; a separate PIN or unlock code is never introduced. Locking and logging out both leave the shift open and unreconciled, and the lock is held by the shift rather than the session so a Register never reopens itself.
_Avoid_: Closed shift, break mode, shared PIN, session logout

**Net Sales**:
The Store's completed sales after discounts, minus refunds. Net Sales does not deduct product costs or operating expenses and is not profit.
_Avoid_: Earnings, gross profit, net profit

**Gross Sales**:
The Store's completed Product sales before discounts and Cash Refunds. Gross Sales does not deduct product costs or operating expenses.
_Avoid_: Net Sales, Gross Profit, earnings

**Gross Profit**:
Net Sales minus the corresponding cost of products sold, adjusted for returns. Gross Profit excludes operating expenses and is not Net Profit or take-home earnings.
_Avoid_: Net Sales, Gross Sales, Net Profit, take-home earnings

**Cash Refund**:
An append-only record a Cashier issues against a completed sale, normally their own, in the active Cashier workspace and under their own open Cashier Shift; an Administrator-authorized exception permits another Cashier to handle an absent or disabled original Cashier's sale while retaining original seller, issuing Cashier, approver, and paying-shift attribution. It never edits, deletes, or voids the sale it reverses, so what is left refundable is a sum over the refunds already issued rather than a column on the sale. It is settled on the sale's original payment method, and only a cash refund reduces expected drawer cash. Full and partial refunds are allowed, capped by each line's remaining quantity and by the amount the customer actually paid, even under concurrent attempts. Routine own-sale refunds require no supervisor approval: individual identity, a predefined reason, a note when the reason is Other, append-only records, Cashier-scoped visibility, and a Protected Audit Record are what make them accountable. Every returned item is classified Restockable or Damaged, and only a Restockable quantity returns to available inventory; a Damaged customer return is not also deducted through a Stock Issue.
_Avoid_: Sale edit, sale void, stock-only return, supervisor-approved reversal, store credit

**Payment Reference**:
The identifier retained as evidence of a card or e-wallet payment or refund completed and verified externally before it is recorded in RetailMind. RetailMind records the transaction and reference without using a payment gateway to move money.
_Avoid_: Gateway confirmation, automatically processed payment

**Sale Receipt**:
The customer-facing record of a completed sale, shown after checkout and available for later reprint. Its transaction-time details remain the same on a reprint even if Store details or the Register name change later.
_Avoid_: Invoice, live sale summary

**Refund Receipt**:
The separate customer-facing record of one Cash Refund, identifying the original sale and the items and amount refunded. It is shown when the refund is recorded and remains available for later reprint.
_Avoid_: Edited Sale Receipt, sale void slip

**Receipt History**:
The customer receipt lookup and reprint view presented by Sales Transactions, with visibility limited to the viewer's authorized sales.
_Avoid_: Operational history, invoice management

**Cashier Operational History**:
The individually scoped record of a Cashier's sales, refunds, drawer movements, and Cashier Shifts, presented by My History.
_Avoid_: Receipt history, Store-wide report

**Stock Issue**:
A Cashier-submitted report that units were Damaged, Missing/Lost, Expired, or Other, moving through pending, returned, cancelled, approved, or rejected. Inventory Managers decide reports; only an approval deducts stock through a linked stock movement. Approved and rejected reports are immutable, and an erroneous approval is corrected only through a separate Inventory Manager inventory count linked back to the report. Administrators hold read-only oversight.
_Avoid_: Damage report, damage claim, reopen an approval

**Fiscal Period**:
A date-bounded reporting and accounting window for the Store, governed by the Administrator. It is separate from a Cashier Shift.
_Avoid_: Cashier Shift, work session, branch fiscal period

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

**Forecast Data Import**:
The Super Administrator's addition of historical daily sales totals from the same Store for demand forecasting. Explicit zero sales are observations; omitted dates remain unknown. Imported history does not create receipts, change stock or revenue, or start Model Retraining.
_Avoid_: Sales migration, public dataset upload, automatic retraining

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
A non-binding buying suggestion reviewed by the Inventory Manager, using current stock and existing stock rules or an available Demand Forecast. Missing forecasts remain explicitly unavailable. No supplier configuration is required, and actual purchases may differ in products, stores, or quantities. Reviewing guidance neither places orders nor changes stock.
_Avoid_: Automatic purchase order, guaranteed reorder

**Stock Receiving**:
The Inventory Manager's record of bought goods brought into the Store, including several products entered together as individual units or saved packs/cases. Supplier, receipt reference, and purchase cost are optional; no request, order, or Administrator approval is required for direct receiving. Only accepted units add to usable stock. Receiving History groups saved shopping entries with staff attribution; Administrators retain read-only oversight.
_Avoid_: Purchase approval, stock count (when referring to newly bought goods)

**Protected Audit Record**:
A record of a security-sensitive or operational action that application users cannot individually edit or delete; Database Restore returns these records to the backup's state. In-app visibility remains role-restricted, but a complete Database Backup exposes all captured records to either administrator role.
_Avoid_: Editable log, activity note

**Temporary Password**:
An administrator-issued credential for an ordinary Administrator, Cashier, or Inventory Manager that must be replaced by the holder before Store access. Creating an ordinary Store account and resetting its password both issue one; completing a Mandatory Password Change or an email-link reset clears it.
_Avoid_: Default password, live credential

**Mandatory Password Change**:
The forced gate that blocks all Store access until the holder replaces a Temporary Password. It triggers solely on the Temporary Password flag and always permits Logout.
_Avoid_: Voluntary change, password expiry

**Voluntary Password Change**:
A Profile-initiated password rotation available at any time when no Temporary Password flag is set. It verifies the current password and revokes other sessions without blocking navigation.
_Avoid_: Forced reset, recovery flow

**Administrator Password Reset**:
The authorized Administrator or Super Administrator Workspace action that issues a Temporary Password for an ordinary Administrator, Cashier, or Inventory Manager, revokes the holder's other sessions, and forces replacement on next login. It is recorded as a Protected Audit Record.
_Avoid_: Self-service reset, Recovery Account rotation
