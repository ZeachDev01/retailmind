<?php

namespace App\Store;

use RuntimeException;

final class StoreConsolidationRequired extends RuntimeException
{
    public function __construct(private array $consolidationReport)
    {
        $details = array_map(
            static fn(array $branch): string => sprintf(
                '%s (%s): %d users, %d products',
                $branch['branch_name'],
                $branch['branch_code'],
                $branch['user_count'],
                $branch['product_count']
            ),
            $consolidationReport['branches']
        );

        parent::__construct(
            'Singleton Store migration requires branch consolidation before it can continue: '
            . implode('; ', $details)
            . '. No operational data was changed.'
        );
    }

    public function report(): array
    {
        return $this->consolidationReport;
    }
}
