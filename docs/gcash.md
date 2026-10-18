# GCash reference capture

Open **Cashier → GCash**, below Point of Sale. This is a separate transaction register: it does not complete a POS sale, move money, or change drawer balances, including after verification.

## Installation

Run the normal migration command against the intended database:

```powershell
C:/xampp/php/php.exe src/backend/scripts/migrate.php
```

Migration `202610180001_gcash_transactions` creates an InnoDB table with a unique reference across all cashiers and statuses. Fresh installations include the same table in `src/backend/database/sql/schema.sql`. Existing records are preserved. The normal migration runner has no automatic down command; retain the table when rolling back application code so reference history is not lost. Database Backup captures it using existing table discovery.

Optional `.env` configuration (restart PHP workers after changes):

```dotenv
GCASH_REFERENCE_MIN_LENGTH=13
GCASH_REFERENCE_MAX_LENGTH=13
```

These are local validation defaults, not a guarantee of every GCash receipt format. Both values must be between 6 and 32, and minimum must not exceed maximum. Leading zeroes are preserved. References permit digits; spaces and hyphens are removed. Amounts must be PHP 0.01–99,999,999.99 with at most two decimals.

## Scanning

Configure a USB scanner for **HID keyboard mode**, matching the Windows keyboard layout, preferably with an Enter suffix. Focus the reference field or click **Manual entry / USB scan**. Input is checked after a short pause or Enter. Enter never saves. A supported 2D barcode scanner can read phone QR codes; a typical 1D scanner cannot read QR codes or plain text.

Supported payloads: a numeric reference, `Reference No.: 0012345678901`, JSON string fields, or HTTP(S) URL query parameters named `reference_number`, `reference_no`, `ref_no`, `reference`, or `gcash_reference`. URLs are parsed locally and never opened. JSON numeric values are refused to avoid losing leading zeroes or precision. Conflicting references are refused. A merchant payment QR often identifies a payee, not a completed transaction; it must not be used as a receipt reference.

For plain text use manual entry, an OCR-capable device, or **Camera OCR**. Camera access requires HTTPS or localhost and browser permission. Tesseract.js 6.0.1, its worker/core and English language data download on demand from free public CDNs; internet access is needed for the initial downloads. Receipt images are processed in the browser and are not uploaded or stored by this application. OCR searches near Reference No./Ref No. labels, refuses ambiguous results, and shows an editable suggestion. Check every digit before choosing **Use this reference**. Closing the camera, capture, or leaving the page stops its tracks. No paid service or GCash API credentials are required.

## Saving and verification

**Save as Pending** checks the database, then inserts a pending record and audit entry in one transaction. The database unique key remains the final protection against concurrent submissions. If a request loses its response, refresh history before retrying. The same reference cannot create another record, including after rejection.

Only an authenticated Cashier workspace with POS capability may use this endpoint. Cashiers see and review their own records. Another cashier's duplicate is reported without exposing transaction details. Locked registers cannot save or review.

From history, choose **Verify / Reject**. Compare the actual reference, amount, and transaction type with the authorized receiving GCash account or its transaction records. A customer's screenshot or scanned reference is insufficient. Verification requires an explicit confirmation and review note; rejection requires a note. A record can leave Pending only once. Verification identity, review time, and before/after audit entries are saved atomically. These are staff attestations, not an automated payment confirmation. Cash-In and Cash-Out are labels for recording externally handled transactions; this page does not execute those operations.

Pending records cannot be edited after saving. Correct the reference/amount before saving; reject an incorrect saved record and refer to its audit trail. A rejected reference stays reserved to prevent reuse. Product scanning is unchanged: this page attaches HID listeners only to its reference field and loads no POS product scanner code.

## Checks

```powershell
node src/backend/tests/gcash_reference_test.js
$env:PHP_BINARY = 'C:/xampp/php/php.exe'
$env:RUN_DB_TESTS = '1'
node src/backend/tests/gcash_browser_test.js
```

The database/browser suite creates and removes a disposable `retailmind_theme_test_*` database. It requires local MySQL, Playwright Chromium, and permission to create disposable databases. OCR tests use a deterministic Tesseract stub; real phone displays, scanner hardware, lighting, and OCR accuracy require a device check.

OCR implementation follows the [Tesseract.js browser API](https://github.com/naptha/tesseract.js/blob/master/docs/api.md). Camera connection requirements follow [MDN getUserMedia guidance](https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia).
