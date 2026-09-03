<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/barcode.php';
require_once __DIR__ . '/../../../backend/app/Services/ProductService.php';
require_role(['admin', 'super_admin', 'inventory_manager']);

$productService = new ProductService($pdo);
$productId = max(0, (int)($_REQUEST['product_id'] ?? 0));
$productIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_REQUEST['product_ids'] ?? ''))), static fn(int $id): bool => $id > 0)));
if ($productId > 0 && !$productIds) {
    $productIds = [$productId];
}
$quantity = max(1, min(200, (int)($_REQUEST['quantity'] ?? 1)));
$sizeKey = (string)($_REQUEST['size'] ?? '40x25');
$allowedSizes = [
    '40x25' => ['width' => 40, 'height' => 25, 'label' => '40 × 25 mm'],
    '50x30' => ['width' => 50, 'height' => 30, 'label' => '50 × 30 mm'],
    '60x40' => ['width' => 60, 'height' => 40, 'label' => '60 × 40 mm'],
];
if (!isset($allowedSizes[$sizeKey])) {
    $sizeKey = '40x25';
}
$labelSize = $allowedSizes[$sizeKey];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_barcode') {
    csrf_verify();
    try {
        $targetId = max(0, (int)($_POST['product_id'] ?? $productId));
        $productService->assignGeneratedBarcode($targetId, (int)$_SESSION['user_id']);
        header('Location: print_barcodes.php?product_id=' . $targetId . '&quantity=' . $quantity . '&size=' . urlencode($sizeKey) . '&autoprint=1');
        exit;
    } catch (Throwable $e) {
        header('Location: print_barcodes.php?product_id=' . $productId . '&quantity=' . $quantity . '&size=' . urlencode($sizeKey) . '&error=' . urlencode($e->getMessage()));
        exit;
    }
}

$products = $productService->getProductsForManagement();
$productsById = [];
foreach ($products as $item) {
    $productsById[(int)$item['product_id']] = $item;
}
$selectedProducts = [];
foreach ($productIds as $id) {
    if (isset($productsById[$id])) {
        $selectedProducts[] = $productsById[$id];
    }
}
$printableProducts = [];
$missingBarcodeProducts = [];
$barcodeErrors = [];
foreach ($selectedProducts as $item) {
    $barcode = trim((string)($item['barcode'] ?? ''));
    if ($barcode === '') {
        $missingBarcodeProducts[] = $item;
        continue;
    }
    try {
        $item['_barcode_svg'] = code128_svg($barcode, 58, 2);
        $printableProducts[] = $item;
    } catch (Throwable $e) {
        $barcodeErrors[] = $item['product_name'] . ': ' . $e->getMessage();
    }
}
$autoPrint = !empty($_GET['autoprint']) && $printableProducts;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printable Barcode Labels</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css')) ?>">
</head>
<body>
<div class="barcode-page">
    <section class="toolbar no-print">
        <h1>Printable Barcode Labels</h1>
        <p>Create labels for one product or print a bulk selection from Products &amp; Stock.</p>
        <form method="GET" class="toolbar-form">
            <div class="product-field"><label for="product_id">Product</label><select name="product_id" id="product_id" required><option value="">Select a product</option><?php foreach ($products as $item): $itemBarcode = trim((string)($item['barcode'] ?? '')); ?><option value="<?= (int)$item['product_id'] ?>" <?= count($productIds) === 1 && (int)$item['product_id'] === $productIds[0] ? 'selected' : '' ?>><?= htmlspecialchars($item['product_name']) ?> — <?= htmlspecialchars($itemBarcode !== '' ? $itemBarcode : 'No barcode') ?></option><?php endforeach; ?></select></div>
            <div><label for="quantity">Labels each</label><input type="number" name="quantity" id="quantity" value="<?= $quantity ?>" min="1" max="200" required></div>
            <div><label for="size">Label size</label><select name="size" id="size"><?php foreach ($allowedSizes as $key => $size): ?><option value="<?= htmlspecialchars($key) ?>" <?= $key === $sizeKey ? 'selected' : '' ?>><?= htmlspecialchars($size['label']) ?></option><?php endforeach; ?></select></div>
            <button class="btn" type="submit"><i class="bi bi-upc-scan"></i>Create Labels</button>
        </form>
        <div class="toolbar-actions"><a class="btn u-button-slate" href="products.php"><i class="bi bi-arrow-left"></i>Back to Products</a><?php if ($printableProducts): ?><button class="btn u-button-orange" type="button" onclick="window.print()"><i class="bi bi-printer"></i>Print <?= count($printableProducts) * $quantity ?> Label(s)</button><?php endif; ?></div>
        <?php if (count($selectedProducts) > 1): ?><div class="selection-summary"><strong><?= count($selectedProducts) ?> products selected.</strong> Each printable product will receive <?= $quantity ?> label(s).</div><?php endif; ?>
        <?php if (!empty($_GET['error'])): ?><div class="message"><?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>
        <?php if ($missingBarcodeProducts): ?><div class="message"><strong><?= count($missingBarcodeProducts) ?> product(s) were skipped because they have no barcode:</strong> <?= htmlspecialchars(implode(', ', array_column($missingBarcodeProducts, 'product_name'))) ?>. Generate their internal barcodes from Products &amp; Stock.</div><?php endif; ?>
        <?php if ($barcodeErrors): ?><div class="message"><?= htmlspecialchars(implode(' | ', $barcodeErrors)) ?></div><?php endif; ?>
        <?php if (count($selectedProducts) === 1 && !$printableProducts && $missingBarcodeProducts): $missing = $missingBarcodeProducts[0]; ?><form class="u-mt-065" method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="generate_barcode"><input type="hidden" name="product_id" value="<?= (int)$missing['product_id'] ?>"><input type="hidden" name="quantity" value="<?= $quantity ?>"><input type="hidden" name="size" value="<?= htmlspecialchars($sizeKey) ?>"><button class="btn u-button-green" type="submit">Generate Barcode and Print</button></form><?php endif; ?>
    </section>

    <?php if ($printableProducts): ?>
        <main class="sheet" aria-label="Barcode label sheet">
            <?php foreach ($printableProducts as $item): ?>
                <?php for ($index = 0; $index < $quantity; $index++): ?>
                    <article class="barcode-label">
                        <div class="label-product" title="<?= htmlspecialchars($item['product_name']) ?>"><?= htmlspecialchars($item['product_name']) ?></div>
                        <?= $item['_barcode_svg'] ?>
                        <div class="label-code"><?= htmlspecialchars($item['barcode']) ?></div>
                        <div class="label-footer"><span><?= htmlspecialchars($item['brand'] ?: ($item['category_name'] ?: 'RetailMind')) ?></span><span class="label-price">₱<?= number_format((float)$item['unit_price'], 2) ?></span></div>
                    </article>
                <?php endfor; ?>
            <?php endforeach; ?>
        </main>
    <?php else: ?>
        <main class="sheet empty-sheet"><div><i class="bi bi-upc-scan u-icon-large"></i><br>Select a product with a barcode to create printable labels.</div></main>
    <?php endif; ?>
</div>
<?php if ($autoPrint): ?><script>window.addEventListener('load',()=>window.print());</script><?php endif; ?>
</body>
</html>
