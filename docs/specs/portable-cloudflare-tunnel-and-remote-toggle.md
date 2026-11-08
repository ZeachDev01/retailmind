# Portable Cloudflare Tunnel and Remote Access Toggle

## Problem Statement

The Store owner wants to easily access RetailMind from a smartphone or laptop outside the local store network without performing complicated router port-forwarding or needing a fixed public IP address. At the same time, the owner is not yet ready to purchase a permanent custom domain, but expects whatever solution is built to be seamless to upgrade once a custom domain is acquired. Furthermore, the application will eventually be deployed to a dedicated physical Store PC that is completely separate from the development workstation; setting up the remote access capability on that target PC must be fast, portable, and require minimal technical effort. In addition, the owner desires an intuitive in-app toggle switch within the administrative interface to enable or disable remote access on demand, easily view and copy the active temporary URL, scan a QR code from a mobile device, and monitor connection status from anywhere in the app header.

## Solution

Provide a turnkey, portable Cloudflare Tunnel management solution integrated into RetailMind. The system supports instant temporary public HTTPS connectivity using Cloudflare Quick Tunnels while maintaining a clean architectural path to bind a permanent domain and tunnel token later. Within the Store Configuration settings, an administrator can toggle remote access on or off, inspect current operational status, copy the temporary URL, or scan a generated QR code with a smartphone. A persistent header quick-pill displays live status across all workspaces and provides 1-click URL retrieval. On the deployment side, self-contained, portable Windows scripts allow the entire tunnel daemon to be bootstrapped and installed as an automatic Windows Service on any target PC with zero host machine coupling. Web security guardrails are enforced to shield local databases, configuration secrets, and administrative utilities from untrusted external traffic.

## User Stories

1. As a Store Owner, I want to access my local RetailMind installation from my phone while away from the store, so that I can monitor operations and store activity remotely.
2. As a Store Owner, I want remote access to generate an online HTTPS URL without requiring a custom domain initially, so that I can test remote connectivity immediately without upfront expense.
3. As a Store Owner, I want the system architecture to support an effortless transition to a permanent custom domain in the future, so that I do not need to rewrite or reconfigure application logic when buying a domain.
4. As an Administrator, I want to toggle remote access on or off directly within the Store Configuration view, so that I maintain direct control over when the local application is reachable from the internet.
5. As an Administrator, I want to view the current remote connectivity status (Online vs Offline) in real time, so that I know whether the tunnel daemon is actively serving traffic.
6. As an Administrator, I want to easily copy the active temporary tunnel URL with a single click, so that I can paste it into messaging apps or save it to my phone's browser bookmarks.
7. As an Administrator, I want to view a scannable QR code of the active remote URL, so that I can open the store interface on my mobile phone instantly without manually typing long random URLs.
8. As a Store Owner, I want a persistent status indicator in the top header bar, so that I can verify remote tunnel availability and copy the link from any page without navigating back to settings.
9. As a Deployer, I want portable, self-contained setup scripts that run on any Windows PC, so that deploying RetailMind to a dedicated store machine does not depend on developer workstation paths or manual binary installations.
10. As a Deployer, I want the remote tunnel capable of running as a registered Windows Service, so that remote connectivity automatically resumes after store power outages or PC restarts without manual intervention.
11. As a Store Owner, I want sensitive files like database backups, source control directories, and environment secrets strictly blocked from external requests, so that exposing the local server to the internet does not leak confidential business data.
12. As a Store Owner, I want local Point of Sale checkout and receipt printing to remain fully operational during internet disruptions, so that store sales are never blocked by cloud or connectivity drops.
13. As an Administrator, I want internal assets, stylesheets, scripts, and session cookies to function properly over both local HTTP and remote HTTPS origins, so that remote pages load cleanly without mixed-content errors or broken styles.
14. As an Administrator, I want the option to input a Cloudflare Tunnel Token when a custom domain is acquired, so that the tunnel smoothly binds to our private domain name without needing code refactoring.
15. As a Cashier, I want my physical checkout register and thermal printing to stay isolated on the store counter, so that remote viewers cannot trigger unintended physical till transactions.
16. As a Store Owner, I want remote sessions to obey authentication policies and timeouts, so that leaving a remote browser open does not compromise store governance.
17. As an Administrator, I want stopping the tunnel toggle to immediately sever external access, so that no external connection persists when the store owner wishes to remain strictly local.
18. As a Deployer, I want clear, concise documentation outlining the 5-minute setup process on the target Store PC, so that store personnel or technicians can bring the system online confidently.

## Implementation Decisions

- **Store Configuration Integration**: Add a dedicated Remote Access management card within the Store Configuration settings. This card houses the activation toggle, live status badge, public URL container with a 1-click clipboard copy button, a modal or popover trigger for a mobile-friendly QR code, and a configuration placeholder for future permanent Cloudflare Tunnel tokens.
- **Persistent Header Quick-Pill**: Embed a non-intrusive status pill in the global application header adjacent to notifications. When remote access is active, the pill illuminates green and provides a compact dropdown displaying the active URL and a quick-copy action.
- **Tunnel Orchestration Service**: Implement a backend daemon management abstraction responsible for starting, stopping, inspecting the health of, and extracting the dynamic public URL from the tunnel process. The service maintains process state cleanly and ensures graceful shutdown when disabled.
- **Portability & Host Decoupling**: Isolate all tunnel bootstrap scripts and binary downloads into standalone scripts using relative paths. The scripts automatically detect the target environment, verify local web server responsiveness, fetch the standalone executable if absent, and support both immediate interactive launch and permanent Windows Service installation.
- **Reverse Proxy and Protocol Detection**: Ensure the application's base URL and routing infrastructure dynamically respect reverse proxy headers such as forwarded host and protocol parameters, preventing hardcoded localhost URLs from corrupting remote stylesheets, scripts, and navigation links.
- **Security Boundary & File Lockdown**: Enforce rigorous web server access controls to ensure hidden files, environment definitions, git metadata, internal test fixtures, and raw database backup archives return immediate HTTP 403 Forbidden responses to external requests.
- **Domain Cutover Readiness**: Provide a structured configuration template separating volatile operational parameters (such as credentials and tunnel tokens) from the core application. Upgrading from a temporary quick tunnel to a branded custom domain requires only populating the token within the configuration file, with zero modifications to application source code.

## Testing Decisions

- **Primary Seam: HTTP & Administrative UI End-to-End**:
  - Tests must evaluate externally observable system behavior and user interactions rather than testing raw script strings or private process internals.
  - Test the Store Configuration remote access card to ensure toggling state correctly triggers operational status transitions, updates the live indicator between Offline and Online, and surfaces the retrieved tunnel URL.
  - Test the header status pill to confirm accurate reflection of online state across distinct roles and views.
  - Test the copy button and QR code generator to ensure the output URL accurately matches the current tunnel entrypoint.
- **Security & Route Guardrail Seam**:
  - Execute automated HTTP probes against sensitive locations (environment definitions, version control directories, backup directories) to guarantee access is categorically denied.
  - Verify that mutations restricted to physical store premises (cash drawer balancing, physical register adjustments) respect access policy boundaries when accessed over remote origins.
- **Prior Art**:
  - Leverages existing administrative dashboard route testing, session/authentication policy tests, and store settings persistence test patterns.

## Out of Scope

- Registering or purchasing a custom domain name during the current development iteration.
- Enforcing paid enterprise Cloudflare features or multi-region load balancing.
- Replicating the local transactional database to cloud database instances; local transactions remain authoritative on the physical Store PC.
- Enabling remote cash drawer kicks or triggering remote physical thermal printing.

## Further Notes

This specification addresses the immediate requirement for portable, on-demand remote access with quick-tunnel capabilities while fully honoring the long-term goal of running on a permanent custom domain on a dedicated physical Store PC. Once approved and implemented, transition to a custom domain will require only obtaining the tunnel credentials from the Cloudflare dashboard and entering them into the provided configuration template.

Published as [GitHub issue #439](https://github.com/ZeachDev01/retailmind/issues/439) with `ready-for-agent`.
