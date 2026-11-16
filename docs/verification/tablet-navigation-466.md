# Ticket 466 verification

Navigation keeps expanded groups while scrolling and sits below the fixed mobile top bar. Its keyboard handler exists only while the drawer is open, contains Tab/Shift+Tab, and restores focus on close. Desktop collapsed flyouts still close on rail scroll. Existing 900px breakpoint remains: overview geometry did not justify changing it.

## Observed red / green

`RUN_DB_TESTS=1 node src/backend/tests/touch_navigation_reflow_browser_test.js` uses a separate PHP application server and a random disposable MySQL database, created and dropped by the existing theme fixture. No Store records were used.

Before fixes: Tab from the sidebar footer escaped navigation at 640x360. Expanded Forecasting destinations disappeared when navigation scrolled. After retaining groups, Data Readiness scrolled behind the fixed top bar (y=22.19px). The drawer now starts below that bar and fits the remaining height.

Supplemental Chromium passed for Administrator, Inventory Manager, and Cashier at 640x360, 800x480, 900x600, 901x600, 960x600, 1024x768, 1280x800, and 1366x768. Checks cover login, overview page overflow, touch opening, destination target size and scroll reachability, keyboard focus/Escape, Workspace option geometry, and retained command-search text across viewport changes. Artifacts: `C:/Users/Mike/AppData/Local/Temp/rm-touch-nav-XGiza4`. Screenshots and geometry are emulated browser evidence only.

PHP syntax checks and `npm run check:contracts` passed. `npm run check` passed bundle/views (one existing duplicate `open-register` ID warning), then failed existing smoke needle `Cashier Shift opening is owned by the Cashier workspace: missing $service->availableRegisters()`.

## Pending acceptance

No TestSprite pass is claimed here. Primary run IDs, dashboard URLs, actual viewport evidence, and populated/empty/long-content coverage remain with the parent verification task. Physical tablet, keyboard, and network acceptance remain unverified. The browser test exercises the existing populated overview fixture and navigation, not every business page or authorized form submission.
