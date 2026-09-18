<?php

use App\Services\ProfileImageStorage;

function profile_image_storage(): ProfileImageStorage
{
    static $storage = null;
    if ($storage === null) {
        $storagePath = $GLOBALS['app']['storage_path'] ?? dirname(__DIR__) . '/storage';
        $storage = new ProfileImageStorage($storagePath . '/profile-images');
    }
    return $storage;
}

function profile_initials(string $name, string $fallback = 'RM'): string
{
    $initials = '';
    foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    return substr($initials ?: strtoupper(substr($fallback, 0, 2)) ?: 'RM', 0, 2);
}

function profile_image_url(int $userId): string
{
    $version = '';
    if ($userId === (int)($_SESSION['user_id'] ?? 0) && !empty($_SESSION['profile_image'])) {
        $version = '&v=' . substr(hash('sha256', (string)$_SESSION['profile_image']), 0, 12);
    }
    return app_url('components/auth/profile_image.php?user_id=' . $userId . $version);
}

function profile_avatar_html(
    int $userId,
    string $name,
    ?string $filename,
    string $className = '',
    ?ProfileImageStorage $storage = null
): string {
    $storage ??= profile_image_storage();
    $classes = trim('profile-avatar ' . $className);
    $html = '<span class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true">';
    $html .= '<span class="profile-avatar-fallback">' . htmlspecialchars(profile_initials($name), ENT_QUOTES, 'UTF-8') . '</span>';
    if ($userId > 0 && $filename !== null && $storage->exists($filename)) {
        $html .= '<img class="profile-avatar-image" src="' . htmlspecialchars(profile_image_url($userId), ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy" decoding="async" onerror="this.remove()">';
    }
    return $html . '</span>';
}