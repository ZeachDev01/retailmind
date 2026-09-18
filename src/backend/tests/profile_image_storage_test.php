<?php

require_once __DIR__ . '/../bootstrap/app.php';
if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        return '/' . ltrim($path, '/');
    }
}
require_once __DIR__ . '/../includes/profile_images.php';

use App\Services\ProfileImageStorage;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$expectException = static function (callable $callback, string $message, ?string $expectedMessage = null) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message;
    } catch (InvalidArgumentException $e) {
        if ($expectedMessage !== null && $e->getMessage() !== $expectedMessage) {
            $failures[] = $message . ' Unexpected message: ' . $e->getMessage();
        }
    }
};

$testDirectory = sys_get_temp_dir() . '/retailmind-profile-images-' . bin2hex(random_bytes(6));
$temporaryFiles = [];
$makeFile = static function (string $contents) use (&$temporaryFiles): string {
    $path = tempnam(sys_get_temp_dir(), 'retailmind-upload-');
    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary test file.');
    }
    file_put_contents($path, $contents);
    $temporaryFiles[] = $path;
    return $path;
};
$upload = static function (string $path, string $name, string $type = 'application/octet-stream'): array {
    return [
        'name' => $name,
        'type' => $type,
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($path),
    ];
};

$storage = new ProfileImageStorage(
    $testDirectory,
    static fn(string $path): bool => is_file($path),
    static fn(string $source, string $destination): bool => copy($source, $destination)
);

try {
    $assert($storage->store(['error' => UPLOAD_ERR_NO_FILE]) === null, 'An omitted profile image must remain optional.');

    $pngPath = $makeFile(base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true
    ));
    $storedFilename = $storage->store($upload($pngPath, '../../avatar.php.png', 'application/x-php'));
    $assert(
        is_string($storedFilename) && preg_match('/^[a-f0-9]{64}\.png$/', $storedFilename) === 1,
        'Stored images must use an opaque generated filename and a server-derived extension.'
    );
    $assert($storage->exists($storedFilename), 'A valid PNG upload was not persisted.');
    $storedImage = $storage->read($storedFilename);
    $assert(($storedImage['mime'] ?? null) === 'image/png', 'The persisted image MIME type was not derived safely.');
    $assert(($storedImage['contents'] ?? null) === file_get_contents($pngPath), 'The persisted image content changed unexpectedly.');

    $oldFilename = str_repeat('a', 64) . '.png';
    file_put_contents($testDirectory . '/' . $oldFilename, file_get_contents($pngPath));
    $persistedFilename = null;
    $replacement = $storage->replace(
        $upload($pngPath, 'replacement.png', 'image/png'),
        $oldFilename,
        static function (string $filename) use (&$persistedFilename): void {
            $persistedFilename = $filename;
        }
    );
    $assert($replacement === $persistedFilename, 'Replacement did not persist the generated filename.');
    $assert(!$storage->exists($oldFilename), 'Replacement did not remove the superseded image.');

    $rollbackFilename = str_repeat('b', 64) . '.png';
    file_put_contents($testDirectory . '/' . $rollbackFilename, file_get_contents($pngPath));
    try {
        $storage->replace($upload($pngPath, 'replacement.png'), $rollbackFilename, static function (): void {
            throw new RuntimeException('Persistence failed');
        });
        $failures[] = 'A failed replacement persistence callback must throw.';
    } catch (RuntimeException $e) {
        $assert($storage->exists($rollbackFilename), 'A failed replacement removed the previous image.');
    }

    $removePersisted = false;
    $storage->remove($rollbackFilename, static function () use (&$removePersisted): void {
        $removePersisted = true;
    });
    $assert($removePersisted && !$storage->exists($rollbackFilename), 'Removal did not persist and delete the image.');

    $jpegPath = $makeFile(base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gOTAK/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU/8AAEQgAAQABAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A/KqiiigD/9k=',
        true
    ));
    $jpegFilename = $storage->store($upload($jpegPath, 'avatar.png', 'image/png'));
    $assert(is_string($jpegFilename) && str_ends_with($jpegFilename, '.jpg'), 'JPEG uploads must use the server-derived extension.');
    $assert($storage->delete($jpegFilename), 'A persisted JPEG profile image could not be removed.');

    $webpPath = $makeFile(base64_decode('UklGRiYAAABXRUJQVlA4IBoAAAAwAQCdASoBAAEAAMASJaQAA3AA/v7uqgAAAA==', true));
    $webpFilename = $storage->store($upload($webpPath, 'avatar.jpg', 'image/jpeg'));
    $assert(is_string($webpFilename) && str_ends_with($webpFilename, '.webp'), 'WebP uploads must use the server-derived extension.');
    $assert($storage->delete($webpFilename), 'A persisted WebP profile image could not be removed.');

    $executablePath = $makeFile("<?php echo 'unsafe'; ?>");
    $expectException(
        static fn() => $storage->store($upload($executablePath, 'avatar.png', 'image/png')),
        'Executable content disguised as an image must be rejected.'
    );

    $gifPath = $makeFile(base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true));
    $gifFilename = $storage->store($upload($gifPath, 'avatar.gif', 'image/gif'));
    $assert(is_string($gifFilename) && str_ends_with($gifFilename, '.gif'), 'GIF uploads must use the server-derived extension.');
    $assert($storage->delete($gifFilename), 'A persisted GIF profile image could not be removed.');

    $oversizedPath = $makeFile(str_repeat('x', ProfileImageStorage::MAX_BYTES + 1));
    $expectException(
        static fn() => $storage->store($upload($oversizedPath, 'large.png', 'image/png')),
        'Oversized uploads must be rejected before storage.',
        'Profile pictures must not exceed 2MB.'
    );

    $assert($storage->read('../app.log') === null, 'Storage traversal attempts must not resolve a file.');
    $fallbackAvatar = profile_avatar_html(42, 'Test Person', str_repeat('a', 64) . '.png', 'test-avatar', $storage);
    $assert(strpos($fallbackAvatar, 'TP') !== false, 'A missing stored image must render the standard initials fallback.');
    $assert(strpos($fallbackAvatar, '<img') === false, 'A missing stored image must not render a broken image element.');
    $assert($storage->delete($storedFilename), 'A persisted profile image could not be removed.');
    $assert(!$storage->exists($storedFilename), 'Deleted profile image content still exists.');
    $assert($storage->delete('missing.png'), 'Missing or invalid stored image names should be safe to clean up.');
} finally {
    foreach ($temporaryFiles as $temporaryFile) {
        @unlink($temporaryFile);
    }
    if (is_dir($testDirectory)) {
        foreach (glob($testDirectory . '/*') ?: [] as $storedFile) {
            @unlink($storedFile);
        }
        @rmdir($testDirectory);
    }
}

if ($failures) {
    fwrite(STDERR, "Profile image storage tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Profile image storage tests: passed\n";