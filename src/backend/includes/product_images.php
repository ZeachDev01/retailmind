<?php

function product_image_url(?string $reference): string
{
    if (!preg_match('#^storage/images/([A-Za-z0-9_-]+\.(?:jpe?g|png|gif|webp))$#i', $reference ?? '', $match)) {
        return '';
    }
    return app_url('components/inventory_management/product_image.php?file=' . rawurlencode($match[1]));
}

function product_image_file(?string $reference): ?array
{
    if (product_image_url($reference) === '') return null;
    $root = realpath(($GLOBALS['app']['storage_path'] ?? dirname(__DIR__) . '/storage') . '/images');
    $path = $root === false ? false : realpath($root . '/' . basename($reference));
    if ($path === false || dirname($path) !== $root || !is_file($path) || !is_readable($path)) return null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) return null;
    return ['path' => $path, 'mime' => $mime];
}
