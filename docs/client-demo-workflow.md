# Client Demo Workflow

## Before client joins

1. Deploy `main` through GitHub Actions to `https://demo.<root-domain>`.
2. Confirm HTTPS, demo database, and dedicated multi-role demo account.
3. Smoke-test Cashier sale/receipt, Inventory Manager receiving, Administrator reports, and mobile view.
4. Keep local demo/screenshots ready as fallback.

## 15-minute walkthrough

1. **Set context (1 min):** “This mirrors a typical store day. We will show each role's work and capture your feedback.”
2. **Cashier (4 min):** switch to Cashier Workspace, start shift, make one demo sale, show Sale Receipt.
3. **Inventory Manager (4 min):** switch Workspace, receive demo stock, show updated stock and replenishment guidance.
4. **Administrator (4 min):** switch Workspace, show dashboard, reports, and oversight.
5. **Close (2 min):** confirm what matches their process, what blocks it, and the highest-priority change.

## Complete gross profit demo

Local development only, after an authorized operational reset and backup:

```powershell
php src/backend/scripts/seed_gross_profit_demo.php --confirm-local-database=inventory_system
```

Use your actual local database name. Apply the existing `202610180005_sold_stock_cost_snapshots` migration first. The seed refuses hosted databases, existing products/sales, open shifts, and protected fiscal periods; it does not reset data or reopen periods itself.

The seed reuses the 60-product catalog and creates 91 days of simulated sales for 12 stocked products. The other 48 products start without stock. Sale cost snapshots come from recorded receiving costs, with catalog-cost estimation disabled. It verifies positive, recorded gross profit for today, this week, and this month, and checks every sold allocation has a recorded cost. If seeding fails after creating records, inspect the error and reset the demo before retrying.

For new sales during the demo:

1. **Inventory Manager:** select an active product and confirm its selling price.
2. **Stock Receiving:** receive stock with its actual **purchase cost per base unit**, not just a selling price. For a case costing ₱240 with 24 pieces, enter ₱10 per piece. Save before selling.
3. **Cashier:** start a shift, sell the received product, and complete payment. An unpaid cart or held sale does not count.
4. **Administrator:** open the dashboard or Gross Profit Report for the sale's Philippine date. Net Sales minus the cost of units sold equals Gross Profit. Unsold stock is not deducted here.

Example: receive 20 pieces at ₱10 each, then sell 2 at ₱15 each. With no discounts or refunds, Net Sales is ₱30, Product Cost is ₱20, and Gross Profit is ₱10.

Entering a catalog cost after a sale is not equivalent to recording its historical purchase cost. Missing sale cost snapshots can keep past profit incomplete. Use stock with recorded receiving costs before checkout. Rent, utilities, internet, and wages remain outside gross profit.

## Rules

- Use Demo Data only; no real customer, employee, or store records.
- Forecasts are advisory guidance, not automatic purchasing or live model training.
- Let the client choose the demo product and quantity; you operate the system.
- Record feedback under: blockers, missing information, visual/usability issues.

- Ask for Dataset for our dataforecasting supplier records or sales record if have in hand
- then explain it what value will it provide them

## Fallback

If hosted demo fails, use the local copy or prepared screenshots, continue the same story, and record the hosting issue separately.
