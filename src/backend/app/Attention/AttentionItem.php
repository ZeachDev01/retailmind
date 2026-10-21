<?php

namespace App\Attention;

use DateTimeImmutable;
use InvalidArgumentException;

final class AttentionItem
{
    private const SEVERITIES = ['critical', 'warning', 'info'];

    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly string $category,
        public readonly string $title,
        public readonly string $explanation,
        public readonly DateTimeImmutable $detectedAt,
        public readonly string $destination,
        public readonly ?int $count = null,
        public readonly bool $required = true,
        public readonly string $notificationType = 'attention',
        public readonly ?int $referenceId = null,
        public readonly ?string $referenceType = 'attention' 
    ) {
        if ($key === '' || $category === '' || $title === '' || $explanation === '') {
            throw new InvalidArgumentException('Attention items require a key, category, title, and explanation.');
        }
        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException("Unsupported attention severity: {$severity}");
        }
        if (str_contains($destination, '..')
            || !preg_match('#^components/[a-z0-9_./-]+\.php(?:\?.*)?$#i', $destination)) {
            throw new InvalidArgumentException('Attention destinations must be permitted application-relative component routes.');
        }
        if ($count !== null && $count < 0) {
            throw new InvalidArgumentException('Attention item counts cannot be negative.');
        }
    }

    public function withDetectedAt(DateTimeImmutable $detectedAt): self
    {
        return new self(
            $this->key,
            $this->severity,
            $this->category,
            $this->title,
            $this->explanation,
            $detectedAt,
            $this->destination,
            $this->count,
            $this->required, $this->notificationType, $this->referenceId, $this->referenceType
        );
    }

    public function notificationLevel(): int
    {
        $level = match ($this->severity) { 'critical' => 3000, 'warning' => 2000, default => 1000 };
        if ($this->notificationType === 'expiring_stock' && preg_match('/^product_batch_expiry_(\d+)$/', $this->referenceType ?? '', $match)) {
            $level += 365 - min(365, (int)$match[1]);
        }
        return $level;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->severity,
            $this->title,
            $this->destination,
        ], JSON_THROW_ON_ERROR));
    }

    public function toArray(): array
    {
        $item = [
            'key' => $this->key,
            'severity' => $this->severity,
            'category' => $this->category,
            'title' => $this->title,
            'explanation' => $this->explanation,
            'detected_at' => $this->detectedAt->format('Y-m-d H:i:s'),
            'destination' => $this->destination,
        ];
        if ($this->count !== null) {
            $item['count'] = $this->count;
        }
        return $item;
    }
}
