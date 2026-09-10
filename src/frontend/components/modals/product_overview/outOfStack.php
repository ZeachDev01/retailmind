<div class="rm-modal-overlay" id="out-of-stock-modal" aria-hidden="true">
	<section class="rm-modal u-modal-lg product-overview-modal" role="dialog" aria-modal="true" aria-labelledby="out-of-stock-title">
		<header class="rm-modal-header">
			<div>
				<h2 id="out-of-stock-title">Out of Stock</h2>
				<p>Products that currently have no units available.</p>
			</div>
			<button type="button" class="rm-close" data-close-modal aria-label="Close out of stock products">
				<i class="bi bi-x-lg" aria-hidden="true"></i>
			</button>
		</header>
		<div class="rm-modal-body">
			<div class="table-wrap">
				<table>
					<thead>
						<tr><th>SKU</th><th>Product</th><th>On Hand</th><th>Reorder</th></tr>
					</thead>
					<tbody>
					<?php foreach ($out_of_stock_products as $item): ?>
						<tr>
							<td><?= htmlspecialchars((string)$item['sku']) ?></td>
							<td><?= htmlspecialchars((string)$item['product_name']) ?></td>
							<td><?= (int)$item['quantity_on_hand'] ?></td>
							<td><?= (int)$item['reorder_level'] ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if (!$out_of_stock_products): ?>
						<tr><td class="u-empty-cell" colspan="4">No products are out of stock right now.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>
</div>
