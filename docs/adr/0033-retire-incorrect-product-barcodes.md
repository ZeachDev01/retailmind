# Retire incorrect Product barcodes on correction

When an Inventory Manager corrects a Product barcode, replace the incorrect code on the same Product. If RetailMind originally used that Base Unit barcode as the automatic SKU, update the SKU in the same save so the old code no longer resolves through SKU lookup. Preserve a custom SKU and never assume that matching text proves a SKU was assigned automatically.

Validate the replacement against the Store's existing barcode and SKU assignments, including inactive Products. Record the old and new codes in activity history. Staff should reprint affected labels and update any CSV files that use the changed automatic SKU before importing again.
