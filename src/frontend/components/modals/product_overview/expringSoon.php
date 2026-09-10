<div class="rm-modal-overlay" id="expiring-soon-modal" aria-hidden="true">
	<section class="rm-modal u-modal-lg product-overview-modal" role="dialog" aria-modal="true" aria-labelledby="expiring-soon-title">
		<header class="rm-modal-header">
			<div>
				<h2 id="expiring-soon-title">Expiring Soon</h2>
				<p>Batches expiring within the next 30 days.</p>
			</div>
			<button type="button" class="rm-close" data-close-modal aria-label="Close expiring soon products">
				<i class="bi bi-x-lg" aria-hidden="true"></i>
			</button>
		</header>
		<div class="rm-modal-body">
			<div class="table-wrap">
				<table>
					<thead>
						<tr><th>Product</th><th>Batch</th><th>Remaining</th><th>Expires</th><th>Supplier</th></tr>
					</thead>
					<tbody>
					<?php foreach ($expiring_batches as $batch): ?>
						<tr>
							<td><?= htmlspecialchars((string)$batch['sku'] . ' - ' . (string)$batch['product_name']) ?></td>
							<td><?= htmlspecialchars((string)($batch['batch_number'] ?? '-')) ?></td>
							<td><?= (int)$batch['remaining_quantity'] ?></td>
							<td><?= htmlspecialchars((string)$batch['expiration_date']) ?></td>
							<td><?= htmlspecialchars((string)($batch['supplier'] ?? '-')) ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if (!$expiring_batches): ?>
						<tr><td class="u-empty-cell" colspan="5">No batches expiring in the next 30 days.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>
</div>
