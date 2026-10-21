# Workspace navigation icons

Status: approved and implemented. Colored SVGs replace workspace navigation icons across all four roles.

## Confirmed scope

- Use consistent colored SVG icons across all four workspaces: Super Administrator, Administrator, Inventory Manager, and Cashier. The user's latest clarification replaces the earlier outline preference.
- Cover sidebar links, nested menus, workspace navigation, and top navigation controls.
- Preserve page labels, routes, layout, permissions, and current navigation behavior.
- Retain the existing GCash brandmark. Its recognizable colored appearance is the visual reference.

## Approved direction

Primary source: the free MIT-licensed Icons8 Flat Color Icons repository, rather than the separate paid Icons8 catalog. Store selected SVGs locally and include their license. Use simple filled objects with a restrained multicolor palette and an approximately 22px icon footprint. Choose recognizable objects for each task; retain text labels and accessible names. Decorative icons remain hidden from assistive technology.

Check the actual SVG silhouettes, edges, and internal details on both the light and dark navigation backgrounds, including active and hover states. Color alone does not guarantee visibility. Adjust colors or use a subtle theme-aware backing only where inspection shows it is needed. Preserve original GCash colors. Do not apply a monochrome CSS filter to the SVGs. Familiar close and notification geometry is retained with colored fills; fullscreen and logout use blue SVG versions from the existing Bootstrap icon family. Sources and licenses live beside the assets.

Lucide outlines are superseded. Microsoft's Fluent color variants were considered, but the upstream SVG package marks them deprecated, so they are not the preferred source for this new rollout.

| Page or group | Local SVG asset |
| --- | --- |
| Control Center; Store Overview; Dashboard Overview | `services`; `shop`; `sales_performance` |
| Platform Administration; Users & Access | `privacy` |
| Store Administration; Store Staff; Registers | `business`; `conference_call`; `self_service_kiosk` |
| Protected Audit Records; Operational Audit | `inspection` |
| Recovery Account; System Health; ML Operation | `safe`; `support`; `process` |
| Reset Data; Backup & Restore; Database Backup | `delete_database`; `data_recovery`; `data_backup` |
| Platform Settings; Store Settings; Inventory Control | `data_configuration`; `settings`; `settings` |
| Store Calendar; Fiscal Periods | `calendar`; `planner` |
| Receipt History; Transaction History | `document`; `timeline` |
| GCash; GCash Overview | Existing GCash brandmark |
| Promotions; Cashier Shift; Cashier Shifts | `advertising`; `overtime`; `overtime` |
| Stock issue reporting, reviews, and oversight | `high_priority` |
| Store Reports; Reports; Report Generation | `statistics` |
| Receiving History; Stock Receiving | `filing_cabinet`; `shipped` |
| Forecast Analytics; Analytics; Demand Forecast | `combo_chart`; `combo_chart`; `positive_dynamic` |
| Forecast Exceptions; Data Readiness; Insights & Risk | `disclaimer`; `accept_database`; `idea` |
| Stock Overview; Products; Replenishment Recommendations | `deployment`; `package`; `workflow` |
| Inventory Counts; Supplier Pullouts | `survey`; `export` |
| Point of Sale; Cash Refunds | `self_service_kiosk`; `money_transfer` |
| Shared navigation controls | `search`, `notification`, `contacts`, `grid`, `settings`, `expand`, `collapse`, `external`, `menu`, `close`, `next`, `right` |
| Appearance: Light; Dark; System | `idea`; `night_landscape`; `display` |

Some specialized retail tasks use the closest available symbol; text labels remain essential. Assets live in `src/frontend/assets/img/navigation/`, with sources and licenses pinned locally.

## Connected implementation and checks

The shared `src/frontend/components/sidebar.php` renders navigation for every role. Its definitions and renderers now use local SVG assets, with sizing rules in `sidebar.css` and `style.css`, connected icon handling in `ui.js`, and appearance controls in `theme.js`.

Command search clones each navigation icon, preserving SVGs and the GCash brandmark; product results use the package SVG. Mobile-menu and fullscreen icons update with their state changes. Existing sidebar-collapse behavior and aligned icon sizing are preserved.

Verification: the four-role sidebar theme browser test checks SVG loading and decorative accessibility attributes, command search including GCash, collapsed sizing, mobile drawer state, fullscreen icon state, and Light/Dark/System persistence. Desktop light/dark and mobile screenshots are saved under `output/navbar-icons/`. The separate fetch-navigation regression test passed. PHP and JavaScript syntax checks and SVG XML/reference checks passed. Visual review covered both themes and mobile navigation; hover-specific contrast and assistive-technology behavior were not separately audited.

Keep page-content icons and unrelated Bootstrap icon usage outside this change. No new runtime dependency or CDN is needed. No domain vocabulary changes or ADR are warranted for this reversible visual choice.
