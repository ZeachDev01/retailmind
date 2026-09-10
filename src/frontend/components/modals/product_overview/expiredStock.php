<div class="rm-modal-overlay" id="expired-stock-modal" aria-hidden="true">
	<section class="rm-modal u-modal-lg product-overview-modal" role="dialog" aria-modal="true" aria-labelledby="expired-stock-title">
		<header class="rm-modal-header">
			<div>
				<h2 id="expired-stock-title">Expired Stock</h2>
				<p>Batches past their expiration date with remaining quantity.</p>
			</div>
			<button type="button" class="rm-close" data-close-modal aria-label="Close expired stock">
				<i class="bi bi-x-lg" aria-hidden="true"></i>
			</button>
		</header>
		<div class="rm-modal-body">
			<div class="table-wrap">
				<table>
					<thead>
						<tr><th>Product</th><th>Batch</th><th>Blocked Qty</th><th>Expired</th><th>Supplier</th></tr>
					</thead>
					<tbody>
					<?php foreach ($expired_batches as $batch): ?>
						<tr>
							<td><?= htmlspecialchars((string)$batch['sku'] . ' - ' . (string)$batch['product_name']) ?></td>
							<td><?= htmlspecialchars((string)($batch['batch_number'] ?? '-')) ?></td>
							<td><?= (int)$batch['remaining_quantity'] ?></td>
							<td><?= htmlspecialchars((string)$batch['expiration_date']) ?></td>
							<td><?= htmlspecialchars((string)($batch['supplier'] ?? '-')) ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if (!$expired_batches): ?>
						<tr><td class="u-empty-cell" colspan="5">No expired batches with remaining quantity.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>
</div>
