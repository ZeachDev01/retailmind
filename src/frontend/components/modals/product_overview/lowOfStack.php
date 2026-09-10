<div class="rm-modal-overlay" id="low-stock-modal" aria-hidden="true">
	<section class="rm-modal u-modal-lg product-overview-modal" role="dialog" aria-modal="true" aria-labelledby="low-stock-title">
		<header class="rm-modal-header">
			<div>
				<h2 id="low-stock-title">Low Stock Items</h2>
				<p>Products at or below their reorder level.</p>
			</div>
			<button type="button" class="rm-close" data-close-modal aria-label="Close low stock items">
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
					<?php foreach ($low_stock as $item): ?>
						<tr>
							<td><?= htmlspecialchars((string)$item['sku']) ?></td>
							<td><?= htmlspecialchars((string)$item['product_name']) ?></td>
							<td><?= (int)$item['quantity_on_hand'] ?></td>
							<td><?= (int)$item['reorder_level'] ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if (!$low_stock): ?>
						<tr><td class="u-empty-cell" colspan="4">No low stock items right now.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>
</div>
