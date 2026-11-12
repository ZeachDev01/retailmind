# Add Product pricing modes

Status: User approved live implementation on 2026-11-11 after reviewing the prototype. Implemented in the Add Product wizard, including the subsequently approved Sales Pack count prefill and combined cost/selling comparison.

## Problem

Previously, Step 3 put pack fields before individual pricing and separated the individual cost from the selling price. Products sold only as loose pieces were difficult to price at a glance. The calculator also used Sales Pack size for purchase-cost division, coupling cost entry to sealed-pack selling requirements.

## Agreed decisions

- Step 3 offers By piece and By pack cost-entry modes, with cost before selling price.
- By piece shows Cost Price per Piece and Selling Price per Piece.
- By pack accepts purchase package cost and its piece count, computes cost per piece, and shows Selling Price per Piece. For example, PHP 120 / 12 pieces gives PHP 10 cost per piece.
- By pack is a purchase-cost calculation choice. It does not enable Sales Pack selling or require a Sales Pack price or barcode.
- Sell sealed packs is a separate choice. Preserve the existing [Sales Pack rules](sealed-pack-sales-and-prepared-oil-pouches.md).
- Selling price remains manually editable in either mode. Markup is optional and serves only as a calculation aid.
- New products default to By piece.
- In By pack mode, cost per piece is computed and read-only. Correct the purchase package cost or piece count to change it.
- Switching from By pack to By piece carries the computed cost into the editable cost field. Returning to By pack restores the entered package cost and piece count and derives cost from those inputs.
- Mode changes preserve the selling price and entered values. Hidden purchase package fields are retained for switching back, but do not determine the active By piece cost or enable Sales Packs.
- Applying markup requires an explicit Apply action. Editing inputs or changing modes must not silently replace a manually entered selling price.
- When enabling Sell sealed packs in By pack mode, prefill an empty Pieces per Sales Pack from the valid purchase package piece count. Preserve an existing Sales Pack count. Keep the count editable because purchase packaging and customer Sales Packs can differ. In By piece mode, enter the Sales Pack count manually.
- The Sales Pack discount guide compares Sales Pack price with Pieces per Sales Pack multiplied by Selling Price per Piece, not Cost Price per Piece. For example, 12 pieces at PHP 15 each have a separate selling total of PHP 180.
- Show Pack cost (saved, rounded Cost Price per Piece × Pieces per Sales Pack) and Sold individually (Selling Price per Piece × Pieces per Sales Pack) together in the discount guide. The cost uses the customer Sales Pack count, which can differ from the purchase package count.

## Pricing clarity follow-up — 2026-11-12

The user reconfirmed the owner's requirement: the same Product may be sold individually and as a sealed Sales Pack, with the Sales Pack cheaper than buying the same number of pieces individually. This preserves the existing Sales Pack definition and pricing rule.

The previous By piece / By pack cost-entry labels were confusing because they could be read as customer selling choices. The user approved separating Purchase cost (Enter cost per piece / Calculate from purchase pack) from Selling prices (Selling Price per Piece and optional Also sell sealed packs). The existing cost calculations and Sales Pack discount rules remain unchanged.

Implemented in Step 3. `npm run check` and `sales_pack_browser_test.js` passed, including desktop 1440px and mobile 390px pricing, pack validation, starting stock and submission checks. Mobile piece-mode and desktop sealed-pack captures were visually inspected. Browser verification uses the extracted wizard fixture; authenticated live rendering was not checked.

## Final confirmation

User approved implementation on 2026-11-11, then explicitly approved the editable Sales Pack count prefill and the combined Pack cost / Sold individually guide. No interview decisions remain open.

## Acceptance checks

- A fresh product starts in By piece with cost before selling price and no purchase-package inputs visible.
- By pack computes PHP 10 per piece from PHP 120 and 12 pieces without enabling Sales Pack fields or sealed-pack starting stock.
- A manual selling price survives cost edits and mode changes. Only an explicit Apply action uses markup to replace it.
- By pack to By piece retains the computed cost; switching back restores the package inputs. Inactive values must not override the active cost on save.
- Draft restore preserves the selected mode and its entered values; a fresh form resets to By piece.
- Invalid or incomplete active inputs block progression without losing entered values. Preserve existing pricing validation and rounding rules.
- Separately enabling Sales Pack selling retains its existing validation, stock entry, and Review behavior.
- Enabling Sales Pack selling from a purchase pack of 12 prefills an empty count with 12 pieces but permits changing the Sales Pack to 6; the discount guide then shows 6 times the saved individual cost alongside 6 times the individual selling price.

## Implementation reach

The live implementation reuses the existing wizard, pricing calculations, and encrypted draft persistence in `src/frontend/components/inventory_management/products.php`. Scoped styling lives in `src/frontend/assets/css/products.css` and its generated `src/frontend/assets/css/style.css` bundle. Step 3 validation, draft restore/reset, Step 4 starting-stock fields, Review, and the product POST payload follow the selected mode. Purchase-cost entry alone does not configure Sales Packs or change starting-stock fields. No database changes or ADR were needed for this reversible UI extension.

This extends the incumbent visual system: existing palette and typography, scoped mode buttons with pressed/focus states, grouped purchase inputs, read-only computed cost, and optional explicit markup. `docs/PRODUCT.md`, `docs/DESIGN.md`, and `.impeccable/design.json` remain unchanged; no new global design rules are established.

## Live verification — 2026-11-11

- Browser `sales_pack` and `product_create` checks passed at desktop 1440px and mobile 390px, covering validation, manual-price preservation, explicit markup, POST payload, reset, and real encrypted draft reload recovery with active, inactive, and legacy values. `products_draft_notice` passed.
- `npm run check` passed initially and on the final rerun. `npm run check:contracts` passed.
- Unchanged PHP integrations `batch_product_creation_receiving_test.php` and `sales_pack_configuration_reconciliation_test.php` could not complete because the SQLite test fixture lacks `scheduled_demand_drivers`. No database changes were made to bypass that fixture issue.
- All six implementation captures `.impeccable/review/product-pricing-{1440,390}-{piece,pack,sealed}.png` were validated and independently reviewed. Reviewer disposition: ship; no material fixes required.
- Browser checks rendered the actual extracted wizard, application CSS, and `UI.js` in a fixture; authenticated live browser rendering was not performed. The fixture omits the Bootstrap icon font; its missing icons are not an application regression.

## Prototype — 2026-11-11

The historical standalone [HTML preview](../mockups/product-pricing.html), available at [localhost](http://localhost/retailmind/docs/mockups/product-pricing.html), was used to review Step 3 before live implementation. It preserved the existing system-UI, navy/cool-neutral and Action Blue (`#2563eb`) language, with an 18px outer radius and 10px inputs. At that prototype stage, the live application was untouched. The live implementation described above now supersedes this preview.

Playwright assertions passed for default By piece mode, package-cost division, mode changes and value preservation, explicit Apply markup, invalid piece counts, below-cost selling validation, separate Sales Pack selection and discount validation, the pricing review dialog, and reset. No horizontal overflow was found at 320px, 390px, 768px or 1280px. All six captures in `.impeccable/review/pricing-*.png` were visually inspected; the initial Impeccable hook scan was clean, but a later stop hook identified undersized text, since fixed. The five mobile wizard labels increased from 10px to 12px; computed-size, no-overlap and no-overflow checks passed at 320px, 390px, 768px and 1280px, and the detector recheck reported no `undersized-ui-text` findings; advisory token differences remain unsuppressed, with no global design updates.

This historical preview has no backend, persistent drafts or full wizard. Its checks did not cover production draft restore, starting stock, product creation or whole-application behavior; live verification is recorded separately above.
