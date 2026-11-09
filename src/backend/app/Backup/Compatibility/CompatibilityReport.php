<?php

namespace App\Backup\Compatibility;

/** Immutable classification of a package against the destination structure. */
final class CompatibilityReport
{
    public function __construct(private array $report) {}

    public function status(): string
    {
        return (string)$this->report['status'];
    }

    public function isSupported(): bool
    {
        return $this->status() !== RestoreCompatibilityRegistry::UNSUPPORTED;
    }

    public function toArray(): array
    {
        return $this->report;
    }
}
