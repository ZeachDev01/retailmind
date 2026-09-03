<?php
// cashier/findProduct.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_role(['admin', 'cashier']);

$posCategories = $pdo->query("SELECT category_id, category_name FROM categories ORDER BY category_name LIMIT 12")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Product</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier.css')) ?>">
</head>
<body class="cashier-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar cashier-topbar">
            <div class="cashier-heading">
                <h1>Find Product</h1>
                <p>Search by product name, SKU, or barcode.</p>
            </div>
            <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>Back to POS
            </a>
        </header>

        <section class="pos-panel product-finder-panel" aria-label="Find a product">
            <div class="pos-panel-header">
                <div class="pos-panel-title">
                    <div class="pos-panel-icon"><i class="bi bi-search" aria-hidden="true"></i></div>
                    <div>
                        <h2>Product lookup</h2>
                        <p>Check price, stock, SKU, barcode, and expiration details before scanning.</p>
                    </div>
                </div>
                <span class="cashier-chip" id="search-count">0 results</span>
            </div>

            <div class="search-bar-wrap">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="product-search" class="pos-input" type="search" placeholder="Start typing a product name or code" autocomplete="off" autofocus>
            </div>

            <?php if ($posCategories): ?>
                <div class="pos-category-section">
                    <div class="pos-category-heading"><strong>Quick categories</strong><span>Tap a category to show available products.</span></div>
                    <div class="pos-category-buttons" id="pos-category-buttons">
                        <?php foreach ($posCategories as $category): ?>
                            <button type="button" class="pos-category-button" data-category-id="<?= (int)$category['category_id'] ?>"><i class="bi bi-grid" aria-hidden="true"></i><?= htmlspecialchars($category['category_name']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div id="search-results" class="search-results product-finder-results" aria-live="polite"></div>
        </section>
    </main>
</div>

<script>
const productSearchInput = document.getElementById('product-search');
const searchResults = document.getElementById('search-results');
const searchCount = document.getElementById('search-count');
const categoryButtons = document.getElementById('pos-category-buttons');
const productsApiUrl = <?= json_encode(app_url('components/barcodeScanner/apiScanner/products.php')) ?>;
const posUrl = <?= json_encode(app_url('components/cashier/pos.php')) ?>;
const lowStockFallback = 5;
let productSearch = [];
let searchTimer = null;

function money(value) {
    return Number(value || 0).toFixed(2);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));
}

function renderProductButtons(products, emptyMessage = 'No matching active product was found.') {
    productSearch = products || [];
    searchCount.textContent = `${productSearch.length} result${productSearch.length === 1 ? '' : 's'}`;
    if (!productSearch.length) {
        searchResults.innerHTML = `<div class="search-empty">${escapeHtml(emptyMessage)}</div>`;
        return;
    }

    searchResults.innerHTML = productSearch.map(product => {
        const threshold = Math.max(Number(product.reorder_level || 0), Number(product.safety_stock || 0), lowStockFallback);
        const low = Number(product.quantity_on_hand) <= threshold;
        const code = product.sku || product.barcode || 'No code';
        const expiration = product.next_expiration_date ? `<span>Expires ${escapeHtml(product.next_expiration_date)}</span>` : '';
        return `<article class="search-result product-result-card">
            <span>
                <strong class="search-result-name">${escapeHtml(product.name)}</strong>
                <span class="search-result-meta">
                    <span>${escapeHtml(code)}</span>
                    <span class="search-result-stock ${low ? 'low' : ''}">Stock ${Number(product.quantity_on_hand)}</span>
                    ${expiration}
                </span>
            </span>
            <span class="product-result-actions">
                <strong class="search-result-price">&#8369;${money(product.price)}</strong>
                <a class="btn btn-small" href="${posUrl}?q=${encodeURIComponent(code)}"><i class="bi bi-cart-plus" aria-hidden="true"></i>POS</a>
            </span>
        </article>`;
    }).join('');
}

async function renderSearchResults() {
    const term = productSearchInput.value.trim();
    if (term === '') {
        productSearch = [];
        searchCount.textContent = '0 results';
        searchResults.innerHTML = '<div class="search-empty"><div><i class="bi bi-keyboard u-search-icon" aria-hidden="true"></i>Enter at least one letter or number to search.</div></div>';
        return;
    }

    try {
        const response = await fetch(`${productsApiUrl}?q=${encodeURIComponent(term)}&limit=20`);
        const data = await response.json();
        renderProductButtons(data.success ? (data.products || []) : []);
    } catch (error) {
        renderProductButtons([], 'Unable to load products.');
    }
}

productSearchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(renderSearchResults, 250);
});

if (categoryButtons) {
    categoryButtons.addEventListener('click', async event => {
        const button = event.target.closest('[data-category-id]');
        if (!button) return;
        categoryButtons.querySelectorAll('.pos-category-button').forEach(item => item.classList.toggle('active', item === button));
        productSearchInput.value = '';
        searchResults.innerHTML = '<div class="search-empty"><span class="skeleton u-skeleton-inline">Loading</span></div>';
        try {
            const response = await fetch(`${productsApiUrl}?category=${encodeURIComponent(button.dataset.categoryId)}&limit=20`);
            const data = await response.json();
            renderProductButtons(data.success ? (data.products || []) : [], 'No available products in this category.');
        } catch (error) {
            renderProductButtons([], 'Unable to load category products.');
        }
    });
}

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        window.location.href = posUrl;
    }
});

const initialQuery = new URLSearchParams(window.location.search).get('q');
if (initialQuery) {
    productSearchInput.value = initialQuery;
}
renderSearchResults();
</script>
</body>
</html>
