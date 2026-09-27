<?php
// Source contract for the Inventory Counts scan affordance (ticket #79):
// the page wires an Enter-terminated code lookup next to the Product dropdown,
// and the shared lookup endpoint stays authenticated and read-only with no
// mutation or paired-phone-token path.
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$pagePath = __DIR__ . '/../../frontend/components/inventory_management/inventory_counts.php';
$endpointPath = __DIR__ . '/../../frontend/components/barcodeScanner/apiScanner/product_code_lookup.php';
$servicePath = __DIR__ . '/../app/Services/ProductCodeLookupService.php';

foreach ([$pagePath, $endpointPath, $servicePath] as $path) {
    $assert(is_file($path), 'Expected file exists: ' . basename($path));
}
if ($failures) {
    fwrite(STDERR, "Inventory counts scan contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

$page = (string)file_get_contents($pagePath);
$endpoint = (string)file_get_contents($endpointPath);
$service = (string)file_get_contents($servicePath);

// Matches SQL writes, not prose such as "never exposes an update operation".
$writeStatementPattern = '/\b(INSERT\s+INTO|UPDATE\s+[A-Za-z_]+\s+SET|DELETE\s+FROM)\b/i';

// The scan field sits outside the count form so Enter can never submit it.
$formStart = strpos($page, '<form method="POST" class="count-form">');
$scanField = strpos($page, 'id="product_code_scan"');
$assert($formStart !== false && $scanField !== false, 'Count page renders the scan field');
$assert($scanField < $formStart, 'Scan field must not be a value that posts with the count form');
$assert(strpos($page, 'product_code_lookup.php') !== false, 'Count page targets the shared product code lookup');
$assert(strpos($page, "event.key !== 'Enter'") !== false, 'Scan field lookup runs on Enter');
$assert(strpos($page, 'physicalInput.focus()') !== false, 'A match focuses the physical quantity field');
$assert(strpos($page, "outcome === 'match'") !== false, 'Page handles the match outcome');
$assert(strpos($page, "outcome === 'ambiguous'") !== false, 'Page handles the ambiguous outcome');
$assert(strpos($page, "No product matches") !== false, 'Page shows a retry/manual-selection message for unknown codes');
$assert(strpos($page, 'productSelect.dispatchEvent') !== false, 'A match selects the product in the existing dropdown');
preg_match_all('/physicalInput\.value\s*=(?!=)/', $page, $assignments);
$assert(
    count($assignments[0]) === 1 && strpos($page, 'physicalInput.value = burstValue') !== false,
    'Scan handling never assigns a counted total of its own'
);
$assert(
    strpos($page, '/[^\d]/') !== false,
    'A wedge code holding a non-digit is always read as a scan, never as a counted total'
);
$assert(
    (bool)preg_match('/<input[^>]*name="physical_quantity"[^>]*\srequired\b/', $page),
    'Physical quantity stays a required, explicitly entered field'
);
$assert(
    (bool)preg_match('/<textarea[^>]*name="discrepancy_reason"[^>]*\srequired\b/', $page),
    'Discrepancy reason stays required'
);

// Endpoint: authenticated, permitted role, read-only, no paired-phone token.
$assert(strpos($endpoint, "header('Content-Type: application/json')") !== false, 'Lookup answers as JSON');
$assert(strpos($endpoint, 'is_logged_in()') !== false, 'Lookup requires an authenticated session');
$assert(strpos($endpoint, "'admin', 'inventory_manager'") !== false, 'Lookup allows only the Inventory Counts roles');
$pageRoles = preg_match('/require_role\(\[\s*([^\]]+?)\s*\]\)/', $page, $pageRoleMatch) === 1
    ? preg_replace('/\s+/', ' ', trim($pageRoleMatch[1]))
    : null;
$lookupRoles = preg_match('/current_workspace_roles\(\),\s*\[\s*([^\]]+?)\s*\]/', $endpoint, $lookupRoleMatch) === 1
    ? preg_replace('/\s+/', ' ', trim($lookupRoleMatch[1]))
    : null;
$assert($pageRoles !== null, 'Count page gates on an explicit role list');
$assert(
    $pageRoles !== null && $pageRoles === $lookupRoles,
    'Count page and lookup agree on the Inventory Counts roles'
);
$assert(strpos($endpoint, 'REQUEST_METHOD') !== false && strpos($endpoint, '405') !== false, 'Lookup refuses non-read methods');
$assert(stripos($endpoint, 'update_stock') === false, 'Lookup exposes no stock-update operation');
$assert(
    stripos($endpoint, 'pairing_token') === false && stripos($endpoint, 'phone_token') === false,
    'Lookup accepts no paired-phone token'
);
$assert(!preg_match($writeStatementPattern, $endpoint), 'Lookup performs no write statement');

// Service: exact, unbounded, unfiltered, unambiguous.
$assert(!preg_match($writeStatementPattern, $service), 'Lookup service performs no write statement');
$assert(strpos($service, 'LIMIT') === false, 'Lookup service applies no page limit');
$assert(stripos($service, 'quantity_on_hand >') === false && stripos($service, 'quantity_on_hand>') === false,
    'Lookup service applies no positive-stock filter');
$assert(strpos($service, "p.status = 'active'") !== false, 'Lookup service matches active products only');
$assert(strpos($service, 'case_barcode') !== false && strpos($service, 'barcode') !== false && strpos($service, 'sku') !== false,
    'Lookup service matches SKU, unit barcode and case barcode');
$assert(strpos($service, 'OUTCOME_AMBIGUOUS') !== false, 'Lookup service refuses ambiguous codes');

if ($failures) {
    fwrite(STDERR, "Inventory counts scan contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Inventory counts scan contract: passed\n";
