<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/theme.php';
require_once __DIR__ . '/../../../backend/app/Services/SupplierPulloutService.php';

header('Cache-Control: no-store');
require_role(['admin', 'super_admin', 'inventory_manager']);

$pulloutId = max(0, (int)($_GET['pullout_id'] ?? 0));
$pulloutService = new SupplierPulloutService($pdo);
$pullout = $pulloutId > 0 ? $pulloutService->getPullout($pulloutId) : null;

$manifestNumber = 'MNF-PO-' . str_pad((string)$pulloutId, 6, '0', STR_PAD_LEFT);
$pageTitle = $pullout ? 'Transfer Manifest #' . $manifestNumber : 'Transfer Manifest Not Found';
$autoPrint = !empty($_GET['autoprint']) && $pullout !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - RetailMind</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/reports.css')) ?>">
    <style>
        .manifest-page {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
            color: var(--text, #111827);
        }
        .manifest-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border, #e5e7eb);
        }
        .manifest-document {
            background: #ffffff;
            border: 1px solid var(--border, #d1d5db);
            border-radius: 8px;
            padding: 2.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        .manifest-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #111827;
            padding-bottom: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .manifest-brand h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 0.25rem 0;
            color: #111827;
            letter-spacing: -0.01em;
        }
        .manifest-brand p {
            margin: 0;
            font-size: 0.875rem;
            color: #4b5563;
        }
        .manifest-meta {
            text-align: right;
        }
        .manifest-tag {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: #f3f4f6;
            color: #1f2937;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
            margin-bottom: 0.35rem;
        }
        .manifest-num {
            font-size: 1.25rem;
            font-weight: 700;
            font-family: monospace;
            color: #111827;
            margin: 0 0 0.25rem 0;
        }
        .manifest-date {
            font-size: 0.8125rem;
            color: #4b5563;
            margin: 0;
        }
        .manifest-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 1.25rem;
        }
        .manifest-col h3 {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            margin: 0 0 0.5rem 0;
            font-weight: 600;
        }
        .manifest-col p {
            margin: 0 0 0.25rem 0;
            font-size: 0.9375rem;
            color: #111827;
        }
        .manifest-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
        }
        .manifest-table th {
            background: #f3f4f6;
            color: #374151;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.75rem;
            text-align: left;
            border-top: 1px solid #d1d5db;
            border-bottom: 1px solid #d1d5db;
        }
        .manifest-table td {
            padding: 1rem 0.75rem;
            font-size: 0.875rem;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }
        .manifest-table .text-right {
            text-align: right;
        }
        .manifest-cert {
            font-size: 0.8125rem;
            color: #4b5563;
            line-height: 1.5;
            background: #fdf2f2;
            border: 1px solid #fee2e2;
            border-radius: 6px;
            padding: 0.75rem 1rem;
            margin-bottom: 2.5rem;
        }
        .manifest-signatures {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            padding-top: 1rem;
        }
        .sig-block {
            border-top: 1px solid #9ca3af;
            padding-top: 0.5rem;
            font-size: 0.8125rem;
        }
        .sig-role {
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.15rem;
        }
        .sig-name {
            color: #374151;
            margin-bottom: 0.25rem;
        }
        .sig-date {
            color: #6b7280;
            font-size: 0.75rem;
        }
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .no-print {
                display: none !important;
            }
            .manifest-page {
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .manifest-document {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
            }
        }
    </style>
</head>
<body class="manifest-body">
<main class="manifest-page">
    <div class="manifest-toolbar no-print">
        <a href="supplier_pullouts.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Pullouts
        </a>
        <?php if ($pullout): ?>
            <button type="button" class="btn btn-primary" onclick="window.print()">
                <i class="bi bi-printer" aria-hidden="true"></i> Print Manifest
            </button>
        <?php endif; ?>
    </div>

    <?php if (!$pullout): ?>
        <section class="card" aria-label="Error">
            <div class="card-body u-text-center u-p-6">
                <i class="bi bi-exclamation-octagon u-text-danger u-icon-large" aria-hidden="true"></i>
                <h2 class="u-mt-2">Transfer Manifest Not Found</h2>
                <p class="text-muted">The requested supplier pullout record could not be found or has been removed.</p>
                <div class="u-mt-4">
                    <a href="supplier_pullouts.php" class="btn btn-primary">Return to Supplier Pullouts</a>
                </div>
            </div>
        </section>
    <?php else: ?>
        <article class="manifest-document" aria-label="Supplier pullout transfer manifest">
            <header class="manifest-header">
                <div class="manifest-brand">
                    <span class="manifest-tag">Official Store Record</span>
                    <h1>TRANSFER MANIFEST</h1>
                    <p>RetailMind Store Inventory • Warehouse &amp; Inventory Custody Control</p>
                </div>
                <div class="manifest-meta">
                    <div class="manifest-num"><?= htmlspecialchars($manifestNumber) ?></div>
                    <p class="manifest-date"><strong>Issued:</strong> <?= htmlspecialchars($pullout['pulled_at']) ?></p>
                    <p class="manifest-date"><strong>Pullout ID:</strong> #<?= (int)$pullout['pullout_id'] ?></p>
                </div>
            </header>

            <section class="manifest-details-grid">
                <div class="manifest-col">
                    <h3>Originating Store &amp; Custodian</h3>
                    <p><strong>Store:</strong> RetailMind Main Store</p>
                    <p><strong>Released By:</strong> <?= htmlspecialchars($pullout['pulled_by_name'] ?: 'Staff #' . $pullout['pulled_by']) ?></p>
                    <p><strong>Transfer Type:</strong> Supplier Pullout / Non-conforming Stock Return</p>
                </div>
                <div class="manifest-col">
                    <h3>Destination Supplier / Vendor</h3>
                    <p><strong>Supplier:</strong> <?= htmlspecialchars($pullout['supplier'] ?: 'General Vendor Return') ?></p>
                    <p><strong>Reason Category:</strong> <?= htmlspecialchars($pullout['reason']) ?></p>
                    <p><strong>Notes:</strong> <?= htmlspecialchars($pullout['notes'] ?: 'No additional notes provided') ?></p>
                </div>
            </section>

            <table class="manifest-table" aria-label="Transferred inventory items">
                <thead>
                    <tr>
                        <th scope="col">Item Description</th>
                        <th scope="col">Batch / Lot #</th>
                        <th scope="col">Expiration Date</th>
                        <th scope="col" class="text-right">Quantity Transferred</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($pullout['product_name']) ?></strong>
                            <br>
                            <small class="text-muted">SKU: <?= htmlspecialchars($pullout['sku']) ?></small>
                        </td>
                        <td>
                            <?= htmlspecialchars($pullout['batch_number'] ?: ('#' . $pullout['batch_id'])) ?>
                        </td>
                        <td>
                            <?= htmlspecialchars(!empty($pullout['expiration_date']) ? $pullout['expiration_date'] : 'Not recorded') ?>
                        </td>
                        <td class="text-right">
                            <strong><?= number_format((int)$pullout['quantity']) ?> base units</strong>
                            <?php
                            $pulledBreakdown = SupplierPulloutService::formatFormBreakdown(
                                (int)($pullout['pulled_packs'] ?? 0),
                                (int)($pullout['pulled_loose'] ?? 0),
                                (int)($pullout['pack_size_used'] ?? ($pullout['pack_size'] ?? 1))
                            );
                            ?>
                            <?php if ($pulledBreakdown !== ''): ?>
                                <br><small class="text-muted">(<?= htmlspecialchars($pulledBreakdown) ?>)</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="manifest-cert" role="note">
                <strong>Certification Statement:</strong>
                I hereby certify that the items listed on this manifest have been officially inspected, verified, and removed from active store inventory. Custody is hereby transferred to the designated supplier representative for credit memo, vendor replacement, or supervised destruction.
            </div>

            <footer class="manifest-signatures">
                <div class="sig-block">
                    <div class="sig-role">Released By</div>
                    <div class="sig-name"><?= htmlspecialchars($pullout['pulled_by_name'] ?: 'Inventory Custodian') ?></div>
                    <div class="sig-date">Date: <?= htmlspecialchars(substr((string)$pullout['pulled_at'], 0, 10)) ?></div>
                </div>
                <div class="sig-block">
                    <div class="sig-role">Authorized By</div>
                    <div class="sig-name">Store Supervisor / Admin</div>
                    <div class="sig-date">Date: ________________________</div>
                </div>
                <div class="sig-block">
                    <div class="sig-role">Received By</div>
                    <div class="sig-name"><?= htmlspecialchars($pullout['supplier'] ?: 'Courier Representative') ?></div>
                    <div class="sig-date">Date: ________________________</div>
                </div>
            </footer>
        </article>
    <?php endif; ?>
</main>
<?php if ($autoPrint): ?>
    <script>window.addEventListener('load', () => window.print());</script>
<?php endif; ?>
</body>
</html>
