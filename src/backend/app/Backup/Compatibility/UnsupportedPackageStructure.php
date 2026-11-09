<?php

namespace App\Backup\Compatibility;

use RuntimeException;

/** Package structure this installation refuses to guess at. */
final class UnsupportedPackageStructure extends RuntimeException
{
    public function __construct(private array $report)
    {
        parent::__construct((string)($report['message'] ?? 'The package has an unsupported database structure.'));
    }

    public function report(): array
    {
        return $this->report;
    }
}
