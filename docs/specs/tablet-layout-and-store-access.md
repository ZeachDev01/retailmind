# Tablet layout and store access

Status: direction agreed through discussion, 2026-11-16. Synthesized into [the implementation specification](tablet-layout-and-store-access-spec.md). Implementation has not started.

Published as [GitHub issue #465](https://github.com/ZeachDev01/retailmind/issues/465), labelled `ready-for-agent`.

## Agreed requirements

- Administrator and Inventory Manager workspaces must remain structured on small landscape tablets, without stretched or overlapping controls.
- Preserve the existing visual identity and all workspace functions.
- Reflow panels and navigation to fit the available space. Important table information and actions must remain reachable; horizontal scrolling inside tables is acceptable.
- Tablets access the Store PC on the store Wi-Fi. Access from outside the store is not required.
- Routine access must not require finding the current IP with `ipconfig` or typing a numeric IP and application path.
- Provide a home-screen icon for routine access and a memorable address for initial access on another device.
- The user has router settings access.
- The target tablet is reported to be close to phone size and running an old OS; exact model, OS, and browser remain unknown.
- The user estimates the device is roughly iPad mini size; this does not identify its platform or browser viewport.
- The network uses PLDT Fibr; the router model and local DNS capabilities are not yet known.
- The user believes the tablet uses Chrome; its exact version remains unknown.
- Test the current browser first. Update the browser or OS if required and supported; use a newer device only if the existing device cannot support the complete functions. The user accepted this compatibility fallback.
- The user selected TestSprite for UI testing. Physical tablet and local network acceptance remain separate checks.

## Agreed direction and deployment constraints

- Adapt navigation, grids, tables, and overlays to available width and height rather than relying on a tablet device label. Preserve readable controls and reachable form actions, including with the on-screen keyboard open.
- Use representative small landscape viewports for initial checks, then validate on the actual tablet before claiming device compatibility.
- Keep the existing Store PC as the sole live installation. Configure stable LAN addressing, then verify a memorable local address and trusted HTTPS on the actual router and tablets. Do not assume PLDT Fibr identifies a router model or guarantees local DNS support.
- Add a home-screen shortcut after the final address works. A shortcut alone does not ensure stable addressing or encrypted draft recovery.
- If the old browser lacks required features, first check supported browser or OS updates. If those cannot provide complete functionality, use the agreed newer-device fallback; do not silently drop functions or security.

## Existing evidence

- `src/frontend/assets/css/responsive.css` switches navigation to drawer mode at widths of 900px or less. Wider landscape tablets can retain desktop navigation; this is a candidate cause, not a confirmed diagnosis on the user's device.
- `src/frontend/assets/css/tables.css` already supports horizontal table scrolling.
- `docs/verification/responsive-ui-verification.md` records emulated tablet checks, but physical device and on-screen keyboard acceptance remain incomplete.
- `docs/verification/STORE_PC_FUNCTIONAL_ACCEPTANCE.md` already calls for stable LAN addressing and staff bookmarks.
- `docs/guides/tablet-inventory-recovery.md` requires HTTPS for encrypted inventory draft recovery through a LAN address or hostname. Plain LAN HTTP does not meet the requirement to preserve every function.

## Facts to verify during implementation and acceptance

- Actual tablet model, operating system, and browser, and a representative failing page.
- Router model and available configuration for stable addressing and local name resolution; administration access is confirmed.
- Whether the old tablet browser can support all existing functions, including encrypted inventory draft recovery; apply the agreed compatibility fallback if it cannot.
- A trusted HTTPS arrangement compatible with the actual tablets and local network.
- Acceptance coverage for landscape height, on-screen keyboard, dialogs, tables, and core workspace actions.

The implementation specification records the consolidated plan and TestSprite testing approach. Verify the network-specific access mechanism during setup rather than assuming router capabilities. No ADR or glossary addition is needed for these reversible layout and deployment refinements.
