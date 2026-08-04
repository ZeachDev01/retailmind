<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/barcode.php';
require_once __DIR__ . '/../../app/Services/ProductService.php';
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
    <style>
        :root { --label-width: <?= (int)$labelSize['width'] ?>mm; --label-height: <?= (int)$labelSize['height'] ?>mm; }
        body { margin:0;background:#e5e7eb;color:#111827; }
        .barcode-page { min-height:100vh;padding:1.25rem; }
        .toolbar { max-width:1100px;margin:0 auto 1rem;padding:1rem;background:#0f172a;color:#e2e8f0;border-radius:14px;box-shadow:0 10px 28px rgba(15,23,42,.16); }
        .toolbar h1 { margin:0 0 .3rem;color:#f8fafc; }
        .toolbar p { color:#94a3b8;margin-bottom:.9rem; }
        .toolbar-form { display:grid;grid-template-columns:minmax(240px,1fr) 110px 150px auto;gap:.75rem;align-items:end; }
        .toolbar label { display:block;margin-bottom:.3rem;font-size:.88rem;color:#cbd5e1; }
        .toolbar select,.toolbar input { width:100%;box-sizing:border-box; }
        .toolbar-actions { display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.9rem; }
        .toolbar .btn { text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:.4rem; }
        .message { margin-top:.8rem;padding:.75rem .9rem;border-radius:9px;background:#422006;color:#fde68a; }
        .selection-summary { margin-top:.75rem;padding:.65rem .8rem;border:1px solid rgba(255,255,255,.1);border-radius:9px;color:#cbd5e1;font-size:.85rem; }
        .sheet { max-width:210mm;min-height:260mm;margin:0 auto;padding:5mm;display:grid;grid-template-columns:repeat(auto-fill,var(--label-width));grid-auto-rows:var(--label-height);gap:2mm;justify-content:center;align-content:start;background:#fff;box-shadow:0 8px 28px rgba(15,23,42,.15); }
        .empty-sheet { display:grid;place-items:center;min-height:260mm;color:#64748b;text-align:center; }
        .barcode-label { width:var(--label-width);height:var(--label-height);box-sizing:border-box;padding:1.5mm 2mm;border:.25mm dashed #cbd5e1;overflow:hidden;display:flex;flex-direction:column;align-items:center;justify-content:space-between;break-inside:avoid;page-break-inside:avoid;background:#fff;color:#000;font-family:Arial,Helvetica,sans-serif; }
        .label-product { width:100%;text-align:center;font-weight:700;font-size:2.6mm;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
        .barcode-svg { display:block;width:95%;height:11mm; }
        .label-code { font-size:2.3mm;letter-spacing:.15mm;line-height:1; }
        .label-footer { width:100%;display:flex;justify-content:space-between;gap:1mm;font-size:2.1mm;line-height:1; }
        .label-price { font-weight:700; }
        @media(max-width:780px){.toolbar-form{grid-template-columns:1fr 1fr}.toolbar-form .product-field{grid-column:1/-1}.sheet{max-width:none}}
        @media print{@page{margin:4mm}body{background:#fff}.no-print{display:none!important}.barcode-page{padding:0}.sheet{max-width:none;min-height:0;margin:0;padding:0;box-shadow:none;gap:0;justify-content:start}.barcode-label{border:none}}
    </style>
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
        <div class="toolbar-actions"><a class="btn" href="products.php" style="background:#475569"><i class="bi bi-arrow-left"></i>Back to Products</a><?php if ($printableProducts): ?><button class="btn" type="button" onclick="window.print()" style="background:#f59e0b"><i class="bi bi-printer"></i>Print <?= count($printableProducts) * $quantity ?> Label(s)</button><?php endif; ?></div>
        <?php if (count($selectedProducts) > 1): ?><div class="selection-summary"><strong><?= count($selectedProducts) ?> products selected.</strong> Each printable product will receive <?= $quantity ?> label(s).</div><?php endif; ?>
        <?php if (!empty($_GET['error'])): ?><div class="message"><?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>
        <?php if ($missingBarcodeProducts): ?><div class="message"><strong><?= count($missingBarcodeProducts) ?> product(s) were skipped because they have no barcode:</strong> <?= htmlspecialchars(implode(', ', array_column($missingBarcodeProducts, 'product_name'))) ?>. Generate their internal barcodes from Products &amp; Stock.</div><?php endif; ?>
        <?php if ($barcodeErrors): ?><div class="message"><?= htmlspecialchars(implode(' | ', $barcodeErrors)) ?></div><?php endif; ?>
        <?php if (count($selectedProducts) === 1 && !$printableProducts && $missingBarcodeProducts): $missing = $missingBarcodeProducts[0]; ?><form method="POST" style="margin-top:.65rem"><?= csrf_field() ?><input type="hidden" name="action" value="generate_barcode"><input type="hidden" name="product_id" value="<?= (int)$missing['product_id'] ?>"><input type="hidden" name="quantity" value="<?= $quantity ?>"><input type="hidden" name="size" value="<?= htmlspecialchars($sizeKey) ?>"><button class="btn" type="submit" style="background:#10b981">Generate Barcode and Print</button></form><?php endif; ?>
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
        <main class="sheet empty-sheet"><div><i class="bi bi-upc-scan" style="font-size:2rem"></i><br>Select a product with a barcode to create printable labels.</div></main>
    <?php endif; ?>
</div>
<?php if ($autoPrint): ?><script>window.addEventListener('load',()=>window.print());</script><?php endif; ?>
</body>
</html>
