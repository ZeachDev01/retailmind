<?php

require_once __DIR__ . '/../bootstrap/app.php';
restore_exception_handler();
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
    $filesBeforeFailure = glob($testDirectory . '/*');
    try {
        $storage->replace($upload($pngPath, 'replacement.png'), $rollbackFilename, static function (): void {
            throw new RuntimeException('Persistence failed');
        });
        $failures[] = 'A failed replacement persistence callback must throw.';
    } catch (RuntimeException $e) {
        $assert($storage->exists($rollbackFilename), 'A failed replacement removed the previous image.');
        $assert(glob($testDirectory . '/*') === $filesBeforeFailure, 'Failed persistence must clean up the staged replacement.');
    }

    $failingStorage = new ProfileImageStorage(
        $testDirectory,
        static fn(string $path): bool => is_file($path),
        static function (string $source, string $destination): bool {
            file_put_contents($destination, 'partial upload');
            return false;
        }
    );
    try {
        $failingStorage->replace($upload($pngPath, 'replacement.png'), $rollbackFilename, static function (): void {
            throw new LogicException('Storage failure must not reach persistence.');
        });
        $failures[] = 'Storage failure must reject replacement.';
    } catch (RuntimeException $e) {
        $assert($storage->exists($rollbackFilename), 'Storage failure must preserve the previous picture.');
        $assert(glob($testDirectory . '/*') === $filesBeforeFailure, 'Storage failure must clean up partial staged files.');
    }

    try {
        $storage->remove($rollbackFilename, static function (): void {
            throw new RuntimeException('Persistence failed');
        });
        $failures[] = 'Failed removal persistence must throw.';
    } catch (RuntimeException $e) {
        $assert($storage->exists($rollbackFilename), 'Failed removal must preserve the previous picture.');
    }

    $removePersisted = false;
    $deleteFailureStorage = new ProfileImageStorage(
        $testDirectory,
        'is_file',
        'copy',
        static fn(string $path): bool => basename($path) === $rollbackFilename ? false : unlink($path)
    );
    try {
        $deleteFailureStorage->replace($upload($pngPath, 'replacement.png'), $rollbackFilename, static function (): void {});
        $failures[] = 'Failed old-image deletion must not report replacement success.';
    } catch (RuntimeException $e) {
        $assert(glob($testDirectory . '/*') === $filesBeforeFailure, 'Deletion failure must preserve old image and clean up replacement.');
    }
    try {
        $deleteFailureStorage->remove($rollbackFilename, static function (): void {});
        $failures[] = 'Failed old-image deletion must not report removal success.';
    } catch (RuntimeException $e) {
        $assert($storage->exists($rollbackFilename), 'Deletion failure must preserve the previous image.');
    }
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
    $expectException(static fn() => $storage->store($upload($gifPath, 'avatar.png', 'image/png')), 'Renamed GIF uploads must be rejected.');
    $legacyGif = str_repeat('c', 64) . '.gif';
    file_put_contents($testDirectory . '/' . $legacyGif, file_get_contents($gifPath));
    $assert(($storage->read($legacyGif)['mime'] ?? null) === 'image/gif', 'Existing GIF pictures must remain viewable.');
    $assert($storage->delete($legacyGif), 'Existing GIF pictures must remain removable.');

    $pngChunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . hash('crc32b', $type . $data, true);
    $png = file_get_contents($pngPath);
    $atLimit = substr($png, 0, -12) . $pngChunk('tEXt', 'Comment' . "\0" . str_repeat('x', 5 * 1024 * 1024 - strlen($png) - 20)) . substr($png, -12);
    $boundaryPath = $makeFile($atLimit);
    $boundaryFilename = $storage->store($upload($boundaryPath, 'boundary.png'));
    $assert($storage->exists($boundaryFilename), 'A genuine picture at exactly 5 MB must be accepted.');
    $storage->delete($boundaryFilename);

    $boundaryMinusOne = $makeFile(substr($png, 0, -12) . $pngChunk('tEXt', 'Comment' . "\0" . str_repeat('x', 5 * 1024 * 1024 - strlen($png) - 21)) . substr($png, -12));
    $storage->delete($storage->store($upload($boundaryMinusOne, 'below-limit.png')));
    $boundaryPlusOne = $makeFile(substr($png, 0, -12) . $pngChunk('tEXt', 'Comment' . "\0" . str_repeat('x', 5 * 1024 * 1024 - strlen($png) - 19)) . substr($png, -12));
    $expectException(static fn() => $storage->store($upload($boundaryPlusOne, 'above-limit.png')), 'A genuine image one byte over 5 MB must be rejected.');
    $spoofedSize = $upload($boundaryPlusOne, 'small.png');
    $spoofedSize['size'] = 1;
    $expectException(static fn() => $storage->store($spoofedSize), 'Actual original-file size must override a spoofed small size.');
    $largeOriginal = $upload($pngPath, 'cropped.png');
    $largeOriginal['size'] = 5 * 1024 * 1024 + 1;
    $expectException(static fn() => $storage->store($largeOriginal), 'Reported oversized original must not pass with a smaller output.');

    $apng = substr($png, 0, 33) . $pngChunk('acTL', pack('NN', 2, 0)) . substr($png, 33);
    $webp = file_get_contents($webpPath);
    $webpChunk = static fn(string $type, string $data): string => $type . pack('V', strlen($data)) . $data . (strlen($data) % 2 ? "\0" : '');
    $animatedWebpChunks = $webpChunk('VP8X', "\x02" . str_repeat("\0", 9)) . $webpChunk('ANIM', str_repeat("\0", 6)) . $webpChunk('ANMF', str_repeat("\0", 16) . substr($webp, 12));
    $animatedWebp = 'RIFF' . pack('V', strlen($animatedWebpChunks) + 4) . 'WEBP' . $animatedWebpChunks;
    $animationMessage = 'Animated profile pictures are not supported. Choose a still JPG, PNG, or WebP image.';
    foreach ([$apng, $animatedWebp] as $animation) {
        $animationPath = $makeFile($animation);
        $expectException(static fn() => $storage->store($upload($animationPath, 'still.jpg', 'image/jpeg')), 'Animated images must be rejected despite renamed extension and spoofed MIME.', $animationMessage);
    }
    foreach (['png' => $apng, 'webp' => $animatedWebp] as $extension => $legacyContents) {
        $legacyFilename = str_repeat('d', 32) . '.' . $extension;
        file_put_contents($testDirectory . '/' . $legacyFilename, $legacyContents);
        $assert($storage->read($legacyFilename) !== null, 'Existing animated pictures must remain viewable until replaced or removed.');
        $storage->delete($legacyFilename);
    }
    // Metadata may contain animation marker text without making an image animated.
    $stillMetadata = $makeFile(substr($png, 0, -12) . $pngChunk('tEXt', "Comment\0acTL fcTL ANIM ANMF") . substr($png, -12));
    $storage->delete($storage->store($upload($stillMetadata, 'still.png')));

    $invalidImages = ['', substr($png, 0, 33), substr($png, 0, -5), substr(file_get_contents($jpegPath), 0, -2), substr($webp, 0, -4)];
    $jpeg = file_get_contents($jpegPath);
    $invalidImages[] = substr($jpeg, 0, 2) . "\xff\xfe\x00\x04\xff\xd9" . substr($jpeg, 2, -2);
    // Keep valid headers and CRCs: reject dangerous dimensions before attempting a decode.
    foreach ([[8001, 1], [1, 8001], [8000, 8000], [0, 1]] as [$width, $height]) {
        $invalidImages[] = substr($png, 0, 8) . $pngChunk('IHDR', pack('NN', $width, $height) . substr($png, 24, 5)) . substr($png, 33);
    }
    $corruptPng = $png;
    $corruptPng[45] = chr(ord($corruptPng[45]) ^ 1);
    $invalidImages[] = $corruptPng;
    $invalidImages[] = substr($png, 0, 33) . $pngChunk('IDAT', 'not compressed pixels') . substr($png, -12);
    $beforeValidation = glob($testDirectory . '/*');
    foreach ($invalidImages as $invalidImage) {
        $invalidPath = $makeFile($invalidImage);
        $expectException(static fn() => $storage->replace($upload($invalidPath, 'avatar.png', 'image/png'), $storedFilename, static function (): void {
            throw new LogicException('Invalid image must never reach account persistence.');
        }), 'Empty, malformed, or unsafe-dimension uploads must be rejected.');
        $assert($storage->exists($storedFilename) && glob($testDirectory . '/*') === $beforeValidation, 'Failed validation must preserve saved picture and leave no staged files.');
    }
    foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL] as $error) {
        $failedUpload = $upload($pngPath, 'avatar.png');
        $failedUpload['error'] = $error;
        $expectException(static fn() => $storage->store($failedUpload), 'PHP upload errors must fail without saving.');
    }

    $oversizedPath = $makeFile(str_repeat('x', ProfileImageStorage::MAX_BYTES + 1));
    $expectException(
        static fn() => $storage->store($upload($oversizedPath, 'large.png', 'image/png')),
        'Oversized uploads must be rejected before storage.',
        'Profile pictures must not exceed 5 MB.'
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
