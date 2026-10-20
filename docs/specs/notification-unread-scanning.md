# Notification unread scanning

Status: Confirmed and implemented, 2026-10-18.

## Purpose and direction

Help signed-in Store staff find unread Notifications faster. Refine the existing
RetailMind workspace, navy/blue theme, and single-list layout. Notification and
Notification Category retain their definitions in `../CONTEXT.md`: marking a
Notification read does not resolve its underlying condition.

## Agreed behavior

- Add All and Unread views. All remains the default when opening Notifications
  through normal navigation. Both views retain stable newest-first ordering.
- Combine the selected view with the existing category filter. Keep category
  choices available when their unread count reaches zero.
- Changing view or category resets the list to the first 10 matching items.
  Show more adds up to 10 matching items and retains both filters.
- Reading remains explicit. Viewing, filtering, or expanding the list never marks
  Notifications read.
- Keep a quieter, text-labeled Mark as read button on each unread row. After marking
  it read, preserve the view, category, and expansion; it disappears in Unread.
  Clamp expansion to the remaining results when necessary.
- Preserve bulk-action scope: Mark all as read covers the signed-in user's unread
  Notifications across all categories; Mark category as read covers unread
  Notifications in the selected category, including undisplayed items. Preserve
  filters after either action.
- Keep the page unread count scoped to the selected category; the bell continues
  to count all of the user's unread Notifications. Showing X of Y reflects both
  filters. Hide Show more when no further matching items remain.
- After the last matching unread item is marked read, show a caught-up message
  and a way to return to All while retaining the category. Keep the existing
  no-Notifications state distinct from an empty Unread view.

## Layout and accessibility

- Keep the title, a compact toolbar, one notification list, and Show more footer.
  Make All/Unread selection clear alongside category filtering and bulk read.
- Give unread titles stronger emphasis and read rows quieter styling. Retain the
  explicit Unread label so state does not depend on color alone.
- Put titles and messages first in the visual hierarchy. Keep category and date
  readable but secondary; reduce the prominence of repeated read buttons.
- On narrow screens, wrap metadata and actions without horizontal scrolling or
  sacrificing the existing 44px minimum control height.
- Reuse theme tokens, visible focus, native GET navigation and POST actions.
  Support light/dark themes, keyboard use, and long messages.

## Implementation boundary and verification

Implemented scope: `src/frontend/components/notification/notifications.php`,
`src/frontend/assets/css/notifications.css`, and existing notification browser
tests/fixtures as needed. Preserve user ownership, authorization, CSRF, escaped
content, delivery, stored messages, and the shared workspace.

Extend existing checks for combined view/category filters, invalid filter values,
expansion, empty Unread results, individual/bulk read scope and redirects, and
account isolation. Inspect populated desktop/mobile and dark-theme fixtures;
check keyboard focus, contrast, long content, and control sizes. No new dependency
or database schema change is expected.

This extends `notification-category-filter-and-show-more.md`. The previous audit
in `../notifications-ui-audit.md` documents historical evidence, not verification
of this change. No new domain term or ADR is needed for these reversible
presentation choices.

## Verification results

- PHP lint, JavaScript syntax, and diff whitespace checks passed.
- `RUN_DB_TESTS=1 node src/backend/tests/notification_list_browser_test.js` passed
  against a disposable MySQL database, including combined filters, expansion,
  read redirects and empty states, bulk scope, malformed inputs, CSRF, and account
  isolation. Updated its stale dashboard navigation selector and reused the login
  fixture's startup-focus wait; existing cross-surface checks remain intact.
- `node src/backend/tests/notification_responsive_browser_test.js` passed at
  320, 375, 425, 768, 1024, and 1440px with ordinary and long messages.
- Inspected populated desktop/mobile and dark screenshots in two bounded rounds.
  Corrected the dark active-view label contrast; selected/unselected view labels
  and timestamps now have measured contrast checks in both themes. Controls retain
  44px minimum height and visible keyboard focus.
- Impeccable detector ran once and returned no findings. Screenshots are in
  `.impeccable/review/notifications-unread/`.

The full repository suite, screen readers, and physical touch devices were not
tested. Browser checks used fixtures; live Store data was not changed.
