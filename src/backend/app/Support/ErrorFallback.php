<?php

namespace App\Support;

/**
 * Global Operator Alert fallback for unhandled failures (ticket #54).
 *
 * One presentation for every surface that can die on the operator: the
 * normal-page fallback, the JSON fallback for scanner and API routes, and
 * the database connection failure path. Shop mode shows only the aligned
 * easy line with the red "Unable to continue" error kind. Debug mode
 * (APP_DEBUG) appends a small grey tech line. The full exception always
 * goes to the server log first, separate from what the operator sees.
 */
final class ErrorFallback
{
    /** Red error kind: an unhandled failure blocks the work in progress. */
    public const KIND = 'error';
    public const TITLE = 'Unable to continue';

    /** Aligned easy message for JSON scanner and API routes. */
    public const JSON_MESSAGE = 'The request could not be completed. Please try again. Tell your Administrator if this keeps happening.';

    /** Aligned easy line for the database connection failure path. */
    public const DB_EASY_LINE = 'RetailMind cannot reach its data right now. Please try again shortly. Tell your Super Administrator if this continues.';

    /** Scanner and API routes answer in JSON; everything else is a normal page. */
    public static function expectsJson(?array $server = null): bool
    {
        $server = $server ?? $_SERVER;
        $accept = (string)($server['HTTP_ACCEPT'] ?? '');
        $uri = (string)($server['REQUEST_URI'] ?? '');

        return stripos($accept, 'application/json') !== false
            || strpos($uri, '/barcodeScanner/apiScanner/') !== false
            || strpos($uri, '/api/') !== false;
    }

    /**
     * Grey tech line for developers: the root-cause exception class and
     * message, reached through any wrapping chain (for example the easy
     * RuntimeException the database connection failure throws).
     */
    public static function techDetail(\Throwable $exception): string
    {
        $root = $exception;
        while ($root->getPrevious() !== null) {
            $root = $root->getPrevious();
        }

        return $root::class . ': ' . $root->getMessage();
    }

    /**
     * Normal-page fallback: aligned easy line, red error kind, and a grey
     * tech line only when debug is on.
     */
    public static function page(\Throwable $exception, bool $debug, ?string $easyLine = null): string
    {
        $easy = $easyLine ?? OperatorAlert::GENERIC_FALLBACK;
        $tech = $debug ? self::techDetail($exception) : null;
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $escape(self::TITLE) . '</title></head><body>';
        $html .= '<main role="alert" data-kind="' . self::KIND . '" style="max-width:32rem;margin:12vh auto 0;padding:1.25rem 1.5rem;border-left:4px solid #dc2626;background:#fff;font-family:system-ui,sans-serif;">';
        $html .= '<h1 style="color:#dc2626;font-size:1.25rem;margin:0 0 0.5rem;">' . $escape(self::TITLE) . '</h1>';
        $html .= '<p style="color:#1f2937;margin:0;">' . $escape($easy) . '</p>';
        if ($tech !== null) {
            $html .= '<pre style="color:#9ca3af;font-size:0.8rem;white-space:pre-wrap;margin:0.75rem 0 0;">' . $escape($tech) . '</pre>';
        }
        $html .= '</main></body></html>';

        return $html;
    }

    /**
     * JSON fallback for scanner and API routes: aligned easy message, with
     * a tech line only when debug is on.
     *
     * @return array{success: bool, message: string, errors: array, tech?: string}
     */
    public static function json(\Throwable $exception, bool $debug, ?string $easyLine = null): array
    {
        $payload = [
            'success' => false,
            'message' => $easyLine ?? self::JSON_MESSAGE,
            'errors' => [],
        ];
        if ($debug) {
            $payload['tech'] = self::techDetail($exception);
        }

        return $payload;
    }
}
