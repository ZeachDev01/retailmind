<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ProfileImageStorage
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_FILE_SIZE = self::MAX_BYTES;
    public const MAX_DIMENSION = 8000;
    public const MAX_PIXELS = 40000000;
    public const ACCEPT = 'image/jpeg,image/png,image/webp';
    public const HELP = 'JPG, PNG, or WebP. Maximum 5 MB. No animation.';

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private $isUploadedFile;
    private $moveUploadedFile;
    private $deleteFile;

    public function __construct(
        private string $directory,
        ?callable $isUploadedFile = null,
        ?callable $moveUploadedFile = null,
        ?callable $deleteFile = null
    ) {
        $this->directory = rtrim($directory, '/\\');
        $this->isUploadedFile = $isUploadedFile ?? static fn(string $path): bool => is_uploaded_file($path);
        $this->moveUploadedFile = $moveUploadedFile ?? static fn(string $source, string $destination): bool => move_uploaded_file($source, $destination);
        $this->deleteFile = $deleteFile ?? static fn(string $path): bool => unlink($path);
    }

    public static function hasUpload(?array $file): bool
    {
        return is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    public function store(?array $file): ?string
    {
        if (!self::hasUpload($file)) {
            return null;
        }

        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new InvalidArgumentException('Profile pictures must not exceed 5 MB.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('The profile picture could not be uploaded. Please try another image.');
        }

        $temporaryPath = (string)($file['tmp_name'] ?? '');
        $reportedSize = (int)($file['size'] ?? 0);
        if ($temporaryPath === '' || $reportedSize <= 0 || !($this->isUploadedFile)($temporaryPath)) {
            throw new InvalidArgumentException('Select a valid JPG, PNG, or WebP image.');
        }
        if ($reportedSize > self::MAX_BYTES) {
            throw new InvalidArgumentException('Profile pictures must not exceed 5 MB.');
        }

        $actualSize = filesize($temporaryPath);
        if ($actualSize === false || $actualSize <= 0) {
            throw new InvalidArgumentException('Select a valid JPG, PNG, or WebP image.');
        }
        if ($actualSize > self::MAX_BYTES) {
            throw new InvalidArgumentException('Profile pictures must not exceed 5 MB.');
        }

        $mime = $this->detectSupportedMime($temporaryPath);
        // GIF remains readable for existing pictures, but cannot be uploaded again.
        if ($mime === 'image/gif') {
            throw new InvalidArgumentException('Only JPG, PNG, and nonanimated WebP profile pictures are supported.');
        }
        $imageInfo = @getimagesize($temporaryPath);
        if ($imageInfo === false || !$this->imageTypeMatchesMime((int)$imageInfo[2], $mime)) {
            throw new InvalidArgumentException('Select a valid JPG, PNG, or WebP image.');
        }

        $width = (int)($imageInfo[0] ?? 0);
        $height = (int)($imageInfo[1] ?? 0);
        if ($width <= 0 || $height <= 0 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || ($width * $height) > self::MAX_PIXELS) {
            throw new InvalidArgumentException('The profile picture dimensions are too large.');
        }

        $this->validateStillImage($temporaryPath, $mime);
        $this->ensureDirectory();
        $extension = self::MIME_EXTENSIONS[$mime];
        do {
            $filename = bin2hex(random_bytes(32)) . '.' . $extension;
            $destination = $this->directory . DIRECTORY_SEPARATOR . $filename;
        } while (is_file($destination));

        if (!($this->moveUploadedFile)($temporaryPath, $destination)) {
            $this->delete($filename);
            throw new RuntimeException('The profile picture could not be saved.');
        }
        @chmod($destination, 0640);

        return $filename;
    }

    public function replace(array $file, ?string $currentFilename, callable $persist, ?callable $complete = null): string
    {
        $filename = $this->store($file);
        if ($filename === null) {
            throw new InvalidArgumentException('Select a valid image to upload.');
        }

        try {
            $this->persistAndDelete($currentFilename, static fn() => $persist($filename), $complete);
        } catch (Throwable $exception) {
            $this->delete($filename);
            throw $exception;
        }

        return $filename;
    }

    public function remove(?string $currentFilename, callable $persist, ?callable $complete = null): void
    {
        $this->persistAndDelete($currentFilename, $persist, $complete);
    }

    private function persistAndDelete(?string $currentFilename, callable $persist, ?callable $complete): void
    {
        $path = $this->pathFor($currentFilename);
        // Keep only a transient copy until the database commit succeeds, never picture history.
        $previousContents = $path !== null && is_file($path) ? file_get_contents($path) : null;
        if ($previousContents === false) {
            throw new RuntimeException('The current profile picture could not be read. Please try again.');
        }
        try {
            $persist();
            if (!$this->delete($currentFilename)) {
                throw new RuntimeException('The previous profile picture could not be removed. Please try again.');
            }
            if ($complete !== null) $complete();
        } catch (Throwable $exception) {
            if ($previousContents !== null && !is_file($path)) {
                if (file_put_contents($path, $previousContents) === false) {
                    throw new RuntimeException('The previous profile picture could not be restored.', 0, $exception);
                }
                @chmod($path, 0640);
            }
            throw $exception;
        }
    }

    public function exists(?string $filename): bool
    {
        $path = $this->pathFor($filename);
        return $path !== null && is_file($path);
    }

    public function read(?string $filename): ?array
    {
        $image = $this->resolve($filename);
        if ($image === null) {
            return null;
        }

        $contents = file_get_contents($image['path']);
        if ($contents === false) {
            return null;
        }

        return ['mime' => $image['mime'], 'contents' => $contents];
    }

    public function resolve(?string $filename): ?array
    {
        $path = $this->pathFor($filename);
        if ($path === null || !is_file($path) || !is_readable($path)) {
            return null;
        }

        try {
            $mime = $this->detectSupportedMime($path);
        } catch (InvalidArgumentException $e) {
            return null;
        }
        $imageInfo = @getimagesize($path);
        if ($imageInfo === false || !$this->imageTypeMatchesMime((int)$imageInfo[2], $mime)) {
            return null;
        }

        return ['path' => $path, 'mime' => $mime];
    }

    public function delete(?string $filename): bool
    {
        $path = $this->pathFor($filename);
        if ($path === null || !is_file($path)) {
            return true;
        }
        return ($this->deleteFile)($path);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Profile picture storage is unavailable.');
        }
        if (!is_writable($this->directory)) {
            throw new RuntimeException('Profile picture storage is unavailable.');
        }
    }

    private function validateStillImage(string $path, string $mime): void
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('The profile picture could not be read. Please try again.');
        }
        $invalid = 'Select a complete, valid JPG, PNG, or WebP image.';
        if ($mime === 'image/png' || $mime === 'image/webp') {
            $png = $mime === 'image/png';
            $size = strlen($contents);
            if (!$png && unpack('V', substr($contents, 4, 4))[1] + 8 !== $size) {
                throw new InvalidArgumentException($invalid);
            }
            for ($offset = $png ? 8 : 12; $offset < $size;) {
                $overhead = $png ? 12 : 8;
                if ($size - $offset < $overhead) throw new InvalidArgumentException($invalid);
                $type = substr($contents, $offset + ($png ? 4 : 0), 4);
                $length = unpack($png ? 'N' : 'V', substr($contents, $offset + ($png ? 0 : 4), 4))[1];
                $end = $offset + $overhead + $length + ($png ? 0 : $length % 2);
                if ($end > $size) throw new InvalidArgumentException($invalid);
                $data = substr($contents, $offset + 8, $length);
                if ($png && hash('crc32b', $type . $data, true) !== substr($contents, $end - 4, 4)) {
                    throw new InvalidArgumentException($invalid);
                }
                if (($png && in_array($type, ['acTL', 'fcTL', 'fdAT'], true))
                    || (!$png && (in_array($type, ['ANIM', 'ANMF'], true)
                        || ($type === 'VP8X' && $length > 0 && (ord($data[0]) & 2) !== 0)))) {
                    throw new InvalidArgumentException('Animated profile pictures are not supported. Choose a still JPG, PNG, or WebP image.');
                }
                $offset = $end;
            }
            if ($png && ($type !== 'IEND' || $length !== 0)) throw new InvalidArgumentException($invalid);
        } elseif (!str_ends_with($contents, "\xff\xd9")) {
            throw new InvalidArgumentException($invalid);
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Profile picture validation is unavailable. Tell your Administrator.');
        }
        $decodeWarning = false;
        set_error_handler(static function () use (&$decodeWarning): bool { $decodeWarning = true; return true; });
        try {
            $image = imagecreatefromstring($contents);
        } finally {
            restore_error_handler();
        }
        if ($image === false || $decodeWarning) throw new InvalidArgumentException($invalid);
    }

    private function detectSupportedMime(string $path): string
    {
        $mime = (string)finfo_file(new \finfo(FILEINFO_MIME_TYPE), $path);
        if (!isset(self::MIME_EXTENSIONS[$mime])) {
            throw new InvalidArgumentException('Only JPG, PNG, and nonanimated WebP profile pictures are supported.');
        }
        return $mime;
    }

    private function imageTypeMatchesMime(int $imageType, string $mime): bool
    {
        $expectedTypes = [
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/png' => IMAGETYPE_PNG,
            'image/gif' => IMAGETYPE_GIF,
            'image/webp' => IMAGETYPE_WEBP,
        ];
        return ($expectedTypes[$mime] ?? null) === $imageType;
    }

    private function pathFor(?string $filename): ?string
    {
        if ($filename === null || preg_match('/\A(?:[a-f0-9]{32}|[a-f0-9]{64})\.(?:jpg|png|gif|webp)\z/', $filename) !== 1) {
            return null;
        }
        return $this->directory . DIRECTORY_SEPARATOR . $filename;
    }
}
