# Cashier Selection of Administrator-created Promotions

## Problem Statement

Cashiers can enter their own discount type, value, and reason. Checkout also automatically chooses the highest eligible promotion. The Store wants Administrators to control discount rules and Cashiers to apply only an existing, applicable Promotion through a dropdown.

## Solution

Administrators continue managing Promotions through the existing workflow. Cashiers see a dropdown of Eligible Promotions for the current cart, showing names and discount amounts. Default to "No promotion"; require explicit selection and apply only one Promotion.

Remove custom discount entry and Administrator approval credentials. Administrator-created Promotions require no additional approval, including above 10%.

Revalidate when the cart changes and at checkout. If the selected Promotion becomes invalid, explain why, clear it, and require review of the updated total before payment.

## User Stories

1. As an Administrator, I want to define Promotions, so that the Store controls available discounts.
2. As an Administrator, I want existing percentage and fixed-amount rules retained, so that established Promotions remain usable.
3. As an Administrator, I want existing scope, schedule, and minimum-quantity rules retained, so that discounts apply where intended.
4. As an Administrator, I want existing activation controls retained, so that I can control availability.
5. As an Administrator, I want forged custom discounts rejected, so that bypassing the interface cannot grant a discount.
6. As a Cashier, I want a Promotion dropdown, so that I can select an existing discount without entering rules.
7. As a Cashier, I want only Eligible Promotions listed, so that available choices match the cart.
8. As a Cashier, I want names and discount amounts shown, so that I can compare choices.
9. As a Cashier, I want "No promotion" selected by default, so that discounts require an explicit choice.
10. As a Cashier, I want my selected Promotion honored even when another gives a larger discount, so that checkout respects my choice.
11. As a Cashier, I want one Promotion per sale, so that discounts cannot stack.
12. As a Cashier, I want to return to "No promotion", so that I can remove a discount before checkout.
13. As a Cashier, I want a clear empty state, so that I understand when no Promotions qualify.
14. As a Cashier, I want eligibility refreshed after cart changes, so that options reflect current items and quantities.
15. As a Cashier, I want a still-eligible selection retained and recalculated, so that its amount reflects the updated cart.
16. As a Cashier, I want invalid selections cleared with an explanation, so that I understand why they became unavailable.
17. As a Cashier, I want expiration and deactivation checked at checkout, so that outdated Promotions cannot apply.
18. As a Cashier, I want to review the updated total after invalidation, so that payment uses an acknowledged amount.
19. As a Cashier, I want Administrator-created Promotions above 10% without another password, so that approved rules need no repeated authorization.
20. As a Cashier, I want payment totals and the final quote to agree, so that I collect the correct amount.
21. As a Cashier using a keyboard or small screen, I want labeled, accessible controls, so that I can complete checkout.
22. As a Cashier, I want loading failures distinguished from no eligible choices, so that unavailable information does not appear valid.
23. As an Administrator, I want recorded sale discounts retained, so that later Promotion changes cannot rewrite history.
24. As a Cashier handling refunds, I want original-paid-amount calculations preserved, so that this change does not alter refund entitlements.

## Implementation Decisions

- Reuse Administrator promotion management and existing role/workspace boundaries. Cashiers cannot create or modify discount rules through checkout.
- Reuse the checkout workflow's eligibility calculation, monetary calculations, and eligible-promotion response rather than building a second promotion engine.
- Replace manual type, value, reason, and approval credentials with one Promotion selection or "No promotion".
- Send the selected Promotion identifier as untrusted checkout input. Resolve current rules and calculate amounts on the server; reject custom discount submissions and unknown or ineligible identifiers.
- Update legitimate checkout callers and quote-input tracking together.
- Default to no Promotion and remove automatic best-discount application from this workflow. Never substitute another Promotion for the selected one.
- Retain active status, inclusive schedule boundaries, cart/product/category scope, and minimum matching quantity.
- Preserve percentage/fixed calculations, matching-subtotal caps, currency rounding, and existing Sales Pack and loose-item behavior.
- Apply at most one Promotion per sale; no stacking or manual discount combination.
- Refresh eligibility and amounts after cart changes. Retain and recalculate a still-eligible selection; clear an invalid one with an explanation.
- Invalidate the reviewed quote when its inputs or applicable rules change. Revalidate at checkout and require an updated review before payment when a selected Promotion becomes invalid; never silently complete a differently priced sale.
- Administrator-created Promotions need no additional credentials, including above 10%. Remove obsolete manual-discount approval controls from this flow.
- Show names, computed monetary discounts, "No promotion", and clear empty/loading/error feedback. Failed eligibility requests must not masquerade as empty successful responses.
- Preserve labels, keyboard operation, responsive usability, payment methods, shift requirements, and fiscal-period controls.
- Preserve saved discount/Promotion details, historical receipts, and original-paid-amount refund calculations.
- Prefer existing schema and modules. No new dependency, promotion engine, or speculative configuration is required.

## Testing Decisions

- Confirmed primary seam: the existing database-backed checkout workflow, exercising quote generation and checkout through public contracts. Assert observable amounts, selected Promotion identity, rejection behavior, persistence, and stale-quote handling rather than private helpers or SQL wording.
- Confirmed supporting seam: existing cashier checkout browser tests for dropdown behavior, totals, feedback, and review invalidation. Reuse established checkout quote integration and browser coverage.
- Cover default no Promotion despite eligible choices, deliberately selecting a smaller discount, returning to no Promotion, and no stacking.
- Cover status, schedule boundaries, scope, minimum quantity, percentage/fixed calculations, matching-subtotal caps, and existing pack/piece behavior.
- Reject forged identifiers, ineligible selections, and custom discount attempts.
- Cover cart edits that retain and recalculate a selection, and edits that invalidate it and require review.
- Cover expiration and deactivation between selection, quote review, and checkout; verify no silent replacement or stale-price completion.
- Cover Promotions above 10% without additional approval and continued checkout authorization.
- Verify labels, displayed amounts, empty/error states, keyboard operation, totals, and selection-driven quote invalidation.
- Use existing receipt/refund regression coverage to verify recorded discounts and original paid amounts remain coherent.
- The user confirmed these testing seams before issue publication.

## Out of Scope

- Application implementation during this specification task.
- Cashier-created discounts, manual exceptions, stacking, or automatic best-promotion selection.
- New eligibility rules, loyalty schemes, coupon codes, customer classifications, or promotion types.
- Redesigning Administrator promotion management or expanding permissions.
- New payment methods, refund policies, historical migrations, or recalculating old discounts.
- New dependencies or a separate promotion subsystem.

## Further Notes

The user confirmed explicit selection, default "No promotion", existing eligibility, one Promotion per sale, clearing invalid selections with an explanation and renewed review, and no extra approval above 10%.

Promotion and Eligible Promotion terms are recorded in the glossary. Existing Store authority and historical receipt decisions remain applicable. No new ADR is required for this localized selection-policy change.

The user authorized publication to the project's GitHub Issues tracker with the `ready-for-agent` label. Application implementation is deferred to the resulting ticket.
