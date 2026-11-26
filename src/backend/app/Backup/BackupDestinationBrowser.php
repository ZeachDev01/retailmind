<?php

namespace App\Backup;

use InvalidArgumentException;

/** Lists only directories on the Store PC, never file contents. */
final class BackupDestinationBrowser
{
    public static function isStorePcRequest(array $server): bool
    {
        if (!in_array($server['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
            return false;
        }
        // shortcut: origin-stripping localhost proxies look local; require device approval if those proxies must be supported.
        foreach (['HTTP_FORWARDED', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_CF_RAY'] as $header) {
            if (!empty($server[$header])) {
                return false;
            }
        }
        $host = strtolower((string)parse_url('http://' . ($server['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public static function listing(string $path): array
    {
        if ($path === '') {
            $roots = PHP_OS_FAMILY === 'Windows'
                ? array_map(static fn(string $drive): string => $drive . ':\\', range('A', 'Z'))
                : ['/'];
            $folders = [];
            foreach ($roots as $root) {
                if (@is_dir($root) && self::canBrowse($root)) {
                    $folders[] = ['name' => $root, 'path' => $root];
                }
            }
            return ['path' => '', 'parent' => null, 'selectable' => false, 'folders' => $folders];
        }

        if (str_contains($path, "\0") || preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) !== 1
            || preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~', $path) === 1) {
            throw new InvalidArgumentException('Enter an absolute folder path on the Store PC.');
        }
        $real = realpath($path);
        if ($real === false || !is_dir($real) || !self::canBrowse($real)) {
            throw new InvalidArgumentException('This Store PC folder is unavailable. Choose another folder.');
        }
        $entries = @scandir($real);
        if ($entries === false) {
            throw new InvalidArgumentException('This Store PC folder cannot be opened. Choose another folder.');
        }
        $folders = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $real . DIRECTORY_SEPARATOR . $name;
            $resolved = realpath($child);
            if ($resolved !== false && !is_link($child) && is_dir($resolved) && self::canBrowse($resolved)) {
                $folders[] = ['name' => $name, 'path' => $resolved];
            }
        }
        usort($folders, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        $parent = dirname($real);
        return [
            'path' => $real,
            'parent' => $parent !== $real && self::canBrowse($parent) ? $parent : '',
            'selectable' => is_writable($real) && ScheduledBackupSettings::isOutsideRecoveryStore($real),
            'folders' => $folders,
        ];
    }

    private static function canBrowse(string $path): bool
    {
        if (!ScheduledBackupSettings::isOutsidePublicRoots($path)) {
            return false;
        }
        $store = strtolower(str_replace('\\', '/', RecoveryStore::directory()));
        $candidate = strtolower(rtrim(str_replace('\\', '/', $path), '/'));
        return $candidate !== $store && !str_starts_with($candidate, $store . '/');
    }
}
