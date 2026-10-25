<?php
// Runtime/filesystem failure boundaries used only by disposable safety-copy tests.
namespace App\Backup;
function openssl_encrypt(string $data, string $cipher, string $key, int $options = 0, string $iv = '', ?string &$tag = null, string $aad = '', int $tag_length = 16): string|false
{
    if (($GLOBALS['safetyFault'] ?? '') === 'encryption') return false;
    return \openssl_encrypt($data, $cipher, $key, $options, $iv, $tag, $aad, $tag_length);
}
function fopen(string $filename, string $mode, bool $use_include_path = false, $context = null)
{
    if (($GLOBALS['safetyFault'] ?? '') === 'storage' && $mode === 'xb' && str_ends_with($filename, '.rmpkg')) return false;
    if (($GLOBALS['safetyFault'] ?? '') === 'checkpoint' && str_contains($filename, '/metadata.json.') && $mode === 'wb') return false;
    return $context === null ? \fopen($filename, $mode, $use_include_path) : \fopen($filename, $mode, $use_include_path, $context);
}
function file_get_contents(string $filename, bool $use_include_path = false, $context = null, int $offset = 0, ?int $length = null): string|false
{
    $contents = \file_get_contents($filename, $use_include_path, $context, $offset, $length);
    if (($GLOBALS['safetyFault'] ?? '') === 'verification' && str_ends_with($filename, '.rmpkg') && is_string($contents)) $contents[strlen($contents)-1] = chr(ord($contents[strlen($contents)-1]) ^ 1);
    return $contents;
}
function disk_free_space(string $directory): float|false
{
    return ($GLOBALS['safetyFault'] ?? '') === 'capacity' ? 0 : \disk_free_space($directory);
}
