---
status: accepted
---

# Free ngrok setup with an optional Cloudflare domain upgrade

The Store must be able to demonstrate remote access without buying a domain. The current Quick Tunnel lost its temporary registration while its Windows service kept running, exposing that process state was being mistaken for public availability. Use each Store's own ngrok Free account and assigned static hostname for the initial demo, accepting its browser warning and monthly quotas. Keep Cloudflare Named Tunnel as the optional custom-domain upgrade, with each Store owning its provider accounts. This revises ADR-0027's Quick Tunnel default; Quick remains available only for temporary troubleshooting.

Platform Settings generates a copy-paste PowerShell block for native Windows service installation, automatic startup and crash recovery. Domain/DNS/tunnel creation and account sign-up remain provider-dashboard steps. Verify the new public route before disabling the previous provider; restore the previous configuration if activation fails. Saved Cloudflare credentials do not change the installed connection until the block is run. RetailMind sign-in and existing role permissions are the initial access gate; ADR-0030's workspace boundaries still apply. Remote availability is required only during store hours with the PC, local services and internet running.

Free service limits and provider outages preclude an uptime guarantee. Public status checks validate this installation through a minimal data-free endpoint and cache results for at most 60 seconds to limit probe traffic. Test connection bypasses that cache. Windows service recovery addresses process crashes; native agents handle network reconnects, and restarting cannot cure exhausted quota. Actual provider login, reboot and reconnect acceptance must be performed with the Store's credentials before the demo is described as reliable.
