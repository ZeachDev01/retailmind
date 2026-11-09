# One-paste Remote Access setup on the Store PC

## Problem Statement

RetailMind runs on one Windows Store PC with XAMPP already installed, and the application source and database are already in place. Enabling Remote Access still requires the operator to locate the tunnel installer, open an elevated PowerShell, paste the Cloudflare tunnel token, and know which script to run. The Super Administrator already holds the token in the Remote Access card, but has no single path from that card to a running, boot-persistent tunnel. A second machine is not part of the deployment: this Store PC is the sole live host.

The owner has no domain yet. A permanent named Cloudflare Tunnel cannot be created without a Cloudflare zone, so an interim Quick Tunnel is required until a domain is purchased.

## Solution

Extend the existing Remote Access card in Platform Settings with a **Generate Store PC setup block** action. The generated block is a self-contained PowerShell snippet that the Super Administrator pastes once into an elevated PowerShell on the same Store PC. The block runs pre-flight checks, provisions the portable `cloudflared` binary, persists the tunnel token, registers the RetailMind tunnel Windows Service with automatic startup and crash recovery, starts it, and prints the resulting state.

When a permanent token is saved, the block provisions the named tunnel. When no token is saved, the block provisions a Quick Tunnel instead and clearly labels it as interim, URL-changing, and without a Cloudflare Access gate. Saving a token later and regenerating the block upgrades the same PC without source changes.

The card also shows a short Cloudflare dashboard checklist and a matching removal block. Generation is local-only, capability-gated, CSRF-protected, and audit-logged; the raw token is never rendered until the Super Administrator explicitly generates a block.

## User Stories

1. As the Super Administrator, I want to open one card and get one setup block, so that I do not need to locate scripts or reconstruct commands.
2. As the Super Administrator, I want to copy the block into an elevated PowerShell once, so that Remote Access is running without editing files.
3. As the Super Administrator, I want the block to install `cloudflared` itself, so that the Store PC does not depend on a pre-installed binary.
4. As the Super Administrator, I want the tunnel registered as a Windows Service, so that Remote Access returns after a reboot or power outage without a Windows login.
5. As the Super Administrator, I want the service to restart after a crash, so that a transient failure does not require a store visit.
6. As the Super Administrator, I want the block to fail fast when the local application is not reachable, so that I do not publish a tunnel to a dead local server.
7. As the Super Administrator, I want the block to remove the legacy Quick Tunnel scheduled task when a permanent service is installed, so that two tunnels never fight over the same route.
8. As the Super Administrator, I want to re-run the same block safely, so that I can repair a broken service or apply a changed token.
9. As the Super Administrator, I want an interim Quick Tunnel block while no domain exists, so that I can use remote monitoring now.
10. As the Super Administrator, I want the interim block labeled with its limits, so that I do not mistake a changing URL for a stable address.
11. As the Super Administrator, I want a checklist for the Cloudflare dashboard steps, so that tunnel creation, hostname, and Access policy are not guessed.
12. As the Super Administrator, I want a removal block in the same card, so that I can cleanly disable Remote Access or move to another PC.
13. As the Super Administrator, I want the token hidden until I generate a block, so that it is not exposed on a passively viewed screen.
14. As the Super Administrator, I want every generation recorded, so that token exposure has an accountable owner.
15. As the Store owner, I want the setup to use the existing token and hostname fields, so that buying a domain later requires no rework.
16. As the Super Administrator, I want the block to embed the resolved application root and local port, so that I do not edit deployment paths by hand.
17. As the Super Administrator, I want a clear message when I paste the block into a non-elevated shell, so that I know to reopen PowerShell as Administrator.
18. As the Super Administrator, I want the same service name in Quick and permanent modes, so that switching modes does not leave two services behind.
19. As a Cashier, I want the setup change to leave local checkout untouched, so that remote access work cannot alter sales behavior.
20. As the Store owner, I want remote access to remain unavailable when the PC, local services, or internet is down, so that the setup does not imply cloud hosting.

## Implementation Decisions

- **Surface:** Extend the existing Remote Access section of Platform Settings. Do not add a navigation entry or a new page.
- **Authorization and locality:** Reuse the existing platform-governance capability and local-request requirement. Generation is a CSRF-protected POST action. The raw token is read only at generation time and is masked in the default view.
- **Block builder:** Put block construction in a small, pure builder module that returns the PowerShell text from explicit inputs: application root, local port, token, hostname, and mode. Keeping it pure lets tests assert the generated artifact without executing PowerShell.
- **Reuse the existing installer:** The generated block is a thin wrapper that runs pre-flight checks, then invokes the existing tunnel installer and removal script. Do not fork a second service installer. Extend the existing installer with a Quick Tunnel mode rather than adding a parallel script.
- **Pre-flight:** Require an elevated PowerShell, 64-bit Windows, internet access, and a reachable local application origin on the configured port. Report, rather than silently ignore, an existing service or legacy scheduled task.
- **Persistence:** The Windows Service uses automatic startup and `sc.exe` failure restart actions. The token is persisted through the existing tunnel configuration path so the application status card stays accurate.
- **Idempotency:** A re-run stops, deletes, and recreates the service, matching current installer behavior. Re-running is the supported repair path.
- **Mode selection:** A saved token plus hostname yields a permanent named-tunnel block. No saved token yields an interim Quick Tunnel block. Both modes share the same service name and local origin check.
- **Interim labeling:** The Quick Tunnel block and the card state must state that the URL changes on restart and that Cloudflare Access is not in front of it. RetailMind sign-in and the application read-only boundary remain in force.
- **Cloudflare checklist:** The card lists creating a tunnel, adding a public hostname (requires an owned domain), creating the owner-only Access policy, and copying the tunnel token. No Cloudflare API automation and no domain purchase are included.
- **App root:** Because the Super Administrator views the card on the same Store PC, the block embeds the resolved application root and configured local port; the operator does not edit paths.
- **Removal:** The card exposes a removal block that stops and deletes the service and removes the legacy scheduled task. It does not delete the application, database, or backups.
- **Audit:** Each generation writes one activity record naming the actor and the selected mode. The record excludes the token and the generated block.
- **No scope creep:** The setup block does not install XAMPP, Apache, PHP, or MySQL, does not import the database, and does not copy source files. Those remain manual preparation steps completed before setup.
- **Seam:** The primary test seam is the rendered Remote Access card and the generated block text, exercised through the existing PHP contract-test pattern. PowerShell service behavior is verified by a manual Windows acceptance checklist because CI cannot install Windows services.

## Testing Decisions

- **What makes a good test:** Assert observable artifacts and state transitions, not private helper calls or script internals. The generated block is the artifact; its mode, embedded root, port, service commands, and token placement are externally visible behavior.
- **Card contract tests:** Assert that the token is masked by default, that generation requires local request, platform governance, and CSRF, and that a successful generation renders the block and an audit record without exposing the token elsewhere.
- **Builder tests:** Cover permanent mode, Quick mode, blank token, hostname present without a token, and path or port values that need quoting. Assert that the token appears only inside the generated command and never in the audit payload.
- **Mode tests:** Assert that saving a token switches subsequent generation to the permanent block and that clearing it returns to the Quick Tunnel block.
- **Prior art:** Reuse the existing Cloudflare tunnel orchestrator contract, global header tunnel pill contract, and mobile QR modal contract patterns, which load the card and assert user-visible behavior and authorization boundaries.
- **Manual Windows acceptance:** Paste the block into a non-elevated shell and confirm a clear exit message; paste into an elevated shell and confirm the service reaches RUNNING. Reboot and confirm Remote Access returns without a login. Re-run and confirm repair. Run the removal block and confirm the service and legacy task disappear. Verify local checkout and printing during an internet outage.
- **Mode acceptance:** With no token, confirm an interim Quick Tunnel URL appears and is labeled. After saving a token and hostname, regenerate and confirm the named tunnel starts and the card reports the permanent hostname.

## Out of Scope

- Installing or configuring XAMPP, Apache, PHP, or MySQL.
- Copying application source or importing the database.
- Purchasing a domain or automating Cloudflare account, tunnel, or Access creation.
- Self-updating setup blocks or background version checks.
- Changing remote read-only enforcement, which remains governed by ADR-0019.
- A second live Store PC, multi-store ingress, or cloud database replication.

## Further Notes

This spec supersedes the Quick Tunnel exclusion in the owner remote monitoring specification only for the interim period before an owner-controlled domain exists, as recorded in ADR-0027. The permanent path remains the named tunnel with Cloudflare Access described in ADR-0019. The setup block is a deployment convenience, not a new runtime dependency: removing it leaves the application, database, and local checkout unaffected.

Published as [GitHub issue #458](https://github.com/ZeachDev01/retailmind/issues/458) with `ready-for-agent`.
