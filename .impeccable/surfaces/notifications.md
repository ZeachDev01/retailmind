---
version: 1
slug: notifications
primary_target: src/frontend/components/notification/notifications.php
related_targets: [src/frontend/assets/css/notifications.css]
---

# Notifications

Mode: Operate. Audience: signed-in Store staff reviewing their own updates.

Direction: retain RetailMind's existing workspace; simplify notification scanning through one toolbar, readable categories, explicit unread state, and one list with quiet separators. Keep filtering, read actions, ownership, CSRF and Show more contracts intact.

Updated 2026-10-18: All/Unread navigation combines with category filtering; All remains default. Unread titles carry stronger weight, read actions are quiet text-labeled buttons, and dates share category metadata. Explicit read actions remove rows from Unread; its caught-up state links back to All. Bulk actions retain category-wide scope. Confirmed brief and current verification: `docs/specs/notification-unread-scanning.md`; current screenshots: `.impeccable/review/notifications-unread/`.

First viewport: title and short instruction, category filter, unread count and bulk action, then recent updates. Mobile stacks toolbar groups and row actions. Theme variables carry both light and dark states; all controls have 44px minimum height.

Documentation comparison: existing docs/PRODUCT.md and docs/DESIGN.md remain authoritative. System type stack, 1.8rem page title, 1.05rem item title, 1rem body/metadata, 14px panel radius and 10px controls reuse documented values. Blue identifies filtering; neutral controls identify read actions. Explicit Unread text avoids color-only meaning. No global design changes or shipping rasters.

Finished 2026-10-14. Syntax and disposable-database browser regression checks passed. Desktop/mobile/dark fixture screenshots inspected; evidence in `.impeccable/review/notifications-after/`. Detector's metadata type-ramp advisories corrected. Detailed findings and verification limits: `docs/notifications-ui-audit.md`. Verdict: ready within tested Chromium coverage; live user session, physical touch, and screen-reader checks remain untested.
