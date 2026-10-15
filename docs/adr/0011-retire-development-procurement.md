# Retire development procurement in favor of direct Stock Receiving

Issue #181 supersedes #176's legacy-preservation scope: remove supplier administration, Supplier Product Terms, requests, orders, and document-linked receiving rather than maintain competing inventory workflows. The owner shops outside RetailMind; Inventory Managers record actual goods through the existing atomic direct-receiving boundary, while Administrators retain read-only oversight. Historical ADRs remain unchanged as records of earlier decisions.

## Consequences

- Six primary inventory destinations: Stock Overview, Replenishment Recommendations, Stock Receiving, Receiving History, Products, and Inventory Control (counts and Stock Issue review).
- Shopping-store text, receipt reference, and per-base-unit purchase cost remain optional. Unknown cost stays unknown; accepted quantities alone increase shared stock and saleable batches.
- Development upgrades delete only retired procurement tables and links. Receiving quantities, costs, shopping details, staff/time, accounts, customer receipts, sales/refunds, inventory/batches, counts, Stock Issues, product planning defaults, and Protected Audit Records survive. Back up and stop Store activity before DDL; rerun after resolving any failure.
- Fresh schema, explicit setup, migrations, reset utilities, seeds, fixtures, and current docs must agree; startup must never recreate retired procurement storage.
- Stock-rule guidance works without forecasts or supplier configuration. Missing forecasts remain unavailable, never zero demand. Observation Mode, recommendation suspension, Forecast Readiness, and model governance remain authoritative. Receiving and reviewing recommendations enqueue no forecasting work.
