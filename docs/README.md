# Documentation

Start with the repository [README](../README.md) for setup, testing, database
updates, and InfinityFree deployment. Paths in commands are relative to the
repository root unless stated otherwise.

## Setup and recovery

- [Project structure](PROJECT_STRUCTURE.md)
- [Production deployment checklist](DEPLOYMENT_CHECKLIST.md)
- [Database Backup and recovery](BACKUP_RECOVERY.md)
- [Offline Recovery Account procedure](RECOVERY_ACCOUNT.md)

## Interface guides

- [Client demo workflow](guides/client-demo-workflow.md)
- [Synthetic-data forecasting demo](guides/DEMO_FORECASTING.md)
- [Forecasting scheduler](guides/FORECASTING_SCHEDULER.md)
- [Daily notifications](guides/DAILY_NOTIFICATIONS.md)
- [GCash workflow notes](guides/gcash.md)
- [Xprinter XP-58IID setup](guides/xprinter-xp-58iid-setup.md)
- [Real public-data forecasting and replenishment demo](guides/public-forecasting-demo.md)
- [Recent-date train/test sales replay and stock preview](guides/train-test-sales-replay.md)
- [Hostinger Demo database import and saved replay](guides/hostinger-demo-replay.md)
- [Hostinger Demo deployment plan](guides/hostinger-demo-deployment-plan.md)
- [Platform health, migrations, and logs](guides/platform-health-migrations-and-logs.md)
- [Failed sign-in review](guides/failed-sign-in-review.md)
- [Stock monitoring, recommendations, and direct receiving](guides/direct-stock-receiving.md)
- [Mobile and tablet home-screen installation](guides/HOME_SCREEN_APP.md)
- [Persistent dashboard navigation](guides/page-navigation.md)
- [Personal display themes and screen coverage](guides/theme-coverage.md)

## Product and architecture

- [Core domain vocabulary](CONTEXT.md) — Store, roles, account access, inventory, and forecasting terms.
- [Store operations vocabulary](../GLOSSARY.md) — additional operations, migration, GCash, packaging, and replenishment terms. Read both when a concept spans them; overlapping definitions remain preserved.
- [Product context](PRODUCT.md)
- [Design system](DESIGN.md)
- [Architecture decisions](adr) — numbered decisions; later decisions may supersede earlier ones.
- [Specifications](specs) — agreed scope and acceptance scenarios.
- [Research](research) — findings from a particular investigation; check the current code before treating them as current behavior.
- [Legacy feature notes](research/legacy-feature-notes.md)

Design and policy discussions:

- [Batch expiration context](specs/batch-expiration-context.md)
- [Fiscal Period context](specs/fiscal-period-context.md)
- [GCash service design](specs/gcash-service-design.md) and [specification](specs/gcash-service-spec.md)
- [Navigation icon design](specs/navbar-icons-design.md)
- [Forecasting implementation review](research/forecasting-implementation-review.md)
- [Replenishment discussion, October 22, 2026](research/replenishment-forecasting-conversation-2026-10-22.md)

## Verification

- [Beta questionnaire quality test plan](verification/beta-questionnaire-quality-plan.md) and [2026-10-30 software evidence report](verification/beta-questionnaire-quality-report-20261030.md).
- [2026-10-30 transaction/browser results and user-reported hardware acceptance](verification/beta-questionnaire-transaction-browser-20261030.md).
- [Beta questionnaire remediation and verification specification](specs/beta-questionnaire-quality-remediation.md) — [issue #352](https://github.com/ZeachDev01/retailmind/issues/352).
- [Operator Alerts manual verification matrix](verification/friendly-alerts-matrix.md)
- [Theme ticket validation](verification/theme-ticket-validation.md)
- [Responsive UI verification](verification/responsive-ui-verification.md)
- Workspace audits: [Administrator](verification/admin-ui-audit.md), [Super Administrator](verification/superadmin-ui-audit.md), [Cashier](verification/cashier-ui-audit.md), [Inventory Manager](verification/inventory-manager-ui-audit.md).
- Workflow audits: [audit logs](verification/audit-logs-ui-audit.md), [notifications](verification/notifications-ui-audit.md), [Receipt History](verification/receipt-history-audit.md), [Registers](verification/registers-ui-audit.md).

These notes record their original checks, not proof that the current revision passes.

## Teaching and development materials

- [Daily Store operations lesson](teaching/lessons/0001-one-store-day.html)
- [Printable demo reference](teaching/reference/daily-operations.html)
- [Teaching mission](teaching/MISSION.md), [notes](teaching/NOTES.md), and [resources](teaching/RESOURCES.md)
- [Datasets, prototypes, and preserved scratch artifacts](../work/README.md)

## Release history

- [Printable barcode feature](release-notes/BARCODE_FEATURE_UPDATE.md)
- [July 2026 operational update](release-notes/OPERATIONAL_UPDATE_2026.md)
- [August 2026 UI/UX update](release-notes/UI_UX_UPDATE_NOTES.md)
- [Archived notes](release-notes/archive) — retained legacy documents, including a duplicate barcode note and an old deployment checklist. Use the current deployment checklist above for setup.

## Repository instructions

- [Issue tracker](agents/issue-tracker.md)
- [Triage labels](agents/triage-labels.md)
- [Domain documentation conventions](agents/domain.md)

Keep repository instructions and architecture decision filenames stable. Keep
recovery procedures at their current paths because application messages refer to them.
