<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/barcode.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_role(['admin', 'inventory_manager']);

$productService = new ProductService($pdo);
$productId = max(0, (int)($_REQUEST['product_id'] ?? 0));
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
        $productService->assignGeneratedBarcode($productId, (int)$_SESSION['user_id']);
        header(
            'Location: print_barcodes.php?product_id=' . $productId .
            '&quantity=' . $quantity . '&size=' . urlencode($sizeKey) . '&autoprint=1'
        );
        exit;
    } catch (Throwable $e) {
        header(
            'Location: print_barcodes.php?product_id=' . $productId .
            '&quantity=' . $quantity . '&size=' . urlencode($sizeKey) .
            '&error=' . urlencode($e->getMessage())
        );
        exit;
    }
}

$products = $productService->getProductsForManagement();
$product = $productId > 0 ? $productService->getProductForBarcodePrinting($productId) : null;
$barcode = $product ? trim((string)($product['barcode'] ?? '')) : '';
$barcodeSvg = '';
$barcodeError = '';

if ($product && $barcode !== '') {
    try {
        $barcodeSvg = code128_svg($barcode, 58, 2);
    } catch (Throwable $e) {
        $barcodeError = $e->getMessage();
    }
}

$autoPrint = !empty($_GET['autoprint']) && $barcodeSvg !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printable Barcode Labels</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <style>
        :root {
            --label-width: <?= (int)$labelSize['width'] ?>mm;
            --label-height: <?= (int)$labelSize['height'] ?>mm;
        }
        body { margin: 0; background: #e5e7eb; color: #111827; }
        .barcode-page { min-height: 100vh; padding: 1.25rem; }
        .toolbar {
            max-width: 1100px; margin: 0 auto 1rem; padding: 1rem;
            background: #0f172a; color: #e2e8f0; border-radius: 14px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.16);
        }
        .toolbar h1 { margin: 0 0 0.9rem; color: #f8fafc; }
        .toolbar-form { display: grid; grid-template-columns: minmax(240px, 1fr) 110px 150px auto; gap: 0.75rem; align-items: end; }
        .toolbar label { display: block; margin-bottom: 0.3rem; font-size: 0.88rem; color: #cbd5e1; }
        .toolbar select, .toolbar input { width: 100%; box-sizing: border-box; }
        .toolbar-actions { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-top: 0.9rem; }
        .toolbar .btn { text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .message { margin-top: 0.8rem; padding: 0.75rem 0.9rem; border-radius: 9px; background: #422006; color: #fde68a; }
        .sheet {
            max-width: 210mm; min-height: 260mm; margin: 0 auto; padding: 5mm;
            display: grid; grid-template-columns: repeat(auto-fill, var(--label-width));
            grid-auto-rows: var(--label-height); gap: 2mm;
            justify-content: center; align-content: start; background: #fff;
            box-shadow: 0 8px 28px rgba(15, 23, 42, 0.15);
        }
        .empty-sheet { display: grid; place-items: center; min-height: 260mm; color: #64748b; text-align: center; }
        .barcode-label {
            width: var(--label-width); height: var(--label-height); box-sizing: border-box;
            padding: 1.5mm 2mm; border: 0.25mm dashed #cbd5e1; overflow: hidden;
            display: flex; flex-direction: column; align-items: center; justify-content: space-between;
            break-inside: avoid; page-break-inside: avoid; background: #fff; color: #000;
            font-family: Arial, Helvetica, sans-serif;
        }
        .label-product { width: 100%; text-align: center; font-weight: 700; font-size: 2.6mm; line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .barcode-svg { display: block; width: 95%; height: 11mm; }
        .label-code { font-size: 2.3mm; letter-spacing: 0.15mm; line-height: 1; }
        .label-footer { width: 100%; display: flex; justify-content: space-between; gap: 1mm; font-size: 2.1mm; line-height: 1; }
        .label-price { font-weight: 700; }
        @media (max-width: 780px) {
            .toolbar-form { grid-template-columns: 1fr 1fr; }
            .toolbar-form .product-field { grid-column: 1 / -1; }
            .sheet { max-width: none; }
        }
        @media print {
            @page { margin: 4mm; }
            body { background: #fff; }
            .no-print { display: none !important; }
            .barcode-page { padding: 0; }
            .sheet { max-width: none; min-height: 0; margin: 0; padding: 0; box-shadow: none; gap: 0; justify-content: start; }
            .barcode-label { border: none; }
        }
    </style>
</head>
<body>
<div class="barcode-page">
    <section class="toolbar no-print">
        <h1>Printable Barcode Labels</h1>
        <form method="GET" class="toolbar-form">
            <div class="product-field">
                <label for="product_id">Product</label>
                <select name="product_id" id="product_id" required>
                    <option value="">Select a product</option>
                    <?php foreach ($products as $item): ?>
                        <?php $itemBarcode = trim((string)($item['barcode'] ?? '')); ?>
                        <option value="<?= (int)$item['product_id'] ?>" <?= (int)$item['product_id'] === $productId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($item['product_name']) ?> — <?= htmlspecialchars($itemBarcode !== '' ? $itemBarcode : 'No barcode') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="quantity">Labels</label>
                <input type="number" name="quantity" id="quantity" value="<?= $quantity ?>" min="1" max="200" required>
            </div>
            <div>
                <label for="size">Label size</label>
                <select name="size" id="size">
                    <?php foreach ($allowedSizes as $key => $size): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $key === $sizeKey ? 'selected' : '' ?>><?= htmlspecialchars($size['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn" type="submit">Create Labels</button>
        </form>

        <div class="toolbar-actions">
            <a class="btn" href="products.php" style="background:#475569;">Back to Products</a>
            <?php if ($barcodeSvg !== ''): ?>
                <button class="btn" type="button" onclick="window.print()" style="background:#f59e0b;">Print Labels</button>
            <?php endif; ?>
        </div>

        <?php if (!empty($_GET['error'])): ?>
            <div class="message"><?= htmlspecialchars($_GET['error']) ?></div>
        <?php elseif ($productId > 0 && !$product): ?>
            <div class="message">The selected product could not be found.</div>
        <?php elseif ($product && $barcode === ''): ?>
            <div class="message">
                This product has no barcode. Generate an internal RetailMind barcode before printing.
                <form method="POST" style="margin-top:0.65rem;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate_barcode">
                    <input type="hidden" name="product_id" value="<?= $productId ?>">
                    <input type="hidden" name="quantity" value="<?= $quantity ?>">
                    <input type="hidden" name="size" value="<?= htmlspecialchars($sizeKey) ?>">
                    <button class="btn" type="submit" style="background:#10b981;">Generate Barcode and Print</button>
                </form>
            </div>
        <?php elseif ($barcodeError !== ''): ?>
            <div class="message"><?= htmlspecialchars($barcodeError) ?></div>
        <?php endif; ?>
    </section>

    <?php if ($product && $barcodeSvg !== ''): ?>
        <main class="sheet" aria-label="Barcode label sheet">
            <?php for ($index = 0; $index < $quantity; $index++): ?>
                <article class="barcode-label">
                    <div class="label-product" title="<?= htmlspecialchars($product['product_name']) ?>"><?= htmlspecialchars($product['product_name']) ?></div>
                    <?= $barcodeSvg ?>
                    <div class="label-code"><?= htmlspecialchars($barcode) ?></div>
                    <div class="label-footer">
                        <span><?= htmlspecialchars($product['brand'] ?: ($product['category_name'] ?: 'RetailMind')) ?></span>
                        <span class="label-price">₱<?= number_format((float)$product['unit_price'], 2) ?></span>
                    </div>
                </article>
            <?php endfor; ?>
        </main>
    <?php else: ?>
        <main class="sheet empty-sheet">
            <div>Select a product to create printable barcode labels.</div>
        </main>
    <?php endif; ?>
</div>
<?php if ($autoPrint): ?>
<script>
    window.addEventListener('load', () => window.setTimeout(() => window.print(), 250));
</script>
<?php endif; ?>
</body>
</html>
