---
version: 1
slug: "workspace-help"
primary_target: "src/frontend/components/auth/help.php"
related_targets: ["src/frontend/components/auth/workspace_overview.php", "src/frontend/assets/css/workspace-help.css"]
---

# Workspace Help

## Guidance dismissal — 2026-11-02

Overview actions save in place, then replace the preferences footer (or automatic workspace overview) with a persistent success message: “Need help later? Open your profile menu and choose Help.” Focus moves to the message. Reuse the existing success palette and focus treatment, with 2rem above the Help confirmation.

Motion: 150 ms opacity exit, 220 ms arrival; reduced motion uses 80 ms opacity feedback. Controls disable while saving; failures retain them for retry. Ordinary POST navigation remains available without JavaScript. No new dependency or asset.

**Verdict: Ship.** Focused browser checks passed both roles, all three choices, keyboard focus, failed-save recovery, reduced motion, unchanged inventory and JavaScript-disabled fallback. The theme/viewport matrix passed at 1440, 1024, 390 and 320px. Administrator guidance checks passed. Desktop and narrow success renders were inspected. Broader onboarding remains limited by the existing fixture query for nonexistent `full_name`.

Mode: Read. Administrator and Inventory Manager guidance. Preserve all workflow explanations, destinations, role permissions, setup confirmations, CSRF protection and guidance preferences. User approved the task-first guide left / setup right composition.

## Direction contract

THESIS: A readable workspace field guide with Store readiness alongside it, replacing the undifferentiated long block.

OWN-WORLD: Inherit RetailMind's navy, action blue, theme-aware neutral surfaces, system type and restrained borders. No new global visual identity.

STORY: Find the relevant workflow, understand its boundaries, then open it. Independently review and confirm Store setup with the permitted role.

FIRST VIEWPORT: Workspace Help heading and return action above a wide editorial workflow list and a narrower setup panel. Workflow headings precede explanations and destination links. Setup status and role ownership are visible alongside the first workflow. Mobile stacks guide then setup. Overview preferences close the page.

FORM: User-pinned two-column reading composition; code-led, no concept roll required for the approved structure.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

## Implemented surface

The approved composition is complete: role-specific action rows combine existing navigation icons, concise destination labels and directional arrows; native “How it works” disclosures retain each full explanation and its workflow boundaries. The existing Store setup checklist occupies the right column. Overview preferences follow both sections. At 980px and below the guide precedes setup in one column; at 480px and below the overview actions stack at full width. The right column is 340px on wide screens and 300px at the 1180px breakpoint.

This is an ordinary surface redesign within **The Working Stockroom**. It retains the incumbent navigation shell, system-UI typography, Action Blue controls and focus, theme-aware neutral surfaces, quiet borders and 14px panels. Help-specific spacing and workflow dividers improve reading hierarchy without introducing global tokens, a new component system or a new visual world. Existing workflow content, destinations, role ownership and confirmation forms remain shared; Help alone receives the expanded workflow markup and stylesheet.

## Review evidence and verdict

- **Verdict: Ship.** The final reviewer completed all five review sections with no material UI findings; the documentation condition is satisfied by this brief and the narrow Workspace Help note in `docs/DESIGN.md`.
- The focused Help browser test passed for Administrator and Inventory Manager, light and dark themes, and widths of 1440, 1024, 390 and 320px. It checked layout/overflow, keyboard focus, action targets, overview POST behavior and preservation of the Inventory Manager workflow.
- The 16 full-page captures are `.impeccable/review/{admin,inventory_manager}-{light,dark}-{1440,1024,390,320}.png`. These are local review evidence, not shipping raster assets. Documentation review directly inspected Administrator/light/1440, Inventory Manager/dark/1440, Administrator/dark/320 and Inventory Manager/light/390 alongside the implemented PHP/CSS and incumbent product/design documents.
- Screenshots show pending setup (0 of 2 confirmations), permitted role actions and the other role's awaiting state. Actual setup success/error confirmation states were not covered because the broader suite failed; their existing markup and handling were preserved. The focused result is not a claim that the broader suite passed.
- No shipping raster assets were introduced. No global token or sidecar regeneration was needed. No pre-existing visual drift requiring repair was established by this surface review; unrelated system files remain outside scope.

## Refinement — 2026-11-02

User requested distill, clarify, bolder and colorize. Short task summaries now lead; native disclosures preserve the complete guidance without adding JavaScript. Repeated Help reminders are consolidated beside setup and overview actions. Larger page/workflow headings strengthen hierarchy; Entrance Navy anchors the checklist header, with blue links and theme-aware reading surfaces. No workflow, permission, destination or POST handler changed. Other workspace guidance keeps its existing presentation.

**Verdict: Ship.** In-thread visual review inspected Administrator light/dark desktop and Inventory Manager dark narrow/mobile-expanded light renders. The focused browser test passed both roles and themes at 1440, 1024, 390 and 320px, including keyboard disclosure, focus, 44px action heights, overflow, computed text contrast of at least 4.5:1 for the targeted links/disclosures/checklist header, overview persistence and unchanged inventory. Four additional `*-expanded.png` captures show open mobile guidance. PHP syntax and `git diff --check` passed. Confirmation success/error paths and the broader suite were not rerun; existing handling is unchanged. No shipping imagery or dependencies were added.

## Icon-led redesign — 2026-11-02

User requested icons and shapes that emphasize function, with concise text and unnecessary subtext removed. The layout now uses one linked action per workflow, paired with the existing navigation SVG in a square tile and a directional arrow. Circular icon wells distinguish the setup task headings. Page introduction, workflow summaries, Help-only checklist descriptions and duplicate restricted-task notes are removed; role ownership, pending/confirmed states, confirmation controls and expandable workflow explanations remain. Other workspace onboarding retains its descriptions. This is a code-led change within the existing visual world and two-column composition.

**Verdict: Ship.** In-thread review inspected Administrator/light/1440, Inventory Manager/dark/320 and expanded Inventory Manager/light/390. The focused Help matrix passed both roles, both themes and all four widths, including loading all function/setup icons, no redundant action subtext, keyboard disclosure, contrast, focus/targets, saving preferences and unchanged inventory. The Administrator workflow guidance browser test also passed with assertions adapted to expand the explanations. The broader workspace onboarding test was attempted but stopped in `support/theme_fixture.php:209` because its query references the nonexistent `full_name` column; that fixture was not changed. PHP/JavaScript syntax and diff whitespace checks passed. No dependencies, new image assets or global style tokens were added.


## Walkthrough UI repair — 2026-11-02

Current working-tree Help intentionally removes the legacy workflow disclosures, setup checklist and overview preferences. Preserve that user work. This refinement follows the current role-specific walkthrough content and behavior; earlier checklist composition notes above describe the superseded surface.

Mode: Read. The outer panel and nested topic card borders are replaced by task groups with divided rows. At 1180px and above each introduction sits beside its topic list; smaller layouts stack the introduction. Actions stack below topics at 760px and become full-width at 480px. Grid rows remove the shared flex-basis that previously stretched mobile topics. Explicit primary/secondary button foregrounds fix the shared link rule that hid blue button labels in light mode. Inventory Manager topics reuse the existing topic-icon map. Section headings now follow the workspace heading in semantic order. All changes remain Help-scoped; shared onboarding behavior and POST handlers are preserved.

**Verdict: Ship.** The focused browser test passed Administrator and Inventory Manager in both themes at 1440, 1024, 390 and 320px. Regression assertions cover button contrast of at least 4.5:1, compact rows, function-specific icons, focus, 44px targets and overflow. Start/Continue and completed/Replay states passed in both themes at 1440 and 320px using a disposable database; inventory remained unchanged. Visual review inspected desktop light, intermediate dark, mobile dark, active desktop/mobile and completed mobile light captures. Representative evidence: `.impeccable/review/help-ui/`. PHP/JavaScript syntax and diff whitespace checks passed. The broader onboarding/walkthrough suites were not rerun; several still assert the legacy sections already removed by the user's working-tree changes. No dependency or shipping image assets were added.

## First-visit guidance distill — 2026-11-07

User asked to distill the automatic first-visit guidance block (the reminder shown above workspace pages until Store setup completes or a choice is saved). Scope: `components/auth/workspace_overview.php` only. The fallback (non-workflow page) branch listed a chip for every walkthrough topic — nine links on Administrator pages. It now shows the page lead, one primary destination ("Review Store operations" / "Review replenishment needs") and "Explore Workspace guidance" (Help holds the full, role-filtered directory). The lead sentence keeps orientation and drops the redundant "Start with…" clause that duplicated the primary link. The optional note is trimmed to one line. The contextual branch (pages that map to a workflow), the checklist, the three persistent choices ("Finish overview", "Skip guidance", "Not now"), CSRF protection, ids and POST handling are unchanged.

**Verdict: Ship.** `php -l` clean. Rendered on a disposable database through `gcash_overview.php` at 1440px and 390px: one lead line, two links and one note, no page overflow, no page errors; desktop and mobile captures inspected. The onboarding browser test reached and passed its guidance-block assertions (heading, all three exact button names, keyboard order, focus, overflow) before failing later on the pre-existing `.help-layout` selector, which no longer exists after the working-tree Help refactor — unrelated to this change. No dependencies, tokens or image assets added.
