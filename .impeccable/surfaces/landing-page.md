---
version: 1
slug: "landing-page"
primary_target: "src/frontend/index.php"
related_targets: ["src/frontend/assets/css/landing.css", "src/frontend/assets/js/theme.js", "src/frontend/assets/js/landing-motion.js"]
---

# Public landing page

## Overdrive — living store record — 2026-11-10

User asked to overdrive the public entrance and to make the light/dark switch feel like water spreading. Direction chosen by the user: **Living store record** — animate one sale through the existing four-stage operation panel instead of adding new product claims or a second visual world.

Mode: Persuade.

**Implemented**
- The hero panel now cycles Sale → Inventory → Planning → Purchasing while in view. A stage label in the header reads Scanning, Updating stock, Reading demand or Receiving; the active row carries a blue node, a filling meter and a matching label colour. The cycle pauses off screen, in a hidden tab, and under reduced motion (panel stays on “Ready”).
- A scanner pass sweeps the hero photograph only during the Sale stage.
- The photograph drifts a few pixels across the first 620px of page scroll.
- Section headings, the lead inventory card, capability cards, workflow steps and the access panel reveal as they enter the viewport, using CSS scroll-driven `view()` timelines; browsers without support render them static.
- A faint ledger grid sits behind the hero copy.
- A kicker line ("Shalom Store · Store operations") anchors the hero without changing the headline.
- Desktop and mobile header controls tightened so the theme selector and Staff login stay on one line at 390px.

**Liquid theme reveal**
- Choosing Light, Dark or System on the landing page starts a same-document View Transition whose new snapshot is clipped by an organic polygon centred on the chosen control. Twelve frames (ten on compact screens) expand a 64-point (40-point) front over 1050 ms with two harmonic terms and a cubic-out spread.
- Systems without the View Transitions API or Web Animations pseudo-element support get the CSS `circle()` fallback; `prefers-reduced-motion` swaps the theme instantly. Authenticated workspaces keep their instant theme swap.
- No new dependency, token, image or external asset was added.

**Verdict: Ship.** `npm run check` passed (stylesheet bundle, 0 view warnings, 113 smoke checks) and `npm run check:contracts` passed. Chromium checks found no page or console errors, no horizontal overflow from 320px to 1440px, the reduced-motion panel static at “Ready”, and the flow cycling only while visible. Contrast measured 7.24:1 and 7.56:1 for the kicker, 5.24:1 and 12.72:1 for the live state label, and 6.41:1 and 10.75:1 for the active stage label in light and dark. Reviews inspected: hero light/dark desktop and mobile, live-stage captures, four water-front frames, scrolled capability/workflow/access sections, print media, tall viewports, and the mobile header. The pre-existing `theme_login_browser_test.js` mobile navigation assertion still fails before this surface is reached (it expects visible section links at 390px while the incumbent stylesheet hides them at 768px); unrelated to this change and not repaired here.

Review captures: `.impeccable/review/landing-overdrive/`.
