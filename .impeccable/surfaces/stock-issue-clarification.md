---
version: 1
slug: "stock-issue-clarification"
primary_target: "src/frontend/components/cashier/stock_issues.php"
related_targets: ["src/frontend/components/inventory_management/stock_issues.php", "src/backend/app/Views/stock_issue_conversation.php", "src/frontend/assets/css/stock-issue-conversation.css", "src/frontend/assets/js/stock-issue-conversation.js"]
---

# Stock Issue clarification

## Mode and confirmed direction

Operate. The user replaced the active conversation flow with direct Inventory Manager recording and a single Manager decision for Cashier reports on 2026-11-25. Preserve RetailMind system typography, Action Blue, compact labels and existing light/dark surfaces.

## Composition and behavior

Manager navigation has Record Stock Issue, Pending review and Recent decisions. Direct entry reuses the Cashier barcode/search form, issue type, stock form, confirmed quantity and reason with one Record & deduct stock action. Product and details grids stack on mobile. Verified quantity remains editable in report review; original Cashier quantity remains in history. No new conversation button appears on reports without historical messages. Existing messages retain the bounded native read-only history dialog, unread marking, Escape and focus return. No reply composer or Cashier-reply waiting destination remains.

## Verification

Service contracts and browser checks cover direct recording, ledger rollback, retries, permissions, legacy unresolved review, editable confirmed quantities, direct packs, Cashier submission/Manager decision, and expired-product lookup. Rendered direct-entry forms were measured at 390, 768 and 1440px in light/dark themes and visually inspected; Cashier was checked at 390px. See `docs/specs/simplified-stock-issues.md` for complete results and the dashboard-cache fixture limitation.
