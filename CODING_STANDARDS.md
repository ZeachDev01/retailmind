# RetailMind Coding Standards

These standards govern code review and architecture consistency across the RetailMind codebase. Read during review, not implementation.

## 1. Component Scoping & Event Collision (Vanilla JS)
- **Top-Level Tabs**: Tab listeners in workspace layouts must be scoped to their container (e.g. `workspace.querySelectorAll('.workspace-nav [role="tab"]')`). Never use unbounded `document.querySelectorAll('[role="tab"]')`.
- **Inner Segmented Switchers**: View-level toggle switches, coin pills, and filter buttons within a page or modal must use `role="group"` with `aria-pressed="true|false"` buttons, never nested `[role="tab"]`.
- **Modals & Drawers**: Event listeners for `keydown` (Escape key) or click-outside must attach only while the modal is open and detach on close, preventing background event clobbering.

## 2. Navigation & Workspace View Hygiene (PHP Views)
- **Single Source of Truth**: Consult [`docs/WORKSPACE_MAP.md`](docs/WORKSPACE_MAP.md) for role entry points, view paths, modals, and asset controllers.
- **Link Integrity**: Relative links in `href` attributes must point to valid existing files or use `app_url(...)`. Run `npm run check:views` to verify view link integrity.
- **Dynamic Product Links**: Link to product catalog using query parameter `products.php?q=<SKU>` to automatically activate catalog search.

## 3. Review & Audit Triage Discipline
- **Tier 1 (Functional Bugs & Security)**: Broken links, 404 paths, missing CSRF tokens, broken event listeners. Fix immediately.
- **Tier 2 (Accessibility & Semantics)**: Missing button types, mismatched label `for`/`id`, missing `aria-label` on icon buttons. Enforced via `npm run check:views`.
- **Tier 3 (Cosmetic Micro-lint)**: Spacing, attribute ordering. Do not block PRs or bundle into unrelated fixes.

## 4. Engineering Minimalism (Ponytail Discipline)
- Before creating a new helper, class, or service, check [`src/backend/includes/`](src/backend/includes/) and [`src/backend/app/Services/`](src/backend/app/Services/).
- Preserve user WIP in the root working tree; never run destructive git commands against it.
