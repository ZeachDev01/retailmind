# RetailMind on mobile and tablet

Install RetailMind on the device's home screen, then open it from that icon.
It opens in standalone app mode, without the browser's address bar. Sidebar
navigation and PHP reloads stay inside the app. The device's status bar may
remain visible.

## Install

- **Android (Chrome):** open RetailMind, open Chrome's menu, and choose
  **Add to Home screen** or **Install app**. Confirm, then launch the new
  RetailMind icon.
- **iPhone/iPad (Safari):** open RetailMind, tap **Share**, then **Add to Home
  Screen**. If **Open as Web App** appears, leave it enabled. Tap **Add**, then
  launch the new RetailMind icon.

If the device already has an old browser shortcut for RetailMind, remove that
shortcut and add it again after deploying this update. You may need to sign in
again inside the installed app.

## Hosting

Use HTTPS for installation on a phone or tablet. An ordinary HTTP LAN address
(for example, `http://192.168.1.10`) does not meet Chromium's installation
requirements. Localhost is allowed for development on the device running the
browser, but a phone accessing XAMPP on another computer needs HTTPS.

Deploy `src/frontend/manifest.webmanifest` alongside `index.php`, and deploy the
updated shared header at `src/backend/includes/theme.php`. The relative manifest
scope supports both a domain root and a subfolder installation. Keep the existing
icon assets at their current paths. No database migration is needed.

On InfinityFree, the manifest link must include `crossorigin="use-credentials"`.
Its browser-check cookie is needed to fetch the JSON manifest; without it, the
host returns an HTML challenge that Chrome reports as a manifest syntax error.
Deploy the updated `src/backend/includes/theme.php` and use
`https://retailmind.infinityfreeapp.com/` when installing. A shortcut that opens
in Chrome is different from an installed standalone app.

The app remains online-only; this change adds no service worker or offline
cache. Sidebar links use Fetch to load pages in an isolated workspace frame,
preserving native fullscreen in a normal browser tab. Deploy
`src/frontend/assets/js/sidebar-loader.js`, `src/frontend/assets/js/ui.js`, and
`src/frontend/components/sidebar.php` together. A full browser reload still exits
native fullscreen. The installed app's display mode is managed
by the browser/OS; the Full Screen button cannot turn it into a browser tab.

## Device check

1. Launch the installed icon and sign in.
2. Visit several sidebar pages, reload, and rotate the device.
3. Confirm that the address bar stays hidden and menus, scrolling, forms, and
   sign-out still work.

Automated checks validate the manifest, icons, shared metadata, and navigation
scope; they do not replace installation checks on physical Android/iOS devices.
