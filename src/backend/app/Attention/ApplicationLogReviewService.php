<?php

namespace App\Attention;

use App\Audit\AuditRecordCategory;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class ApplicationLogReviewService
{
    public const ACTION = 'Application log acknowledged';
    public const RESULTS = [
        'fixed' => 'Fixed and verified',
        'expected' => 'Known diagnostic or test entry',
    ];

    public function __construct(private PDO $pdo, private Clock $clock, private string $path) {}

    public function check(): array
    {
        try {
            $snapshot = $this->snapshot();
            $status = 'healthy';
            $detail = 'No logged application errors are currently stored.';
            if ($snapshot['size'] > 0) {
                $reviewed = $this->isAcknowledged($snapshot['fingerprint']);
                $recent = $snapshot['modified_at'] >= $this->clock->now()->getTimestamp() - 86400;
                $status = $recent && !$reviewed ? 'warning' : 'healthy';
                $detail = $reviewed
                    ? 'Current log version acknowledged. Review history is in Protected Audit Records; new log entries will require review.'
                    : number_format($snapshot['size'] / 1024, 1) . ' KB — last updated ' . date('M d, Y g:i A', $snapshot['modified_at']);
            }
        } catch (\Throwable) {
            $status = 'warning';
            $detail = 'The application log or its review history could not be checked. Refresh and check log access.';
        }
        return ['name' => 'Application log', 'status' => $status, 'detail' => $detail, 'category' => 'Core'];
    }

    public function reviewSnapshot(string $actorRole): array
    {
        $this->requireReviewer($actorRole);
        $snapshot = $this->snapshot();
        $snapshot['acknowledged'] = $snapshot['size'] > 0 && $this->isAcknowledged($snapshot['fingerprint']);
        return $snapshot;
    }

    public function acknowledge(string $actorRole, int $actorId, string $fingerprint, string $result, string $note, string $ipAddress): void
    {
        $this->requireReviewer($actorRole);
        $note = trim($note);
        if (!isset(self::RESULTS[$result]) || $note === '' || mb_strlen($note, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('Choose a review result and enter a note of up to 1,000 characters.');
        }
        $snapshot = $this->snapshot();
        if ($snapshot['size'] === 0 || !hash_equals($snapshot['fingerprint'], $fingerprint)) {
            throw new DomainException('The log has changed. Refresh and review the latest entries before acknowledging.');
        }
        if ($this->isAcknowledged($fingerprint)) {
            throw new DomainException('This log version has already been acknowledged. Refresh to see its status.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO activity_log (user_id, action, category, module, new_value, metadata, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $actorId, self::ACTION, AuditRecordCategory::SECURITY, 'System Health',
            json_encode(['result' => self::RESULTS[$result], 'note' => $note, 'log_size_bytes' => $snapshot['size']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $ipAddress, $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function isAcknowledged(string $fingerprint): bool
    {
        $statement = $this->pdo->prepare('SELECT metadata FROM activity_log WHERE category = ? AND action = ? ORDER BY log_id DESC LIMIT 1');
        $statement->execute([AuditRecordCategory::SECURITY, self::ACTION]);
        $record = json_decode((string)$statement->fetchColumn(), true);
        return is_array($record) && hash_equals((string)($record['fingerprint'] ?? ''), $fingerprint);
    }

    private function snapshot(): array
    {
        clearstatcache(true, $this->path);
        if (!file_exists($this->path)) {
            return ['size' => 0, 'modified_at' => 0, 'fingerprint' => '', 'excerpt' => ''];
        }
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The application log could not be read. Check its file permissions.');
        }
        try {
            $before = fstat($handle);
            if ($before === false || ($before['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('The application log is not a readable file.');
            }
            if ($before['size'] === 0) {
                return ['size' => 0, 'modified_at' => $before['mtime'], 'fingerprint' => '', 'excerpt' => ''];
            }
            $head = fread($handle, min(4096, $before['size']));
            if (fseek($handle, max(0, $before['size'] - 16384)) !== 0) {
                throw new RuntimeException('The recent log entries could not be read.');
            }
            $tail = fread($handle, min(16384, $before['size']));
            $after = fstat($handle);
            if ($head === false || $tail === false || $after === false) {
                throw new RuntimeException('The recent log entries could not be read.');
            }
            if ($before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']) {
                throw new DomainException('New log entries arrived. Refresh to review the latest entries.');
            }
            // shortcut: identify append-only logs by file stats and bounded head/tail samples; use full hashing if in-place log editing is introduced.
            $identity = array_intersect_key($before, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
            return [
                'size' => $before['size'], 'modified_at' => $before['mtime'],
                'fingerprint' => hash('sha256', json_encode($identity) . $head . $tail),
                'excerpt' => $tail,
            ];
        } finally {
            fclose($handle);
        }
    }

    private function requireReviewer(string $actorRole): void
    {
        if ($actorRole !== 'super_admin') {
            throw new DomainException('Only the Super Administrator may review and acknowledge application logs.');
        }
    }
}
