---
version: 1
slug: "remote-access"
primary_target: "src/frontend/components/system_administrator/system_settings.php"
related_targets: ["src/frontend/assets/css/platform-settings.css", "src/frontend/assets/js/remote-access-toggle.js"]
---

# Remote Access — 2026-11-10

Mode: Operate. Super Administrator preparing the Store PC for remote access and a defense demonstration. Apply clarify/distill within RetailMind's existing Working Stockroom system. The user requested separate ngrok Free and Cloudflare setup views, a visible public link, and an unmistakable running-provider label.

The first viewport separates installed provider, public reachability, enable control, and a persistent clickable/copyable public link. Viewing a different provider's setup never switches the service. Provider controls use a scoped button group with `aria-pressed`, native GET fallback, and hidden setup panels. The selected view survives normal navigation and Cloudflare save actions. Setup guides, notifications, and removal use native disclosures. Generated scripts stay visible after an explicit generation action; no token is redisplayed in the free token input.

The status badge says Online/Connecting/Offline; the installed-provider label remains independently visible. Saved links remain available while offline with an explicit unverified note. A verified public health response can establish Online even when the web process cannot query Windows service state. The page uses the existing versioned asset helper and clears stale error feedback after a successful status refresh.

No new global tokens, imagery, libraries, provider selection side effects, or service restart. Reuse existing CSRF/local governance gates, IDs and setup actions. New copy/control text uses the documented 0.95rem control step; titles use 1.05rem. Quiet hover, visible keyboard focus and reduced-motion behavior remain scoped to this surface.

Validation: `npm run check`, `npm run check:contracts`, focused state tests and the rendered production-section browser test. Browser checks cover 1440/1024/768/390px, light/dark, keyboard provider navigation, clipboard fallback, preserved provider/link while offline, recovery, and overflow. The real installed ngrok route also passed the XAMPP PHP HTTPS health test. Screenshots are in `.impeccable/review/remote-access/`. Full authenticated Chrome access was unavailable through Computer Use, so the browser test rendered the real section with isolated API responses and did not mutate the live service.

Design detector: no deterministic findings after a narrow `sfmono-regular` exception for the existing literal PowerShell textarea. Existing surrounding stylesheet advisory drift was left outside this task; no blanket rule or file suppression was added. Rendered views were inspected. Verdict: ready for user review; authenticated mobile-data login and reboot acceptance are separate deployment checks.

## Revision — clarify + distill, provider marks, status motion

The page heading subtitle was removed; the navigation already names every section. The Remote Access section now leads with one bordered connection console: installed provider name, live state, enable switch, and the public link with Copy and Test actions. The page-level subtitle, the "Installed provider" field label, the provider-tab hint, the "status refreshes automatically" note, both setup introductions, the free-plan and verification paragraphs, and the online link note are gone. Facts that change a decision moved into the existing disclosures: ngrok quota and browser warning, and Cloudflare's pre-switch verification.

Provider marks are inline SVG: the ngrok wordmark and the Cloudflare cloud, drawn in the panel title beside "Free setup" and "Custom domain". Marks use the vendor colors `#1f1e37` (white in dark theme) and `#f38020`; no palette token was added. Provider tabs stay text-only so the brand never repeats its own name in the same row.

Motion is scoped and authored once per state: a radar ring on the Online status dot, a breathe on Connecting, and a 220 ms panel rise when the selected setup view changes. Every animation stops under `prefers-reduced-motion: reduce`. Copy and the existing IDs, actions, and CSRF gates are unchanged.

Validation: `npm run check`, `npm run check:contracts`, and `remote_access_setup_browser_test.js` passed after the revision; screenshots were re-inspected at 1440/1024/768/390px in light and dark.
