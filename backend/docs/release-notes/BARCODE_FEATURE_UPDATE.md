# Printable Barcode Feature

## What was added

- Products without manufacturer barcodes can now receive an automatically generated internal RetailMind barcode.
- The Add Product form supports **Scan**, **Generate**, or leaving the barcode blank for server-side generation.
- A product can be saved and immediately opened as printable barcode labels.
- Existing products with an empty barcode show a **Generate & Print** action.
- Printable Code 128 labels support 1–200 copies and three label sizes:
  - 40 × 25 mm
  - 50 × 30 mm
  - 60 × 40 mm
- Labels contain the product name, generated/scanned barcode, brand or category, and selling price.
- CSV product imports may leave the SKU and barcode fields blank. RetailMind generates an internal barcode and uses it as the SKU.

## How to use

1. Open **Inventory Manager → Products & Stock**.
2. Add a product.
3. For a product without a barcode, click **Generate** or leave the Barcode field blank.
4. Keep **Open printable barcode labels after saving** selected.
5. Choose the number of labels and save the product.
6. On the label page, choose the label size and click **Print Labels**.

No database migration is required.
