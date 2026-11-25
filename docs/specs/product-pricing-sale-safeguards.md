# Product pricing safeguards for sales

Status: User approved implementation on 2026-11-25. Implemented in shared quote review and checkout, with sale-time cost snapshots and Promotion discovery that does not authorize payment.

## Problem

Seeded Products have identification details but start with zero selling price and unknown cost. Stock can later exist while pricing remains incomplete. Checkout must prevent accidental sales of these Products. An intentional Administrator Promotion may nevertheless produce a legitimate below-cost sale.

## Confirmed decisions

- Keep the Product and its stock visible in inventory even when pricing is incomplete.
- Block Cashier sales when the Product lacks a valid selling price or confirmed cost, even if stock exists.
- Distinguish missing cost from Confirmed Zero Cost. Goods received free may have an explicitly confirmed zero cost.
- Permit intentional below-cost sales through Administrator Promotions, for example to clear goods approaching expiry or attract customers. A blanket ban on negative Gross Profit would prevent these intended sales.
- A Promotion cannot bypass an invalid regular selling price or missing cost. The regular price must be positive even when a Promotion is selected.
- An active, eligible Administrator Promotion explicitly selected by the Cashier is sufficient authorization for a below-cost sale. Do not require another approval for each sale. Only its eligible items receive this exception; another Product in the cart must not borrow the exception.
- Block an ordinary below-cost sale without a qualifying selected Promotion, including Sales Pack sales. Equality with cost is permitted.
- Do not allow a Promotion to make any sold item free, even when other items keep the cart total positive. Do not allow a zero-total cart. This also covers fixed discounts that consume the entire eligible subtotal and percentage discounts that round the payable amount to zero.
- Use the purchase cost recorded for the actual stock batch being sold, including its receiving cost when available. If that batch has no usable recorded cost, fall back to the confirmed Product cost entered during Product setup. If neither exists, block the sale. A confirmed zero cost remains valid.
- Apply the cost rule to every allocated batch, including mixed-batch and sealed-pack sales; profit from another Product must not hide an ordinary below-cost item.
- The fallback is a sale-time estimate, not a historical batch-cost correction. Preserve recorded versus estimated provenance and freeze the cost used with the completed sale. Later Product cost changes must not rewrite earlier sales.
- Preserve existing stock and expiry checks. A near-expiry Promotion does not authorize selling expired goods.

## Checkout behavior

- Validate on the server for both quote review and final checkout. A stale cart, resumed held sale, changed batch cost, changed price or changed Promotion must not bypass these rules.
- Identify the blocked Product and tell the Cashier to ask the Inventory Manager to complete or correct pricing. Do not ask the Cashier to enter or override purchase costs.
- Refusals create no sale, payment record, stock deduction or completed held sale.
- The Product and its physical stock remain in inventory; pricing readiness does not change stock quantities or silently deactivate the Product.
- Existing negative Gross Profit remains visible. This safeguard prevents unintended future sales; it does not erase legitimate promotional losses or rewrite historical records.

## Acceptance examples

- Seeded Product with stock, unknown cost and zero selling price: blocked.
- Positive regular price but no batch or confirmed Product cost: blocked, with or without a Promotion.
- Explicitly confirmed free goods, positive selling price: allowed, subject to existing stock checks.
- Batch cost PHP 12, Product cost PHP 10, regular selling price PHP 11, no Promotion: blocked using the batch cost.
- No recorded batch cost, confirmed Product cost PHP 10, selling price PHP 11: allowed using an estimated cost of PHP 10.
- Valid pricing and an eligible selected Administrator Promotion reduce payment below cost while payment remains positive: allowed.
- A Promotion for one Product does not authorize an unrelated below-cost Product in the same cart.
- A 100% discount, a fixed discount covering the whole eligible subtotal, or a discount rounded to zero payable: blocked even if other items have positive payment.
- A price, cost or Promotion change after quote review is rechecked before completing payment.

## Implementation scope and verification

The implementation reuses checkout, FEFO allocation, cost-provenance and Promotion mechanisms. No new approval workflow, dependency or database migration is needed. The existing allocator retains its historical missing-cost semantics; checkout applies the agreed fallback only to the new sale allocation and records it as estimated. Legacy unbatched stock also receives an immutable estimated allocation with no invented batch.

Promotion refresh returns eligibility only, without a reviewed quote hash or payment token. This lets a Cashier discover an authorized Promotion for a below-cost cart. Ordinary quote review and final checkout still enforce every safeguard; sending the preview flag to checkout cannot bypass validation. Selected discounts are apportioned to matching lines to the cent, so an unrelated Product cannot borrow a Promotion and a free item cannot hide behind a positive cart total.

Verification completed against disposable MySQL/SQLite fixtures: expanded `checkout_quote_integration.php`, `mixed_form_checkout_pack_opening_test.php`, `gross_profit_historical_costs_test.php`, `sold_stock_cost_preservation_test.php`, and `known_receiving_cost_mysql_test.php` passed. Coverage includes missing/confirmed-zero costs, batch versus Product priority, mixed batches, Sales Packs, scoped promotional losses, zero/rounded-zero payments, immutable fallback snapshots, unbatched stock, quote changes, atomic refusals and concurrent retry. `checkout_quote_browser_test.js`, `checkout_attempt_behavior_test.js`, and `npm run check:contracts` passed. The quote fixtures were brought up to date with the current single-store schema and current POS bindings.

The original zero-price regression failed with `Expected refusal: selling price` before the fix and passed afterward. `npm run check` passed, with an existing duplicate `open-register` ID warning in the unrelated Cashier Shifts view. PHP syntax checks passed for both services and the POS controller. No live Store records were modified.

TestSprite basic POS checkout passed all 20 steps in a disposable synthetic Store: run `868c2ce5-9f07-4ed5-bffc-3b3d0d9b69fb`, [execution result](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/d97dfbd9-de82-4c1e-9f7d-564e7a16ef25/test/868c2ce5-9f07-4ed5-bffc-3b3d0d9b69fb). This verifies the normal authenticated POS/cart/payment/receipt flow; the new pricing refusal scenarios are covered by the database-backed regression suite, not claimed as TestSprite coverage.

No interview decisions remain open. An ADR is unnecessary for this reversible validation change; the agreed distinction between missing and confirmed zero cost is recorded in `GLOSSARY.md`.

## POS add-to-cart follow-up — 2026-11-25

The user confirmed that rejecting checkout alone was too late: unpriced stock could still appear in Current sale. POS cards now retain their stock count but show a disabled **Pricing incomplete** button when regular selling price or usable purchase cost is missing. A missing selling price is labelled **Price not set**, rather than advertised as PHP 0. The label wraps within mobile controls.

The catalog carries pricing-readiness metadata without exposing purchase-cost amounts. Confirmed Product cost, including explicitly recorded zero, or a recorded cost on eligible batch/receiving stock can establish available cost. The catalog flag is an initial indication; online barcode scans and quick-adds revalidate the actual requested piece/pack quantities through the shared sale-time cost checks before changing the cart. A stale card cannot authorize adding stock with newly missing pricing. This validation does not require choosing a Promotion before the Cashier adds otherwise priced stock, and preserves the final below-cost checkout checks.

Offline additions require cached pricing-readiness metadata; old cache entries without it need an online refresh. Existing invalid cart drafts are retained rather than silently deleted; the Cashier may remove them, and final checkout remains blocked. No stock, pricing or historical sale records are changed by a rejected addition.

`RUN_DB_TESTS=1 node src/backend/tests/pos_pricing_readiness_browser_test.js` initially failed because the zero-price scan returned HTTP 200 instead of 409. It now passes, covering disabled cards, direct/scanned/quick additions, stale-card validation, confirmed zero cost, recorded batch cost, Product fallback, Sales Pack selection without a separate barcode, and unchanged stock/sales. Desktop 1440px light and mobile 390px dark captures were visually inspected; wrapping and page overflow checks passed. Offline-scan, keyboard-scan, cart-quantity, checkout integration and quote-browser tests passed, as did `npm run check` and `npm run check:contracts`. The offline fixture now supplies readiness metadata and settles its online-event refresh before replacing the response.

The older `pos_mixed_form_cart_test.js` cannot run against current POS rendering because its element mock lacks `addEventListener`; this existing fixture issue was inspected and left unchanged. Real Sales Pack addition is covered by the new authenticated browser fixture, and mixed-form fulfillment by the existing PHP checkout regression.

TestSprite follow-up **POS blocks unpriced stock before adding to cart** passed all 12 steps in a disposable Store: run `c93983b1-5339-4ce8-9cc6-af878bc8e428`, [execution result](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/eaa3932e-c8d6-4660-b064-4abb6a58e0b8/test/c93983b1-5339-4ce8-9cc6-af878bc8e428). It verified visible disabled pricing controls, zero-price and missing-cost scan refusals with an empty cart, and successful addition of a valid priced Product.

## Source observations

- `src/backend/scripts/seed_philippine_products.php` deliberately seeds zero selling price, zero cost, unknown-cost status and zero stock.
- Normal Product creation validates positive selling price and permits explicitly entered zero cost.
- `src/backend/app/Services/SalesWorkflowService.php` uses the catalog selling price during checkout without a positive-price safeguard at the observed calculation point.
- Existing sales support explicitly selected eligible Promotions; custom Cashier discounts are rejected.

These are source observations, not a reproduction of the user's specific negative-profit transactions. No historical sales or financial records have been changed.
