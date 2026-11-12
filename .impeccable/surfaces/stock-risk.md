---
version: 1
slug: "stock-risk"
primary_target: "src/frontend/components/inventory_management/stock_risk.php"
related_targets: ["src/frontend/components/inventory_management/inventory_overview.php", "src/frontend/assets/css/stock-risk.css", "src/frontend/assets/js/stock-risk.js"]
---

# Stock Risk

## Mode and confirmed direction

Operate. The user selected Compare products (prototype A) for a new Stock Risk tab inside the existing Stock Overview, then explicitly requested colorful category icons and stronger table/UI presentation. Overview and Products remain host content, not redesign targets. No ABC or automatic counting schedule is included.

Keep RetailMind's system font, blue action voice, restrained borders, panel geometry and native light/dark themes. Local blue, orange, purple, teal, green and slate tints identify product/concern categories. Every icon has an adjacent label; hue never carries the only meaning. Icons reuse the installed Bootstrap Icons library. No dependency or global brand token was added.

## Composition and interaction

Lead with the Stock Risk heading and purpose, then a row of icon/category filter controls. The white comparison surface combines a compact toolbar, labeled records and pagination. Rows align stock/sales quantities, separate category/SKU metadata, and offer explicit expandable details. Product, stock and sales headers sort; filters/search reset pagination and close stale details.

Mobile reflows each table record into labeled quantities, its concern and a visible View action. A native sorting selector preserves sorting without requiring interaction with visually hidden headers. Details expose the historical stock-in/out calculation, basis and next step in place. No modal, count prompt or new inventory mutation is introduced. Motion is confined to detail reveal and respects reduced motion.

## Implementation and verification

See `docs/specs/inventory-stock-risk-consolidation.md` for the approved purpose, calculation limits and archived prototype source. Dedicated reader checks, inventory alert/recent-movement checks and the Overview browser check cover the integration. Captures at 1440px and 390px in both themes were inspected; mobile overflow and accidental visible screen-reader labels were corrected. Existing Overview behavior remains covered by the same browser check. A read-only local-database check loaded active product rows.

The design detector's font finding was a false positive for the existing Bootstrap icon font embedded in the browser fixture. A value-level ignore is scoped only to `src/backend/tests/inventory_overview_clarity_browser_test.js`; no real UI font rule was disabled. No deterministic design issues remain standing. A signed-in live browser session and the full theme/account suite have not been checked.
