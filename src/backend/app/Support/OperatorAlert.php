<?php

namespace App\Support;

/**
 * Builds Operator Alert text at the service/page boundary (ticket #52).
 *
 * Shop mode shows only easy words. Debug mode (APP_DEBUG) appends a small
 * tech line for the developer. Full exception detail always goes to the log,
 * separate from what the operator sees.
 */
final class OperatorAlert
{
    public const GENERIC_FALLBACK = 'Something went wrong. Please try again. Tell your Administrator if this keeps happening.';

    public static function isDebug(): bool
    {
        return (bool)($GLOBALS['app']['debug'] ?? false);
    }

    public static function techDetail(\Throwable $exception): string
    {
        return $exception::class . ': ' . $exception->getMessage();
    }

    public static function isTechnicalMessage(string $message): bool
    {
        if (trim($message) === '') {
            return true;
        }

        $patterns = [
            '/SQLSTATE/i',
            '/\b(?:PDOException|RuntimeException|LogicException|DomainException|InvalidArgumentException|UnexpectedValueException|OutOfBoundsException|LengthException|OverflowException|UnderflowException|RangeException|Exception|Throwable|Error)\b/',
            '/Stack trace/i',
            '/#\d+\s+\S+\.php/',
            '/\.php\s*:\s*\d+/',
            '/[A-Za-z]:\\\\[^\s]+/',
            '/\/(?:var|home|usr|etc|opt|proc|xampp|Users|Applications)\/[^\s]+/',
            '/Object of class/i',
            '/Call to (?:a )?member function/i',
            '/Undefined (?:variable|array key| property)/i',
            '/allowed memory size/i',
            '/Maximum execution time/i',
            '/syntax error/i',
            '/Array to string conversion/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Easy line for the operator, plus a tech line only when debug is on.
     * Plain domain messages pass through; technical text becomes $friendly.
     * The full exception is always written to the server log.
     */
    public static function message(\Throwable $exception, string $friendly): string
    {
        self::log($exception);

        $easy = self::isTechnicalMessage($exception->getMessage())
            ? ($friendly !== '' ? $friendly : self::GENERIC_FALLBACK)
            : $exception->getMessage();

        if (self::isDebug()) {
            $easy .= "\n" . self::techDetail($exception);
        }

        return $easy;
    }

    private static function log(\Throwable $exception): void
    {
        error_log(sprintf(
            '[operator-alert] %s: %s in %s:%d',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));
    }
}
