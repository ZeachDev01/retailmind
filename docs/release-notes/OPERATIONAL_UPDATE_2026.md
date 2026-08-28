# RetailMind Operational Update — July 2026

## Implemented workflows

1. **Controlled checkout** — only active products with sellable non-expired stock can be processed. The POS uses server-side product lookup instead of loading the full catalog.
2. **Cashier control** — cashiers must open a shift, can record drawer pay-ins/pay-outs, and close with expected-versus-actual cash reconciliation.
3. **Held sales** — carts are stored in MySQL for 24 hours and can be resumed or cancelled by the cashier.
4. **Discount controls** — manual discounts require a reason; discounts above 10% require an active administrator login. Scheduled promotions are evaluated automatically and the largest eligible discount is applied.
5. **Formal replenishment** — a request is reviewed by a different administrator, converted to a supplier purchase order, then received by units or packages. Only accepted quantities complete the request or PO.
6. **Supplier and purchasing records** — supplier contacts, lead times, MOQ, costs, PO status, partial receiving, and printable PO details are available.
7. **Forecast governance** — recommendations can be accepted, modified with a reason, or rejected. Decisions are retained for review.
8. **Model validation** — Random Forest WAPE and MAE are compared with a seven-day moving-average baseline.
9. **Exception review** — low-confidence, inaccurate, stale, low-data, and unusual forecast results are collected in one dashboard.
10. **Inventory intelligence** — days of stock, dead stock, excess stock, expiry exposure, ABC class, and cycle-count schedules are calculated.
11. **Product handling** — case barcodes, package conversion, base/receiving units, and optional family/variant labels are supported.
12. **Expiry control** — configurable 90/60/30/14/7-day alerts and FEFO/expired-stock blocking are included.

## Upgrade

```bash
php backend/scripts/migrate.php
python backend/legacy/demandForcasting/train_model.py
bash backend/tests/run_all.sh
```

## Verification completed in this release

- PHP syntax checking for every project PHP file.
- Python compilation for all ML scripts.
- Static smoke checks for inactive-product blocking, shift enforcement, discount authorization, segregation of duties, accepted-stock receiving, server-held sales, API product search, baseline comparison, and migration coverage.

A live MySQL integration test was not run in the build environment. Deploy first to a staging database, run the migration, and test the end-to-end workflows before replacing the production installation.
