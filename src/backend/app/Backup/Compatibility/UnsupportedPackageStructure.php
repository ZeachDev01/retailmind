<?php

namespace App\Backup\Compatibility;

use DomainException;

/** Package structure this installation refuses to guess at; carries the review report. */
final class UnsupportedPackageStructure extends DomainException
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
