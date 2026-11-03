# Consistent notification counts and stock-alert recovery

## Problem Statement

Staff cannot tell whether Notifications represent unseen updates or unresolved stock conditions. The Inventory Manager screenshot shows Unread 0 while the bell still shows 10. Marking a low-stock Notification read does not reliably produce matching counts, and the user reports stale alerts remaining after replenishment restores safe stock.

The Administrator notification center labels stock oversight as Action needed even though routine replenishment belongs to the Inventory Manager. The Administrator screenshot shows 62 such conditions alongside a bell count of 2, implying personal work that the active Workspace cannot perform.

## Solution

Make the bell and the unfiltered Unread total agree for the active Workspace. Reading acknowledges an update and immediately clears its unread contribution; it does not alter inventory or resolve the underlying condition. An unchanged condition must not restore unread status.

Automatically resolve stock alerts when current stock no longer meets their trigger. Remove resolved alerts from Unread, active reminders, and bell counts, while retaining history in All with a Resolved label. Stock Overview and Replenishment Recommendations remain the places to track unresolved stock work.

Keep Administrator stockout and overdue-stock oversight Notifications, but exclude those inventory oversight conditions from Action needed. Reserve that section for work the active role can perform. Preserve existing role authority and routing.

## User Stories

1. As an Inventory Manager, I want the bell and unfiltered Unread total to agree, so that I can trust how many updates need reading.
2. As a staff member, I want marking one Notification read to update both counts immediately, so that acknowledgement has a clear result.
3. As a staff member, I want bulk read actions to update both counts consistently, so that clearing my inbox does not leave a stale badge.
4. As a staff member, I want the bell badge hidden at zero, so that it does not imply unseen work.
5. As a staff member, I want category filters to retain their established scope, so that a filtered count does not silently redefine the Workspace-wide bell.
6. As an Inventory Manager, I want a read low-stock Notification to stay read while the condition is unchanged, so that repeated checks do not demand acknowledgement again.
7. As an Inventory Manager, I want reading an alert to leave stock unchanged, so that acknowledging an update cannot falsify inventory.
8. As an Inventory Manager, I want successful Stock Receiving that restores safe stock to clear the active low-stock alert, so that replenished products do not remain flagged.
9. As an Inventory Manager, I want replenishment above safe stock to clear the same alert, so that excess replenishment does not leave stale warnings.
10. As an Inventory Manager, I want partial replenishment that remains low stock to retain the underlying warning, so that insufficient replenishment is not mistaken for recovery.
11. As a staff member, I want recovered stock alerts removed from Unread even if I never read them, so that obsolete warnings do not inflate the bell.
12. As a staff member, I want recovered stock alerts removed from active reminders, so that those reminders describe current conditions.
13. As a staff member, I want resolved stock Notifications retained in All with a Resolved label, so that useful history survives.
14. As an Inventory Manager, I want low stock that becomes zero stock to generate a new escalation update, so that a meaningful worsening receives attention.
15. As an Inventory Manager, I want stock that recovers and later becomes low again to generate a fresh Notification, so that recurrence is visible.
16. As an Inventory Manager, I want repeated recovery or detection checks to avoid duplicate Notifications, so that routine processing does not create noise.
17. As an Administrator, I want stockout oversight Notifications without an Action needed assignment, so that visibility does not imply replenishment authority.
18. As an Administrator, I want overdue inventory oversight kept outside Action needed, so that responsibility remains with the Inventory Manager.
19. As an Administrator, I want genuinely actionable reviews retained in Action needed, so that I can find work my role can perform.
20. As a multi-role staff member, I want counts and read actions isolated to the active Workspace, so that acknowledging inventory updates does not clear another role's inbox.
21. As a staff member, I want another user's read state protected, so that my actions cannot change their inbox.
22. As a staff member, I want existing active stock alerts reconciled against current stock, so that the fix also clears stale warnings already present.

## Implementation Decisions

- Reuse the existing Notification service, Attention condition lifecycle, Workspace scoping, read actions, bell, and notification list. Diagnose the count mismatch across their consumers before changing shared behavior; the screenshot establishes a symptom, not its technical cause.
- Keep unread acknowledgement separate from condition resolution. No new acknowledgement workflow or manual stock-resolution action is required.
- Bell counts represent eligible unread Notifications in the active Workspace, never the number of unresolved conditions. The unfiltered Unread total uses the same eligibility rules. Category-specific counts may differ intentionally.
- Update the visible bell and list state after successful individual or bulk read actions without requiring a manual page reload. Preserve category-scoped bulk actions.
- Retain established stock thresholds. Current low-stock eligibility is quantity on hand at or below Reorder Level; safe stock for this alert means strictly above Reorder Level. Equality still qualifies as low stock. Do not reinterpret Safety Stock as a new trigger or change replenishment calculations.
- Evaluate each condition independently: replenishing from zero to a positive quantity clears the Administrator stockout condition, while the Inventory Manager low-stock condition remains if the quantity is still at or below Reorder Level.
- Successful Stock Receiving must make recovery visible on the next notification-center/bell render without waiting for the daily scheduler. Cover other existing stock-changing paths through the smallest shared reconciliation mechanism appropriate to the current architecture.
- Recovery removes associated stale stock updates from unread eligibility and active reminders, and cancels pending obsolete condition email through the existing lifecycle. Retain historical Notifications with a Resolved label; do not delete history or operational records.
- History resolution must distinguish a recovered occurrence from a later recurrence. A fresh occurrence must not make older resolved entries appear active again.
- Do not recreate unread Notifications for unchanged active conditions. Preserve escalation from low stock to out of stock and fresh delivery after recovery followed by recurrence.
- Exclude Administrator inventory oversight from Action needed while retaining its notification history and existing stockout/overdue-stock recipient rules. Other actionable Administrator reviews remain. Notification eligibility never grants inventory authority.
- Reconcile already-existing stock Notifications safely and repeatably against current conditions; do not limit the fix to alerts created after deployment.
- Preserve account and Workspace isolation, role-removal visibility, preferences, CSRF protection, escaped content, and accessible controls. Use existing state/storage where sufficient; this specification does not mandate a new schema.

## Testing Decisions

- The user confirmed extending existing notification browser coverage and stock-alert service contracts. Prefer these established seams over new test interfaces.
- Test observable behavior: delivered updates, visible rows and labels, bell/list counts, permitted actions, and recurrence. Avoid tests that merely reproduce private SQL or implementation structure.
- Extend the notification list and Administrator stock-notification browser fixtures to cover individual and bulk reads, same-page bell refresh, zero-badge removal, filtered counts, active Workspace isolation, and account isolation.
- Verify Administrator stock oversight remains in notification history but is absent from Action needed; preserve a genuinely actionable Administrator review as a control.
- Extend role-notification and Attention lifecycle contracts for unchanged reruns, read-state preservation, escalation, recovery, repeated recovery, and recurrence.
- Cover stock quantities below, exactly at, and above Reorder Level; partial replenishment from zero; and replenishment to safe stock or above it.
- Exercise successful Stock Receiving through existing workflow fixtures, followed by notification rendering before any scheduled run. Assert removal from active reminders, Unread, and bell counts, plus retained Resolved history.
- Cover recovery of an unread alert, recovery of a read alert, and existing stale alerts. Confirm recurrence creates one new unread update without reactivating old history.
- Use disposable database fixtures and the existing injectable clock. Do not run destructive fixture cleanup against the configured Store database.

## Out of Scope

- Implementing the feature as part of publishing this specification.
- Changing role permissions, stock thresholds, Safety Stock calculations, or replenishment policy.
- Removing Administrator oversight delivery or adding inventory execution authority to that Workspace.
- New push/SMS channels, delivery infrastructure, manual dismissal workflows, or speculative reminder settings.
- Broad dashboard or notification-page redesign, retirement of all Action needed sections, or unrelated forecasting changes.
- Deleting notification history or modifying sales, stock, or Stock Issue records to hide warnings.

## Further Notes

- Product behavior and the existing test approach were confirmed in discussion on 2026-11-03.
- Screenshots establish a bell/list discrepancy and confusing Administrator action labeling. Stale alerts following replenishment are user-reported. Runtime root causes remain to be verified during implementation.
- This specification refines the earlier role-based notification specification; it preserves its responsibility split and replaces the confusing presentation of inventory oversight and stale stock conditions.
- No real-time cross-tab delivery is required. Counts must be correct after the user's successful read action and on the next normal render after stock recovery.
