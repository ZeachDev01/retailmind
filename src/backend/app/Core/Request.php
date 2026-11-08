<?php

namespace App\Core;

final class Request
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    public function post(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }

    public function input(string $key, $default = null)
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function expectsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        return stripos($accept, 'application/json') !== false
            || stripos($contentType, 'application/json') !== false
            || strpos($_SERVER['REQUEST_URI'] ?? '', '/barcodeScanner/apiScanner/') !== false
            || strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    }

    public function isSecure(): bool
    {
        return function_exists('app_is_secure') ? app_is_secure() : false;
    }

    public function scheme(): string
    {
        return function_exists('app_scheme') ? app_scheme() : 'http';
    }

    public function host(): string
    {
        return function_exists('app_host') ? app_host() : 'localhost';
    }

    public function origin(): string
    {
        return function_exists('app_origin') ? app_origin() : ($this->scheme() . '://' . $this->host());
    }
}
