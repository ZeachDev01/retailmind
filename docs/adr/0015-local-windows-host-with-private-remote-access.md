---
status: proposed
---

# Local Windows hosting with private remote access

Remote-access scope was later narrowed to owner-only read-only monitoring through Cloudflare; see [ADR-0019](0019-owner-only-remote-monitoring-through-cloudflare.md). The local hosting, offline checkout, and recovery decisions below still apply.

The owner confirmed a dedicated Windows Store PC as RetailMind's host, continued local selling during internet outages, and remote access limited to the owner and approved staff. Keep the application and primary database on that PC so local operations can continue while the Store's internet connection is unavailable; remote access depends on the host's power and internet connectivity.

The user confirmed installing a private-access app on remote devices and limiting cashier sales and refunds to the Store. Remote use initially covers reports, inventory, and administration. One daily backup replaces the initially discussed one-hour data-loss target: the user accepts potential loss of roughly a day of sales and inventory changes after drive failure. Retain 30 daily recovery copies, subject to storage capacity. Automatic backups must be copied off the host; scheduling a backup alone does not establish recoverability.

The user confirmed a four-business-hour recovery target, conditional on an available replacement PC, with manual sales recording during recovery; encrypted automatic backups to owner-controlled cloud storage; and a recurring service budget subject to review of exact prices before purchase. Existing hardware is one PC, one wired barcode scanner, and one thermal printer. A UPS is planned; purchase, runtime, and shutdown behavior remain the owner's decision. Short-outage bridging followed by controlled shutdown is the recommendation, not a confirmed owner requirement.

The user will handle updates, backup-failure alerts, and recovery. An external backup drive is acceptable so backups can continue during internet outages. Recovery depends on successful backup capture and copying to a surviving destination; backup monitoring and a restore drill are required to validate the arrangement. External-drive backups do not independently protect against theft, site loss, or ransomware. The owner will control the cloud account and retain an encryption-key recovery copy outside the host PC.

Automatic application startup every time Windows boots is confirmed. The user selected standalone Apache HTTP Server with PHP and the existing MySQL/MariaDB database engine as the alternative to Laragon because of its popup. Run Apache and the database as automatic Windows services, with PHP configured in Apache, and schedule background jobs independently of Laragon. Compatibility and unattended startup validation remain pending.

These requirements were discussed on 2026-10-22. Final review of the high-level outline is pending. Private-network and cloud-provider selection and pricing, any local HTTPS needs, backup timing around PC shutdown, key recovery arrangements, UPS sizing, and restore validation remain for the detailed setup plan. Local-only cashier enforcement and durable encrypted off-PC backup automation require implementation; neither is currently established by the existing application. This record does not authorize deployment or purchases.

The user subsequently requested specification synthesis and confirmed testing through existing browser/HTTP workflows, backup/restore integration coverage, and Windows boot/outage/restore acceptance checks. See the [deployment specification](../specs/local-windows-deployment.md), published as [issue #271](https://github.com/ZeachDev01/retailmind/issues/271) with `ready-for-agent`. This records the design for future implementation; live deployment remains separate.
