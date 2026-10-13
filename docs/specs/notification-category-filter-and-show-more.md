# Notification category filter and Show more

Status: Confirmed and implemented.

## Scope

Update the Notifications page reached through the bell. Keep notifications scoped to the signed-in user and preserve existing authorization and CSRF protection. Notification delivery preferences remain separate.

## Agreed behavior

- Initially show the 10 newest notifications. Use a stable newest-first order for equal timestamps.
- Replace numbered pagination with Show more. Each click adds up to 10 matching notifications to the visible list; hide the control when none remain.
- Place a category dropdown above the list. Default to All and include only categories present in the signed-in user's notifications.
- Use existing categories: Low stock, Replenishment, Inventory adjustments, System, Fiscal periods, Expiring stock, Expired stock, and Attention.
- Changing category resets the visible list to the first 10 matching notifications.
- Show more retains the selected category.
- Marking an individual notification read preserves the selected category and expanded list.
- Reopening Notifications through its normal navigation starts at All with 10 items.
- Under a selected category, label the bulk action Mark category as read. It marks all notifications in that category read, including items not currently displayed.
- Under All, retain Mark all as read and apply it to all notifications belonging to the signed-in user.
- After a bulk action, preserve the selected category and expanded list. The bell's unread count continues to cover all categories; the list's unread count and bulk-action availability reflect the selected category.
- Support empty lists, fewer than 10 items, and category-specific empty states without showing Show more.

## Verification

Check initial limits, repeated expansion, stable ordering, category isolation, state preservation after read actions, and category-wide bulk read beyond the displayed items. Verify another user's notifications cannot be viewed or marked read. Check keyboard operation and narrow-screen layout using existing UI conventions.

## Documentation

Notification and Notification Category vocabulary is recorded in `docs/CONTEXT.md`. No ADR: these presentation choices are readily reversible.

## Verification results

Disposable-MySQL browser checks passed for the initial limit, repeated expansion, timestamp ties, category choices and isolation, read-state preservation, category-wide and all-category bulk read, another user's notification protection, and malformed query values. Existing responsive card checks passed at 320–1440px; desktop and mobile screenshots were reviewed. PHP lint passed for changed PHP files. The full repository suite was not run.

The existing Attention notification type/schema discrepancy remains outside this change. Attention is offered only when present in the user's stored notifications.
