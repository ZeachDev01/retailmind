<?php

final class OpenFoodFactsCatalog
{
    private const ORIGIN = 'https://world.openfoodfacts.org';
    public const PHOTO_LICENSE_URL = 'https://creativecommons.org/licenses/by-sa/3.0/';
    private const FIELDS = 'code,product_name,brands,quantity,categories_tags,image_front_url';

    public static function supports(string $code): bool
    {
        if (!preg_match('/^(?:[0-9]{8}|[0-9]{12,14})$/D', $code) || trim($code, '0') === '') return false;

        $sum = 0;
        foreach (str_split(strrev($code)) as $index => $digit) {
            $sum += (int)$digit * ($index % 2 === 0 ? 1 : 3);
        }
        return $sum % 10 === 0;
    }

    public static function lookup(string $code, ?callable $request = null): array
    {
        $url = self::ORIGIN . '/api/v3/product/' . $code . '?fields=' . self::FIELDS;
        [$status, $body] = ($request ?? self::request(...))($url);
        $response = is_string($body) ? json_decode($body, true) : null;
        $result = $response['result']['id'] ?? null;
        if ($status === 404 && $result === 'product_not_found') return ['status' => 'not_found'];

        $product = $response['product'] ?? null;
        $returnedCode = $product['code'] ?? null;
        if ($status !== 200 || $result !== 'product_found' || !is_string($returnedCode) || !self::supports($returnedCode)
            || str_pad($returnedCode, 14, '0', STR_PAD_LEFT) !== str_pad($code, 14, '0', STR_PAD_LEFT)) {
            return ['status' => 'unavailable'];
        }

        $tags = $product['categories_tags'] ?? [];
        $photo = $product['image_front_url'] ?? null;

        return [
            'status' => 'found',
            'product' => [
                'name' => self::text($product['product_name'] ?? null, 150),
                'brand' => self::text($product['brands'] ?? null, 100),
                'declared_size' => self::text($product['quantity'] ?? null, 100),
                'category_tags' => is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
                'photo_url' => is_string($photo) && self::isPhotoUrl($photo) ? $photo : null,
            ],
            'source' => [
                'name' => 'Open Food Facts',
                'product_url' => self::ORIGIN . '/product/' . $returnedCode,
                'photo_license_url' => self::PHOTO_LICENSE_URL,
            ],
        ];
    }

    private static function text(mixed $value, int $limit): ?string
    {
        if (!is_string($value)) return null;
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        return $value !== '' && mb_strlen($value) <= $limit ? $value : null;
    }

    private static function isPhotoUrl(string $url): bool
    {
        return preg_match('~^https://(?:images|static)\.openfoodfacts\.org/images/products/(?:[0-9]+/)*[0-9]+/[a-z0-9_][a-z0-9_.-]*\.(?:jpe?g|png|gif|webp)$~D', $url) === 1;
    }

    public static function downloadPhoto(string $url, int $limit, ?callable $request = null): ?string
    {
        if (!self::isPhotoUrl($url)) return null;
        [$status, $body] = $request ? $request($url) : self::request($url, $limit, 'image/*', connectTimeoutMs: 5000, timeoutMs: 10000);
        return $status === 200 && is_string($body) && strlen($body) <= $limit ? $body : null;
    }

    private static function request(
        string $url,
        int $limit = 65536,
        string $accept = 'application/json',
        int $connectTimeoutMs = 1500,
        int $timeoutMs = 3500,
    ): array
    {
        if (!function_exists('curl_init')) return [0, null];

        $curl = curl_init($url);
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_USERAGENT => 'RetailMind/1.0 (https://github.com/ZeachDev01/retailmind)',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($curl, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        $completed = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        return [$status, $completed === false ? null : $body];
    }
}
