<div class="rm-modal-overlay" id="product-overview-modal" aria-hidden="true">
	<section class="rm-modal u-modal-lg product-overview-modal" role="dialog" aria-modal="true" aria-labelledby="product-overview-title">
		<header class="rm-modal-header">
			<div>
				<h2 id="product-overview-title">Products</h2>
				<p>Complete product catalog and current inventory levels.</p>
			</div>
			<button type="button" class="rm-close" data-close-modal aria-label="Close products">
				<i class="bi bi-x-lg" aria-hidden="true"></i>
			</button>
		</header>
		<div class="rm-modal-body">
			<div class="table-wrap">
				<table>
					<thead>
						<tr><th>Product</th><th>SKU</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th></tr>
					</thead>
					<tbody>
					<?php foreach ($products as $product):
						$quantity = (int)($product['quantity_on_hand'] ?? 0);
						$reorderLevel = max((int)($product['reorder_level'] ?? 0), (int)($product['safety_stock'] ?? 0));
						$status = $product['status'] !== 'active' ? 'Inactive' : ($quantity <= 0 ? 'Out of stock' : ($quantity <= $reorderLevel ? 'Low stock' : 'Available'));
					?>
						<tr>
							<td><?= htmlspecialchars((string)$product['product_name']) ?></td>
							<td><?= htmlspecialchars((string)$product['sku']) ?></td>
							<td><?= htmlspecialchars((string)($product['category_name'] ?? 'Uncategorized')) ?></td>
							<td>₱<?= number_format((float)($product['unit_price'] ?? 0), 2) ?></td>
							<td><?= number_format($quantity) ?></td>
							<td><?= htmlspecialchars($status) ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if (!$products): ?>
						<tr><td class="u-empty-cell" colspan="6">No products found.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>
</div>
