<?php

namespace App\Store;

use RuntimeException;

/**
 * Raised when a Store mutation is admitted while a Database Backup snapshot is
 * being captured. The write is refused instead of queued or replayed so a
 * sale or stock change is never applied twice (#69).
 */
final class StoreWritePausedException extends RuntimeException
{
    public const USER_MESSAGE = 'Saving changes is temporarily paused while a database backup is prepared. Your work is still here — wait a moment and save again.';

    public function __construct(string $message = self::USER_MESSAGE, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
