# Role-based Notifications

Status: Product decisions and test approach confirmed on 2026-10-21. Implemented on 2026-10-21; see #262–#268.

## Problem Statement

Staff receive Notifications unrelated to their responsibilities. Routine inventory alerts reach Administrators and Cashiers, technical model-training failures reach operational staff, and users with secondary roles can miss relevant updates. A shared user inbox mixes Workspaces, making unread counts and read actions unreliable indicators of each role's work.

Administrator attention currently treats every pending Stock Issue as an escalation. Required work can also be suppressed by inconsistent preference handling, while repeated checks can generate unnecessary alerts. Existing development Notifications reflect these inconsistent rules.

## Solution

Route Notifications according to responsibility across every assigned role, and show each Workspace only its relevant updates. Keep required-action in-app Notifications enabled, retain optional routine reminders and daily email, escalate significant inventory exceptions to the Administrator, and notify again only on escalation or recurrence after resolution.

| Workspace | Responsibility |
| --- | --- |
| Super Administrator | Platform security, locked accounts, backup and restore failures, system health, and technical Demand Forecast failures. |
| Administrator | Cash discrepancies, unusual discounts, sales reversals, Fiscal Period deadlines, and serious or overdue inventory exceptions. |
| Inventory Manager | Low stock, replenishment, expiry and Supplier Pullout reminders, unusual demand, and Stock Issues awaiting review. |
| Cashier | Own workflow updates, including Stock Issues returned, approved, or rejected; relevant stock warnings during checkout. |

Administrator inventory oversight remains read-only. Routine replenishment alerts do not fill Cashier inboxes. Correct existing development Notifications without changing operational records.

## User Stories

1. As a Super Administrator, I want security and locked-account alerts, so that I can protect platform access.
2. As a Super Administrator, I want backup, restore, and system-health alerts, so that I can investigate technical failures.
3. As a Super Administrator, I want technical Demand Forecast failure alerts, so that operational staff do not receive internal error details.
4. As an Administrator, I want cash discrepancies, unusual discounts, and sales reversals highlighted, so that I can investigate Store exceptions.
5. As an Administrator, I want Fiscal Period deadline alerts, so that I can complete compliance work.
6. As an Administrator, I want serious or overdue Stock Issues escalated, so that I can oversee risk without deciding inventory adjustments.
7. As an Administrator, I want out-of-stock products and overdue expired-stock collection escalated, so that I can follow up with Inventory Managers.
8. As an Inventory Manager, I want routine low-stock and replenishment alerts, so that I can plan stock purchases.
9. As an Inventory Manager, I want expiry and Supplier Pullout reminders, so that I can prepare goods for collection.
10. As an Inventory Manager, I want unusual-demand alerts, so that I can review replenishment needs.
11. As an Inventory Manager, I want new and resubmitted Stock Issues promptly, so that I can review reports.
12. As a Cashier, I want my returned Stock Issues highlighted, so that I can correct and resubmit them.
13. As a Cashier, I want approval and rejection updates for my Stock Issues, so that I know their outcomes.
14. As a Cashier, I want relevant stock warnings during checkout, so that I can respond while serving a customer.
15. As a Cashier, I want replenishment alerts routed to Inventory Managers, so that my inbox remains relevant.
16. As a multi-role staff member, I want eligibility based on every assigned role, so that a secondary role does not miss its work.
17. As a multi-role staff member, I want the active Workspace's list and bell count, so that I can identify that role's unread updates.
18. As a multi-role staff member, I want read actions isolated by Workspace, so that reading one role's updates does not clear another role's unread work.
19. As an account administrator, I want role removal to revoke access to that role's Notifications, so that former assignments do not retain visibility.
20. As a staff member, I want required-action in-app alerts to remain enabled, so that preference changes do not hide required work.
21. As a staff member, I want routine reminders and email to remain optional, so that I can control optional delivery.
22. As an email recipient, I want new or escalated conditions emailed through the daily run, so that unchanged conditions do not create daily duplicates.
23. As a staff member, I want unresolved required work to remain visible after reading, so that reading is not mistaken for resolution.
24. As a staff member, I want another alert when a resolved condition returns or an active condition escalates, so that meaningful changes receive attention.
25. As a developer, I want existing development alerts corrected and current conditions regenerated, so that demonstrations match the agreed role rules.
26. As a reporting Cashier, I want valid Stock Issue workflow updates retained during cleanup, so that useful history survives.
27. As a staff member, I want my Notifications protected from other users, so that Workspace filtering preserves account isolation.

## Implementation Decisions

- Reuse the existing Notification service, Attention rules and signal source, Stock Issue workflow, daily notification runner, and shared authorization and Workspace mechanisms. Correct both transaction-driven and scheduled producers consistently.
- Determine recipients from all currently assigned roles and active-account eligibility. Primary-role-only selection is insufficient. Keep role permissions separate; Notification eligibility does not grant authority to perform the linked action.
- Maintain a reliable recipient Workspace association for filtering, unread counts, read mutations, and destinations. Exact storage representation is an implementation choice; use the smallest consistent extension to existing persistence.
- Scope lists, categories, counts, individual read actions, bulk read actions, and links to both the signed-in user and active Workspace. Do not rely on hidden links as authorization.
- New or resubmitted Stock Issues notify eligible Inventory Managers immediately through the workflow. Returned, approved, and rejected reports notify their reporting Cashier.
- Stock Issues escalate when pending for at least 24 hours OR valued at PHP 5,000 or more. Reuse existing configurable thresholds. Counting all pending reports alone must not trigger escalation. Preserve the existing per-report value calculation rather than treating the total value of unrelated reports as a serious individual report.
- Products becoming out of stock escalate to Administrator oversight. Expired stock remaining uncollected 24 hours after expiry also escalates. Reuse the Store's inventory and Stock Batch conventions and Philippine time; stock that is no longer present must not continue generating an active expiry condition.
- Routine low stock and approaching expiry remain Inventory Manager concerns. Checkout warnings remain contextual Cashier feedback.
- Required-action in-app delivery overrides optional-delivery preferences for security failures, pending Stock Issue reviews, returned reports, and escalated exceptions. Routine reminders and email remain optional. Do not treat the entire Notification Category as mandatory when its individual conditions differ.
- Keep email on the existing daily notification run. Only newly triggered or escalated conditions are eligible, subject to email preferences and available recipient addresses. There is no immediate-email guarantee. Email-only recipients must also be deduplicated.
- Use stable condition identity and lifecycle state. Repeated checks of an unchanged condition must not insert or email another alert. Reading does not resolve a condition. Notify again on escalation or recurrence after actual resolution; required unresolved work remains visible independently of unread status.
- Send technical model-training failures to the Super Administrator. Operational recipients must not receive raw internal error details.
- Correct existing development Notifications: remove misrouted generated alerts, regenerate alerts for current conditions under the agreed rules, and retain valid Stock Issue workflow updates. Identify the development target explicitly before mutation. Do not change sales, inventory, or Stock Issue records. Cleanup must be safe to repeat without duplicate current alerts.
- Preserve authorization, user ownership, CSRF protection, escaped content, and the existing accessible list and read controls.

## Testing Decisions

- Confirmed approach: extend existing notification browser coverage and existing service contracts. Prefer these established seams over new testing interfaces.
- Test externally observable recipient selection, delivery, Workspace visibility, unread counts, read mutations, and condition lifecycle. Avoid assertions that merely mirror internal SQL or private implementation details.
- Exercise the Notification page, bell, and read actions with disposable-database fixtures: each role, a user with multiple roles, a secondary-role-only recipient, role removal, disabled accounts, and another signed-in user's isolation.
- Retain existing category, All/Unread, Show more, read-state preservation, CSRF, and responsive accessibility checks where changed behavior touches them.
- Extend the Attention rule contract for the role split and escalation boundaries immediately below and at 24 hours and PHP 5,000. Confirm that an ordinary new pending report does not escalate.
- Use existing Stock Issue workflow contracts for new/resubmitted manager alerts and returned/approved/rejected reporting-Cashier updates.
- Extend existing daily-notification and inventory-alert fixtures for optional preferences, mandatory overrides, email-only recipients, out-of-stock transitions, expiry escalation boundaries, no remaining expired stock, unchanged reruns, resolution, escalation, and recurrence.
- Verify that development cleanup preserves valid workflow history and operational records, removes misrouted generated alerts, and remains safe on a second run.
- Use the existing injectable clock and disposable database patterns. Never run destructive fixture or cleanup checks against an unidentified configured Store database.

## Out of Scope

- Changing role permissions or giving Administrators inventory execution authority.
- New sales, payment, refund, shift, procurement, or inventory workflows.
- SMS, push notifications, a new messaging provider, or real-time email delivery.
- A broad notification UI redesign, unrelated refactoring, or speculative delivery settings.
- Production notification-history deletion or modification of sales, inventory, or Stock Issue records during development cleanup.
- Guaranteed immediate delivery for conditions detected only by a scheduled run.

## Further Notes

- Product decisions were confirmed through eleven discussion questions on 2026-10-21, including optional daily email. The user also confirmed the existing browser-test and service-contract seams before publication.
- This specification preserves the existing single-Store role-authority split and uses the project's Notification, Workspace, Stock Issue, Supplier Pullout, and role vocabulary.
- Code inspection confirmed partial role routing, broad low-stock recipients, technical training failures sent to operational roles, primary-role-only recipient queries, and a user-wide inbox without Workspace separation.
- The existing Attention rule contract passed during investigation. That verifies current behavior only; this feature has not been implemented or validated.
- Actual scheduler configuration and email delivery remain unverified. Thresholds establish eligibility; detection and delivery depend on the relevant workflow or successful scheduled run.
