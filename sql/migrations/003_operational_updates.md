# Migration 003: Operational Updates

Run `php scripts/migrate.php` from the project root after deploying this release.
The migration is idempotent and adds supplier and purchase-order workflows, cashier shifts,
server-held sales, discount audit fields, package conversion, forecast decisions, and cycle-count schedules.
