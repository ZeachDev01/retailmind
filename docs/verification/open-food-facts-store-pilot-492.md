# Open Food Facts Store pilot

On 2026-11-18, the pilot used the configured Store database as its reference, as agreed with the Store operator. The read-only sample covered one active, in-stock Product with a supported, valid Individual Item barcode. An exact-barcode lookup requested the same fields as the [RetailMind catalog integration](../../src/backend/app/Services/OpenFoodFactsCatalog.php). See the [Open Food Facts v3 product lookup documentation](https://openfoodfacts.github.io/documentation/docs/Product-Opener/v3/products/get-api-v3-product-code/).

| Lookup outcome | Count |
| --- | ---: |
| Products sampled | 1 |
| Catalog records returned | 0 |
| Catalog misses | 1 |
| Incomplete records observed | 0 |
| Incorrect records observed | 0 |
| Provider failures | 0 |

Open Food Facts returned HTTP 404 with `product_not_found`. The counts for incomplete and incorrect records reflect the absence of any returned record.

| Comparison | Comparable results | Matches or useful photos |
| --- | ---: | ---: |
| Product name | 0 | 0 |
| Variant | 0 | 0 |
| Declared package size | 0 | 0 |
| Front photo | 0 | 0 |

The single lookup measures catalog availability for this sample. Name, variant, size, and photo accuracy remain unmeasured. The Store operator will verify physical packaging separately.

The [automated catalog checks](../../src/backend/tests/product_create_barcode_browser_test.js) and [photo checks](../../src/backend/tests/product_catalog_photos_browser_test.js) use [provider fixtures](../../src/backend/tests/support/open_food_facts_fixture.php). The table above reports only the live pilot. [Pilot issue #492](https://github.com/ZeachDev01/retailmind/issues/492)
