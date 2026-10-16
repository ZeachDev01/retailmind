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

## Rules

- Use Demo Data only; no real customer, employee, or store records.
- Forecasts are advisory guidance, not automatic purchasing or live model training.
- Let the client choose the demo product and quantity; you operate the system.
- Record feedback under: blockers, missing information, visual/usability issues.

- Ask for Dataset for our dataforecasting supplier records or sales record if have in hand
- then explain it what value will it provide them

## Fallback

If hosted demo fails, use the local copy or prepared screenshots, continue the same story, and record the hosting issue separately.
