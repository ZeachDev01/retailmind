# Tablet inventory recovery

Inventory Managers can keep unfinished Products (including selected photos), Inventory Counts, Stock Receiving, Stock Issue reviews, and Supplier Pullouts on the current browser and device. Resume and Discard are explicit. Resume restores entered values and context; it never submits a form or changes stock.

## Setup

Apply pending database migrations, including `202610250002_inventory_recovery`. Serve the Store application over HTTPS when accessed through its LAN address or hostname. Modern browsers expose the required WebCrypto encryption API only in a secure context; development loopback addresses are an exception. Plain LAN HTTP can still save online inventory, but shows that encrypted draft recovery is unavailable. Do not promise draft preservation in that state.

The application holds a random, persistent encryption key for each Inventory Manager in `inventory_draft_keys`. Only the authenticated, authorized manager page receives that user's key. The browser stores AES-GCM encrypted snapshots, with a new random nonce for each write, in localStorage. It does not persist the unlock key. Other users' ciphertext cannot be unlocked by the current account. Authenticated pages use `Cache-Control: no-store`, and a page restored from the browser back/forward cache reloads before use. Product drafts from the previous unowned plaintext storage and legacy receiving session drafts are removed rather than shown to a different operator.

Keep the page open until the “Unsaved values kept” status appears. Storage denial, exhausted quota (including a large photo), unavailable key storage, or a missing secure context produces an explicit preservation warning. The app cannot recover drafts after browser data deletion or eviction, device loss, or deletion of the original account/key. This is device-local recovery, not cross-device synchronization.

## Saving and retry

Drafts retain the exact operation token until confirmed submission or explicit discard. Inventory operations record their result in `inventory_submissions` inside the same transaction as the business change. An explicit retry after a lost server response returns the recorded outcome; it does not repeat receiving, count creation/approval, Product opening stock, emergency stock adjustment, Supplier Pullout, or Stock Issue decisions. A committed token with changed values is refused so operators inspect the saved operation before starting another.

Permissions and existing Product, batch, quantity, duplicate-code, and stock rules remain server-enforced. Restoring dependent forms loads current Product and batch context before applying entered values. Drafts never bypass these rules or post automatically when a connection returns. A failed restoration keeps Resume and Discard available.

Product scanners accept an Enter-terminated scan into the explicitly selected barcode field. During Product entry, scans into other text, number, and selection controls are rejected and their prior values restored. Outside entry, existing Product lookup opens a match or reports Product not found; it creates nothing. Burst timing is a software heuristic and needs physical hardware verification.

## Verification

`RUN_DB_TESTS=1 node src/backend/tests/inventory_private_recovery_browser_test.js` exercises authenticated browser/HTTP recovery on a disposable MySQL database: encrypted local values, same-account unlock after a Chromium process restart, another Inventory Manager's isolation, explicit Resume/Discard, confirmed clearing, lost-response retry with one persisted receiving record/movement, dynamic Count rows, current Pullout batch selection, Stock Issue notes, Product fields/photo, existing Product editor context, and storage denial. `RUN_DB_TESTS=1 php src/backend/tests/batch_receiving_mysql_test.php` checks receiving replay, changed-token-payload rejection, stock totals, and rollback. Product keyboard scan coverage is in `products_keyboard_scan_browser_test.js`.

Chromium responsive emulation is not physical acceptance. Physical iPad Safari, iPad Chrome, Android Chrome, tablet restart, USB wireless-receiver/adapter, and Bluetooth scanner combinations remain pending. Record device model, OS/browser, scanner, and adapter details when those checks are performed.
