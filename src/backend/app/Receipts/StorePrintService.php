<?php
namespace App\Receipts;

use PDO;
use DomainException;

/** Queue saved customer receipts only. A claimed job is never automatically replayed. */
final class StorePrintService
{
    public function __construct(private PDO $pdo, private string $deployment) {}

    public function submit(int $actor, string $role, int $store, string $type, int $receipt, string $reprintOf = ''): array
    {
        if ($role !== 'cashier' || !in_array($type, ['sale', 'refund'], true) || $receipt < 1 || $this->deployment === '') {
            throw new DomainException('Store printing is unavailable for this workspace.');
        }
        $width = (new ReceiptPaperService($this->pdo))->currentWidth($actor, $role);
        $details = new ReceiptDetailsService($this->pdo);
        if ($type === 'sale') {
            $sale = $details->fetchSale($receipt, $store);
            if (!$sale || (int)$sale['cashier_id'] !== $actor) throw new DomainException('Receipt unavailable.');
            ob_start();
            try { SaleReceiptPresentation::render($sale, $details->fetchItems($receipt), $sale['receipt_store'] ?? $details->storeInfo(), $width); $html = ob_get_contents(); }
            finally { ob_end_clean(); }
        } else {
            $refund = (new RefundReceiptService($this->pdo))->forCashier($receipt, $actor, $store);
            if (!$refund) throw new DomainException('Receipt unavailable.');
            ob_start();
            try { RefundReceiptPresentation::render($refund, $width); $html = ob_get_contents(); }
            finally { ob_end_clean(); }
        }
        if ($reprintOf !== '') {
            $prior = $this->status($actor, $type, $receipt, $reprintOf);
            if (!in_array($prior['status'], ['spooled', 'uncertain', 'failed'], true)) return $prior;
        } else {
            $this->expireClaims();
            $latest = $this->pdo->prepare('SELECT j.job_id, j.status FROM store_print_jobs j LEFT JOIN store_print_jobs n ON n.reprint_of=j.job_id AND n.deployment=j.deployment WHERE j.deployment=? AND j.cashier_id=? AND j.receipt_type=? AND j.receipt_id=? AND n.job_id IS NULL LIMIT 1');
            $latest->execute([$this->deployment, $actor, $type, $receipt]);
            if ($existing = $latest->fetch(PDO::FETCH_ASSOC)) return $existing;
        }
        // Preserve the shared paper's content; raw ESC/POS uses its configured character width.
        $text = preg_replace('/<\/(?:div|p|h[1-6])\s*>|<br\s*\/?>/i', "\n", $html);
        $text = preg_replace('/<\/(?:span|strong)>/i', ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(['₱', '×'], ['PHP ', 'x'], $text);
        // shortcut: RAW printing uses ASCII approximations; calibrate Unicode rendering if non-ASCII names are required.
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        // Receipt values cannot inject printer control sequences.
        $text = preg_replace('/[^\x20-\x7e\n]/', '', $text);
        $lines = array_filter(array_map(static fn($line) => trim(preg_replace('/ +/', ' ', $line)), explode("\n", $text)), static fn($line) => $line !== '');
        $payload = "\x1b@" . implode("\n", array_map(static fn($line) => wordwrap($line, $width === 58 ? 32 : 48, "\n", true), $lines)) . "\n\n\n\n";
        $job = bin2hex(random_bytes(16));
        $query = $this->pdo->prepare('INSERT IGNORE INTO store_print_jobs (job_id, deployment, cashier_id, receipt_type, receipt_id, reprint_of, payload, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)' );
        $query->execute([$job, $this->deployment, $actor, $type, $receipt, $reprintOf, $payload, 'queued']);
        $query = $this->pdo->prepare('SELECT job_id, status FROM store_print_jobs WHERE deployment=? AND cashier_id=? AND receipt_type=? AND receipt_id=? AND reprint_of=?');
        $query->execute([$this->deployment, $actor, $type, $receipt, $reprintOf]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function status(int $actor, string $type, int $receipt, string $job): array
    {
        $this->expireClaims();
        $query = $this->pdo->prepare('SELECT job_id, status FROM store_print_jobs WHERE deployment=? AND cashier_id=? AND receipt_type=? AND receipt_id=? AND job_id=?');
        $query->execute([$this->deployment, $actor, $type, $receipt, $job]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('Print request unavailable.');
    }

    public function claim(): ?array
    {
        $this->expireClaims();
        $query = $this->pdo->prepare("SELECT job_id, payload FROM store_print_jobs WHERE deployment=? AND status='queued' ORDER BY created_at, job_id LIMIT 1");
        $query->execute([$this->deployment]);
        $job = $query->fetch(PDO::FETCH_ASSOC);
        if (!$job) return null;
        $claim = $this->pdo->prepare("UPDATE store_print_jobs SET status='printing', updated_at=CURRENT_TIMESTAMP WHERE job_id=? AND deployment=? AND status='queued'");
        $claim->execute([$job['job_id'], $this->deployment]);
        return $claim->rowCount() === 1 ? $job : null;
    }

    public function finish(string $job, string $status): void
    {
        if (!in_array($status, ['spooled', 'uncertain', 'failed'], true)) throw new DomainException('Invalid delivery outcome.');
        $query = $this->pdo->prepare("UPDATE store_print_jobs SET status=?, updated_at=CURRENT_TIMESTAMP WHERE job_id=? AND deployment=? AND status='printing'");
        $query->execute([$status, $job, $this->deployment]);
    }

    private function expireClaims(): void
    {
        $query = $this->pdo->prepare("UPDATE store_print_jobs SET status='uncertain', updated_at=CURRENT_TIMESTAMP WHERE deployment=? AND status='printing' AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 MINUTE)");
        $query->execute([$this->deployment]);
    }
}
