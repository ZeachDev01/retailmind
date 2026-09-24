<?php
// Friendly alerts 04 (ticket #56): Inventory Manager operation alerts.
//
// Contract for the Inventory Manager surfaces in shop mode: the duplicate
// product-name hint, the product photo size and type limits, and the
// receiving, count, replenishment, stock-monitoring, and inventory-control
// failure alerts. Each shows its aligned easy sentence with the correct red
// or yellow kind; no raw getMessage output, database text, or file path
// reaches the Inventory Manager; full exception text still goes to server
// logs at the page boundary through OperatorAlert with the existing
// Protected Audit Record trails kept; and with debug on the alert leads with
// the easy line plus the grey tech line through the shared wrapper.

require_once __DIR__ . '/../bootstrap/app.php';

use App\Support\OperatorAlert;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$setDebug = static function (bool $debug): void {
    if (!isset($GLOBALS['app']) || !is_array($GLOBALS['app'])) {
        $GLOBALS['app'] = [];
    }
    $GLOBALS['app']['debug'] = $debug;
};

$logPath = tempnam(sys_get_temp_dir(), 'retailmind-inventory-alert-');
$previousLog = ini_get('error_log');
ini_set('error_log', $logPath);

try {
    $root = dirname(__DIR__, 3);
    $read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);

    $products = $read('src/frontend/components/inventory_management/products.php');
    $receiving = $read('src/frontend/components/report/stock_receiving.php');
    $counts = $read('src/frontend/components/inventory_management/inventory_counts.php');
    $reorder = $read('src/frontend/components/inventory_management/reorder_planner.php');
    $replenishment = $read('src/frontend/components/inventory_management/replenishment_requests.php');
    $insights = $read('src/frontend/components/inventory_management/inventory_insights.php');
    $suppliers = $read('src/frontend/components/inventory_management/suppliers.php');
    $promotions = $read('src/frontend/components/inventory_management/promotions.php');
    $print = $read('src/frontend/components/inventory_management/print_barcodes.php');
    $csv = $read('src/frontend/components/inventory_management/csv_import.php');
    $purchaseOrders = $read('src/frontend/components/invoice/purchase_orders.php');
    $managerStock = $read('src/frontend/components/inventory_management/stock_issues.php');

    foreach ([
        'products' => $products,
        'stock receiving' => $receiving,
        'inventory counts' => $counts,
        'reorder planner' => $reorder,
        'replenishment requests' => $replenishment,
        'inventory insights' => $insights,
        'suppliers' => $suppliers,
        'promotions' => $promotions,
        'print barcodes' => $print,
        'csv import' => $csv,
        'purchase orders' => $purchaseOrders,
        'manager stock issues' => $managerStock,
    ] as $name => $source) {
        $assert($source !== '', ucfirst($name) . ' must be readable');
    }

    $leakNeedles = ['SQLSTATE', 'Stack trace', 'getMessage', '.php:', 'PDOException', 'Object of class'];
    $assertTechnicalFree = static function (string $name, string $line) use ($assert, $leakNeedles): void {
        $assert(!OperatorAlert::isTechnicalMessage($line), ucfirst($name) . ' easy sentence must stay free of technical words, got: ' . $line);
        foreach ($leakNeedles as $needle) {
            $assert(!str_contains($line, $needle), ucfirst($name) . ' easy sentence must not contain ' . $needle);
        }
    };

    // --- AC1: duplicate product name shows the yellow Attention hint ---
    $duplicateHint = 'A product with the same name already exists. Add a variant label when appropriate.';
    $assert(str_contains($products, $duplicateHint), 'Duplicate product name must show the variant-label hint');
    $assert(
        str_contains($products, "RetailMindUI.toast('" . $duplicateHint . "', 'warning')"),
        'Duplicate product name must raise a yellow Attention Operator Alert'
    );
    $assert(
        !str_contains($products, "'Possible duplicate'"),
        'The duplicate hint must use the Attention title, not a custom title'
    );
    $assertTechnicalFree('duplicate hint', $duplicateHint);

    // --- AC2: photo limits state size and type in plain words ---
    $photoLimit = 'Choose a product photo that is a JPEG, PNG, GIF, or WebP image no larger than 5MB, then try again.';
    $assert(str_contains($products, $photoLimit), 'Photo limit failure must state the size and type limit in plain words');
    $assertTechnicalFree('photo limit', $photoLimit);
    $assert(
        str_contains($products, 'RetailMindUI.toast(PRODUCT_PHOTO_LIMIT_MESSAGE, \'warning\')'),
        'Photo limit failures must raise the yellow Attention Operator Alert in the wizard'
    );
    $assert(
        str_contains($products, 'throw new RuntimeException(PRODUCT_PHOTO_LIMIT_MESSAGE)'),
        'Server-side photo limit failures must throw the same plain sentence'
    );
    $assert(
        str_contains($products, '.includes(file.type)'),
        'Client photo validation must check the allowed image types'
    );
    $assert(
        str_contains($products, "redirect_products('warning'"),
        'Retryable photo failures must redirect as the yellow warning kind'
    );
    foreach ([
        'Only image files (JPEG, PNG, GIF, WebP) are allowed.',
        'Image file must not exceed 5MB.',
        'Please select a valid image file.',
        'Image upload failed: ',
    ] as $old) {
        $assert(!str_contains($products, $old), 'Old photo wording must be replaced by the aligned limit sentence (found: ' . $old . ')');
    }

    // --- AC3: easy sentences with the correct red or yellow kind ---
    $easyLines = [
        'receiving' => ['The receiving record could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.', $receiving],
        'count save' => ['The inventory count could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.', $counts],
        'reorder planning' => ['The replenishment requests could not be saved. Check your selection and try again. Tell your Administrator if this keeps happening.', $reorder],
        'replenishment request' => ['The replenishment request could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.', $replenishment],
        'cycle schedule' => ['The cycle-count schedule could not be generated. Refresh the page and try again. Tell your Administrator if this keeps happening.', $insights],
        'supplier save' => ['The supplier details could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.', $suppliers],
        'promotion save' => ['The promotion could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.', $promotions],
        'barcode generate' => ['The barcode could not be generated. Try again. Tell your Administrator if this keeps happening.', $print],
        'csv row import' => ['This row could not be imported. Check the row values and try again. Tell your Administrator if this keeps happening.', $csv],
        'purchase order' => ['The purchase order could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.', $purchaseOrders],
    ];
    foreach ($easyLines as $name => [$line, $source]) {
        $assert(str_contains($source, $line), ucfirst($name) . ' failure must show its aligned easy sentence');
        $assert(str_contains($line, 'Tell your Administrator'), ucfirst($name) . ' easy sentence must say who to tell if it repeats');
        $assertTechnicalFree($name, $line);
    }

    // Red Unable to continue containers for blocked inventory failures.
    $redContainers = [
        'receiving' => $receiving,
        'inventory counts' => $counts,
        'reorder planner' => $reorder,
        'replenishment requests' => $replenishment,
        'inventory insights' => $insights,
        'suppliers' => $suppliers,
        'promotions' => $promotions,
        'purchase orders' => $purchaseOrders,
        'csv import' => $csv,
        'print barcodes' => $print,
    ];
    foreach ($redContainers as $name => $source) {
        if ($name === 'inventory counts') {
            $assert(str_contains($source, '<div class="alert-error">'), ucfirst($name) . ' failures must render in the red Unable to continue container');
            continue;
        }
        $assert(str_contains($source, '<div class="message error">'), ucfirst($name) . ' failures must render in the red Unable to continue container');
    }

    // No raw getMessage output reaches the Inventory Manager.
    foreach ([
        'reorder planner' => $reorder,
        'replenishment requests' => $replenishment,
        'inventory insights' => $insights,
        'suppliers' => $suppliers,
        'promotions' => $promotions,
        'print barcodes' => $print,
        'csv import' => $csv,
        'purchase orders' => $purchaseOrders,
    ] as $name => $source) {
        $assert(!str_contains($source, 'getMessage('), ucfirst($name) . ' must not surface getMessage output to the Inventory Manager');
        $assert(str_contains($source, 'OperatorAlert::message'), ucfirst($name) . ' must build the friendly message at the boundary so the full exception is logged');
        $assert(str_contains($source, 'catch (Throwable'), ucfirst($name) . ' must catch every failure at the boundary');
    }
    $assert(
        str_contains($receiving, 'catch (Throwable'),
        'Receiving must catch every failure at the boundary'
    );
    $assert(
        str_contains($managerStock, 'catch (Throwable'),
        'Manager stock review must catch every failure at the boundary'
    );
    $assert(
        !str_contains($csv, "'File upload error'") && str_contains($csv, 'The file could not be uploaded'),
        'CSV upload failures must show a plain sentence instead of raw upload text'
    );
    $assert(
        !str_contains($csv, "'Only CSV files are allowed'") && str_contains($csv, 'Choose a CSV file to import.'),
        'CSV type failures must show a plain sentence'
    );

    // --- AC4: full exception text stays in logs and audit; debug adds tech ---
    $setDebug(false);
    $nasty = new RuntimeException('SQLSTATE[HY000] [2002] Connection refused in /var/www/retailmind/src/backend/app/Core/Database.php:44');
    $countFriendly = $easyLines['count save'][0];
    $shown = OperatorAlert::message($nasty, $countFriendly);
    $assert($shown === $countFriendly, 'Shop mode must show only the easy line for a technical failure, got: ' . $shown);
    foreach ($leakNeedles as $needle) {
        $assert(!str_contains($shown, $needle), 'Shop mode must not leak ' . $needle);
    }
    $logged = (string)file_get_contents($logPath);
    $assert(str_contains($logged, 'SQLSTATE'), 'The full technical exception must be written to the server log');
    $assert(str_contains($logged, 'RuntimeException'), 'The log entry must record the exception class');
    $assert(
        str_contains($counts, 'log_activity') && str_contains($receiving, 'log_activity'),
        'Receiving and count failures must keep their Protected Audit Record trails'
    );

    $setDebug(true);
    $debugShown = OperatorAlert::message($nasty, $countFriendly);
    $assert(str_starts_with($debugShown, $countFriendly), 'Debug mode must still lead with the easy line');
    $assert(str_contains($debugShown, 'RuntimeException:'), 'Debug mode must include the grey tech line');
    $setDebug(false);
} catch (Throwable $exception) {
    $failures[] = 'Inventory alert contract threw: ' . $exception->getMessage();
} finally {
    ini_set('error_log', $previousLog === false ? '' : $previousLog);
    if (is_string($logPath) && is_file($logPath)) {
        @unlink($logPath);
    }
    $setDebug(false);
}

if ($failures) {
    fwrite(STDERR, "Inventory alert contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Inventory alert contract: passed\n";
