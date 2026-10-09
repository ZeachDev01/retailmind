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

- [Mobile and tablet home-screen installation](guides/HOME_SCREEN_APP.md)
- [Persistent dashboard navigation](guides/page-navigation.md)
- [Personal display themes and screen coverage](guides/theme-coverage.md)

## Product and architecture

- [Domain vocabulary](CONTEXT.md)
- [Product context](PRODUCT.md)
- [Design system](DESIGN.md)
- [Architecture decisions](adr/) — numbered decisions; later decisions may supersede earlier ones.
- [Specifications](specs/) — agreed scope and acceptance scenarios.
- [Research](research/) — findings from a particular investigation; check the current code before treating them as current behavior.
- [Legacy feature notes](research/legacy-feature-notes.md)

## Verification

- [Operator Alerts manual verification matrix](verification/friendly-alerts-matrix.md)
- [Theme ticket validation](verification/theme-ticket-validation.md)

These notes record their original checks, not proof that the current revision passes.

## Release history

- [Printable barcode feature](release-notes/BARCODE_FEATURE_UPDATE.md)
- [July 2026 operational update](release-notes/OPERATIONAL_UPDATE_2026.md)
- [August 2026 UI/UX update](release-notes/UI_UX_UPDATE_NOTES.md)
- [Archived notes](release-notes/archive/) — retained legacy documents, including a duplicate barcode note and an old deployment checklist. Use the current deployment checklist above for setup.

## Repository instructions

- [Issue tracker](agents/issue-tracker.md)
- [Triage labels](agents/triage-labels.md)
- [Domain documentation conventions](agents/domain.md)

Keep repository instructions and architecture decision filenames stable. Keep
recovery procedures at their current paths because application messages refer to them.
