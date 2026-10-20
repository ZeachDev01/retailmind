<?php
// components/modals/product_overview/viewBatches.php
?>
<div class="rm-modal-overlay" id="view-batches-modal" aria-hidden="true">
    <section class="rm-modal u-modal-lg product-overview-modal" role="dialog" aria-modal="true" aria-labelledby="view-batches-title">
        <header class="rm-modal-header">
            <div>
                <h2 id="view-batches-title">Product Batches</h2>
                <p id="view-batches-subtitle">Batch expiration, sources, and stock history.</p>
            </div>
            <button type="button" class="rm-close" data-close-modal aria-label="Close batch details">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>
        <div class="rm-modal-body">
            <div id="view-batches-reconciliation-alert" class="alert alert-warning view-batches-alert" data-rm-alert-processed="1" style="display:none;">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span id="view-batches-reconciliation-text"><strong>Reconciliation Exception:</strong> Total physical recorded on-hand stock exceeds the sum of tracked batches. A physical inventory count and correction is required.</span>
            </div>
            <div class="table-wrap">
                <table class="table" id="view-batches-table">
                    <thead>
                        <tr>
                            <th>Batch #</th>
                            <th>Source</th>
                            <th>Remaining Qty</th>
                            <th>Expiration Date</th>
                            <th>Days Left</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="view-batches-table-body">
                        <tr><td class="u-empty-cell" colspan="7">Select a product to view batches.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
