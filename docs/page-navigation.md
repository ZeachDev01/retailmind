# Persistent dashboard navigation

Signed-in pages still use their PHP entry points and existing authentication,
authorization, CSRF checks, and redirects. `includes/page_navigation.php` buffers
HTML responses from these routes. The shared sidebar marks the persistent shell;
requests with `X-RetailMind-Navigation: 1` receive page HTML and current session UI
metadata as JSON, excluding the shell and its scripts. API and download responses
without these markers pass through unchanged.

`assets/js/page-navigation.js` keeps the sidebar, global header, account menu,
theme controls, and fullscreen document alive. It replaces `.main-content`
(including older pages that use a div), accompanying dialogs, and page styles.
Existing PHP URLs, query strings, Back/Forward, native GET/POST forms, CSRF fields,
submit buttons, and multipart uploads are supported. Existing AJAX handlers get
first chance to handle their events. Downloads remain downloads. Login gates,
logout, and workspace changes use full navigation because the session shell changes.

Page scripts run again, with inline lexical declarations scoped for repeat visits.
Deferred scripts and DOM-ready handlers run after content is mounted. Page-owned
event listeners and timers are removed before the next page is mounted; charts,
DataTables, and camera streams are cleaned up. Shared libraries load once. A page
that needs additional resource cleanup can register a `pagehide` handler.
Use `RetailMindUI.navigate(url)` for programmatic navigation; native
`window.location` navigation destroys fullscreen. External links, new-tab links,
hash links, and links with `data-no-navigation` retain normal browser behavior.

Run the navigation regression without a database:

```powershell
node src/backend/tests/page_navigation_browser_test.js
$env:NAVIGATION_CHANNEL='msedge'; node src/backend/tests/page_navigation_browser_test.js
# Run separately without NAVIGATION_CHANNEL to include Firefox:
$env:NAVIGATION_CHANNEL=$null
$env:NAVIGATION_FIREFOX='1'; node src/backend/tests/page_navigation_browser_test.js
```

The regression uses PHP's actual response filter and browser tests for persistent
DOM/fullscreen, repeat visits, scripts, styles, dialogs, history, forms, uploads,
existing AJAX handlers, downloads, competing loads, errors, and login redirects.
