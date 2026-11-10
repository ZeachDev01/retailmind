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
