# Correct a refunded item's return condition

Implemented, 2026-11-24, through tickets #507, #508, and #509 on `integration/refund-return-condition-correction`.

## Purpose

Let staff fix an incorrectly recorded return condition inside the original Cash Refund. A normal stock correction must not require a separate Refund Correction Review, role switching, or Administrator approval.

Example: one Barnats was refunded as Damaged, but inspection confirms it is fit for sale. Staff change its condition to Restockable. The system restores that one Barnats to available stock and records who made the correction.

This specification replaces the Administrator-approved Refund Correction Review requirement for these return-condition corrections only. It does not change Cash Refund authorization, refund amounts, payments, or Cashier Shift money controls. Cash corrections are outside this specification.

## Staff flow

1. Open the original Cash Refund and select **Correct condition** beside the returned item.
2. Select the correct condition and enter a short reason.
3. Check the stock-change preview and select **Save correction**.

Keep this in the existing refund details. Do not send staff to another workspace, add an approval stage, or require a separate execution step. Saving records the condition correction and applies its stock effect together.

Show the product name, returned quantity and selling form, current effective condition, and new condition. Staff cannot change the product, returned quantity, batch, price, or refund amount through this action. For a multi-item refund, the action applies to the selected returned line, not every item on the refund.

The correction applies to the full quantity on that returned line. A mixed physical result requiring only part of the line to change uses the existing Inventory Manager stock reconciliation workflow; do not introduce a quantity editor in this action.

Use these preview messages, with the actual product and quantity:

- **Barnats — Damaged → Restockable. Return 1 piece to available stock.**
- **Barnats — Restockable → Damaged. Remove 1 piece from available stock.**

Do not show an unexplained `+1`, ask staff to calculate stock changes, or request a reason twice. After saving, show the effective condition and confirmation, for example **Correction saved. 1 Barnats returned to available stock.**

## Roles

| Role | Allowed action |
| --- | --- |
| Cashier | Correct the condition on a Cash Refund they issued, without switching workspace or obtaining Administrator approval. |
| Inventory Manager | Correct return conditions on Store Cash Refunds through the existing authorized refund/receipt details. |
| Administrator | View corrections and audit history, and handle exceptions. Routine corrections do not wait for their approval. |

A staff member with both Cashier and Inventory Manager roles can correct their own refund directly in the Cashier Workspace. Receipt visibility alone does not grant correction authority. Enforce permissions on the server, including direct requests against another Cashier's refund.

## Stock rules

The server calculates the change from the selected refund line, its latest effective condition, and its preserved selling form and batch allocations.

| Current condition | New condition | Stock effect |
| --- | --- | --- |
| Damaged | Restockable | Restore the selected line's returned quantity to available stock. |
| Restockable | Damaged | Remove that quantity from available stock. |
| Same as current | Same | No correction or stock movement; explain that the condition is unchanged. |

The first release covers Damaged and Restockable transitions only. Other return conditions keep their existing behavior and do not silently become eligible for this shortcut.

Restockable requires physical confirmation that the item is fit for sale. Preserve existing expiration and sealed Sales Pack eligibility: an expired, opened, or incomplete pack must not become sellable through this action. Show packs or pieces as originally returned; never treat one returned pack as one base unit.

Preserve the original Stock Batch provenance, costs, expiration, and sealed/loose stock rules. Do not create an unrelated receiving batch or select an arbitrary batch to make the totals balance. If the necessary historical allocation cannot be established, stop without a stock change and direct the Inventory Manager to reconciliation.

For removal, require sufficient stock in the applicable batch and selling form. If it is no longer available, save nothing and explain that the Inventory Manager must reconcile the stock. Never allow negative stock or take unrelated stock silently.

Keep existing applicable Store write and Fiscal Period safeguards. A stock-only correction does not require an open cash drawer or a money movement.

## History and safe saving

- Preserve the original sale, Cash Refund, refund lines, and printed receipt. Store each correction separately with its linked refund line, previous and new condition, quantity/form, reason, actor, time, and linked stock movements. Display the latest effective condition alongside correction history.
- Save the correction, stock balances, batch/form balances, stock movements, and audit record atomically. A failure leaves all of them unchanged.
- Bind saving to the condition/version shown in the preview. Reject stale submissions and make retries safe: double-clicks, refreshes, and concurrent staff must not restore or remove the same quantity twice.
- A later legitimate reversal uses the latest effective condition and creates a new historical correction. It does not repeat the original stock effect.
- Existing Refund Correction Reviews remain visible. Before allowing this shortcut for a line, account for its linked pending or completed stock corrections. Do not execute both paths for the same physical correction. Ambiguous legacy links require Inventory Manager reconciliation rather than an automatic extra movement.

## Acceptance checks

1. Barnats starts at 10 pieces. A sale leaves 9. A Damaged refund leaves 9. The issuing Cashier changes it to Restockable, gives a reason, and saves once. Available stock becomes 10 without Administrator approval or workspace switching.
2. A Restockable refund has restored stock. Correcting it to Damaged removes exactly its returned quantity and preserves the original refund and cash amount.
3. The Inventory Manager can perform the same action on an authorized Store refund. A Cashier cannot correct another Cashier's refund through a changed URL or POST body.
4. Multi-item refunds affect only the selected returned line. Product, quantity, batch, money, and selling-form tampering are rejected.
5. A sealed-pack correction preserves its historical pack size and sealed stock. Expired or otherwise ineligible returns cannot become sellable.
6. Repeated saves, simultaneous requests, and stale previews create at most one stock effect for each accepted transition. A subsequent valid reversal produces the correct opposite effect.
7. Missing allocation, insufficient stock, or an injected write failure leaves no partial correction, stock change, or completion claim. Existing linked review tasks cannot duplicate the correction.
8. On desktop and mobile, the product, quantity/form, condition change, and stock effect are readable before Save. After Save, history identifies the actor and reason. No separate execution checkbox or approval page is required.

Implementation should reuse the existing Cash Refund details, authorization, batch ledger, and audit mechanisms. Extend the relevant refund authorization, stock/batch, and browser tests; do not build a second stock ledger or a new correction workspace.

## Related specifications

- [Administrator workflow alignment](administrator-workflow-alignment.md)
- [Cashier history consolidation](cashier-history-consolidation.md)
- [Sealed pack sales and prepared oil pouches](sealed-pack-sales-and-prepared-oil-pouches.md)
- [Batch expiration and stock balance](batch-expiration-and-stock-balance.md)
