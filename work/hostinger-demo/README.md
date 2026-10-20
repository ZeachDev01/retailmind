# Hostinger Demo database snapshot

`replay-demo.sql` contains the simulated UCI train/test replay from 2026-10-21.
Import it only into a **new, empty, dedicated Demo database**. Do not import
`schema.sql` first and do not import this snapshot into an operating Store.

Included: 20 products, 5,760 training rows, 1,680 test-day/product observations
represented by 72 simulated daily aggregate sales, current stock/batches/movements,
2,240 saved prediction rows, training evaluations and replay provenance.
Zero-sale dates have no receipt; the complete test calendar is recorded in metadata.

All four Demo accounts have unusable password hashes in this committed copy.
The local private `activate-accounts.sql` enables them with new random passwords.
PHP sessions, login attempts, activity logs, real credentials and model binaries
are excluded. `manifest.json` records the SQL checksum and exported row counts.

Follow the [Hostinger setup guide](../../docs/guides/hostinger-demo-replay.md).
This is an application data snapshot, not a MySQL server/session backup.

Source: [UCI Online Retail](https://archive.ics.uci.edu/dataset/352/online+retail),
Daqing Chen, CC BY 4.0, DOI 10.24432/C5BW33. Daily aggregation, product selection
and date shifting are adaptations. Stock and monetary placeholders are simulated;
see the [experiment methodology](../../docs/guides/train-test-sales-replay.md).
