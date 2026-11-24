# Philippine product seed

Run from the repository root. Preview the 50 products in 14 categories without
connecting to MySQL, downloading images, or writing products:

```powershell
php src/backend/scripts/seed_philippine_products.php --dry-run
```

Import into the configured local database (`DB_NAME` in `.env`):

```powershell
php src/backend/scripts/seed_philippine_products.php --confirm-local-database=inventory_system
```

Replace `inventory_system` if your database name differs. Requires the existing
database migrations, PHP cURL, internet access, writable backend storage, and
`APP_ENV=local` or `development`. Hosted targets are refused. The script runs no
migrations and never clears existing products.

`philippine_products.json` contains 50 real, distinct barcodes selected from the
Open Food Facts Philippine-market API response retrieved on 2026-11-24. Its
`source_url` records the exact API query. Categories are curated Store categories.
The saved snapshot keeps repeated imports stable; product metadata is not fetched
again at import time. Each missing product's actual front photo is downloaded
from Open Food Facts and saved to protected local product-image storage, with its
source product link retained for attribution. Existing seeded products are
skipped, including their photos and stock. A different existing Product using a
seed barcode or SKU causes the import to fail without overwriting that Product.

Stock, purchased and sold quantities start at **0**. Expiry remains **NULL**;
no stock batches, purchases, sales or stock movements are created. Prices start
at **0.00**, with purchase cost marked unknown: Open Food Facts supplies no
verified Store selling prices. **Set selling prices before receiving and selling
these products.** Existing Product prices and quantities survive repeat imports.

All new photos must download and pass image validation before the database
transaction starts. On failure, new photos are removed and no products are
imported. Retry when the image service is available.

Data: [Open Food Facts](https://world.openfoodfacts.org),
[ODbL 1.0](https://opendatacommons.org/licenses/odbl/1-0/).
Photos: Open Food Facts contributors,
[CC BY-SA 3.0](https://creativecommons.org/licenses/by-sa/3.0/).

Verification:

```powershell
php src/backend/tests/philippine_product_seed_test.php
$env:RUN_DB_TESTS='1'
php src/backend/tests/philippine_product_seed_integration_test.php
```

The integration test creates and drops only a randomly named disposable local
database and temporary image directory. It uses fixture image responses by
default. Set `RM_SEED_TEST_LIVE=1` to verify actual photo downloads as well.
Optional `DB_TEST_USER` / `DB_TEST_PASSWORD` supply disposable-database credentials.
