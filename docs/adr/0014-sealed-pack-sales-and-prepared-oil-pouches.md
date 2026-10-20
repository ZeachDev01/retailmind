---
status: accepted
---

# Sell sealed packs and prepared oil pouches without extra checkout steps

The owner sells individual pieces and cheaper sealed Sales Packs, identified by different barcodes. Use one Product with one fixed sales-pack size, separate piece and pack prices, and separate counts of unopened packs and loose pieces. A pack sale requires unopened stock; loose pieces cannot satisfy it. Starting stock is entered as unopened packs and loose pieces. Receiving must preserve whether accepted goods are sealed packs or loose pieces rather than flattening everything into loose stock.

Keep checkout as scan, quantity, and pay. Piece sales consume loose stock first; if insufficient, automatically open the minimum number of available sealed packs needed and retain the leftover loose pieces. Record Pack Opening with the completed sale atomically, without an extra cashier action or confirmation. An abandoned cart changes no stock. This deliberately grants checkout the ability to record opening existing stock; the physical cashier must use loose pieces first and open packs as needed. Pack barcodes charge the pack price; piece barcodes charge the piece price without an automatic pack-price discount.

Normal pack Cash Refunds require a sealed, complete pack, refund its original pack price, and restore sealed stock. Opened or incomplete returns remain outside this normal pack-refund path. Stock movements must preserve existing batch provenance, expiration, costs, and audit history as required by ADR-0013; opening a pack is neither a sale nor a receipt and must not create demand or revenue by itself.

Prepared Oil Pouches are two separately counted Products: 250 mL (1/4 litre) at PHP 20 and 500 mL (1/2 litre) at PHP 40. Staff add prepared pouches to stock; sales deduct whole pouches. Bulk-oil balances, pouch-production conversion, and decimal sale quantities are outside this design.

## Trade-off

A single total piece count is simpler but cannot guarantee unopened-pack availability. Separate sealed and loose counts preserve that distinction; automatic Pack Opening avoids an extra checkout step but depends on staff following the same physical allocation. Existing stock cannot safely be classified as sealed versus loose from a total alone and needs a physical count before enabling pack sales.

The user agreed to these rules during the design discussion on 2026-10-20 and requested a published specification. Application implementation remains pending. Vocabulary is recorded in [GLOSSARY.md](../../GLOSSARY.md); behavior and verification are detailed in the [specification](../specs/sealed-pack-sales-and-prepared-oil-pouches.md).
