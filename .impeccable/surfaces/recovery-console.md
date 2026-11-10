---
version: 1
slug: "recovery-console"
primary_target: "src/frontend/components/backup/recovery.php"
related_targets: ["src/frontend/assets/css/recovery.css"]
---

## Direction contract

THESIS: One calm recovery screen. The operator sees the Store's state, signs in with retained credentials, reviews verification, and reopens access with the fewest possible words.

OWN-WORLD: The change-password entrance: cool canvas, ambient blue glow over a dotted grid, one white 20px card with a blue-to-cyan top rule, the RetailMind logo above it, Action Blue primary controls, semantic state pills.

STORY: Store status → sign in → review automated checks and confirm the checklist → reopen the Store. Maintenance actions stay in native disclosures below the review.

FIRST VIEWPORT: Brand mark, then a single card holding the state pill, a short title, and the one action. The signed-in console keeps the same card and adds a two-column stage/next-action strip.

FORM: Local refinement of an existing operational screen. Tails the change-password visual world; no new brand, tokens, dependencies or images.

## Implementation and verification

### Redesign with clarify and distill — 2026-11-11

Mode: Operate. The page now shares the change-password canvas: brand mark, ambient glow, dot grid, one card with a gradient top rule, one status pill, and one short subtitle. Tailwind-style utility classes and the Tailwind emulation in `recovery.css` were replaced by `rc-*` components scoped to `.recovery-console`; `backup.css` is no longer loaded because nothing on this page used it. Every `data-recovery-*` hook, ASP.NET-style field ID, form action, CSRF field and confirmation string is unchanged, so the recovery browser tests address the same DOM.

Copy was cut to one line per task. The signed-in console replaces the old two-box stage grid with a compact stage/next-action strip, renders the four automated checks and both previews as tiles, and keeps each disclosure to a single summary line. Card width is 480px for idle/login and 760px for review. The warning panel keeps the destructive guidance, pending-check list, password field and single-active-Store confirmation. Reopening remains the only amber action; secondary actions stay quiet.

Verification: `npm run check` passes (stylesheet bundle, 117 view markup audit with 0 errors and 0 warnings, 113 smoke checks). PHP lint passes. Rendered the real page at 1440px and 390px in light and dark: no horizontal overflow, one status element per state, no console errors. The signed-in states were reviewed against a static mirror of the same markup at 1440px and 390px in both themes. The recovery browser flows were not run: they require a disposable database and put the local Store through replacement. No tokens, dependencies or images were added.
