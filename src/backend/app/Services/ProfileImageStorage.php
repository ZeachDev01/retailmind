<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ProfileImageStorage
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_FILE_SIZE = self::MAX_BYTES;
    public const MAX_DIMENSION = 8000;
    public const MAX_PIXELS = 40000000;

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private $isUploadedFile;
    private $moveUploadedFile;

    public function __construct(
        private string $directory,
        ?callable $isUploadedFile = null,
        ?callable $moveUploadedFile = null
    ) {
        $this->directory = rtrim($directory, '/\\');
        $this->isUploadedFile = $isUploadedFile ?? static fn(string $path): bool => is_uploaded_file($path);
        $this->moveUploadedFile = $moveUploadedFile ?? static fn(string $source, string $destination): bool => move_uploaded_file($source, $destination);
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
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('The profile picture could not be uploaded. Please try another image.');
        }

        $temporaryPath = (string)($file['tmp_name'] ?? '');
        $reportedSize = (int)($file['size'] ?? 0);
        if ($temporaryPath === '' || $reportedSize <= 0 || !($this->isUploadedFile)($temporaryPath)) {
            throw new InvalidArgumentException('Select a valid JPEG, PNG, GIF, or WebP image.');
        }
        if ($reportedSize > self::MAX_BYTES) {
            throw new InvalidArgumentException('Profile pictures must not exceed 2MB.');
        }

        $actualSize = filesize($temporaryPath);
        if ($actualSize === false || $actualSize <= 0 || $actualSize > self::MAX_BYTES) {
            throw new InvalidArgumentException('Profile pictures must not exceed 2MB.');
        }

        $mime = $this->detectSupportedMime($temporaryPath);
        $imageInfo = @getimagesize($temporaryPath);
        if ($imageInfo === false || !$this->imageTypeMatchesMime((int)$imageInfo[2], $mime)) {
            throw new InvalidArgumentException('Select a valid JPEG, PNG, GIF, or WebP image.');
        }

        $width = (int)($imageInfo[0] ?? 0);
        $height = (int)($imageInfo[1] ?? 0);
        if ($width <= 0 || $height <= 0 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || ($width * $height) > self::MAX_PIXELS) {
            throw new InvalidArgumentException('The profile picture dimensions are too large.');
        }

        $this->ensureDirectory();
        $extension = self::MIME_EXTENSIONS[$mime];
        do {
            $filename = bin2hex(random_bytes(32)) . '.' . $extension;
            $destination = $this->directory . DIRECTORY_SEPARATOR . $filename;
        } while (is_file($destination));

        if (!($this->moveUploadedFile)($temporaryPath, $destination)) {
            throw new RuntimeException('The profile picture could not be saved.');
        }
        @chmod($destination, 0640);

        return $filename;
    }

    public function replace(array $file, ?string $currentFilename, callable $persist): string
    {
        $filename = $this->store($file);
        if ($filename === null) {
            throw new InvalidArgumentException('Select a valid image to upload.');
        }

        try {
            $persist($filename);
        } catch (Throwable $exception) {
            $this->delete($filename);
            throw $exception;
        }

        if (!$this->delete($currentFilename)) {
            error_log('Unable to delete a superseded profile image from managed storage.');
        }
        return $filename;
    }

    public function remove(?string $currentFilename, callable $persist): void
    {
        $persist();
        if (!$this->delete($currentFilename)) {
            error_log('Unable to delete a removed profile image from managed storage.');
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
        return unlink($path);
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

    private function detectSupportedMime(string $path): string
    {
        $mime = (string)finfo_file(new \finfo(FILEINFO_MIME_TYPE), $path);
        if (!isset(self::MIME_EXTENSIONS[$mime])) {
            throw new InvalidArgumentException('Only JPEG, PNG, GIF, and WebP profile pictures are supported.');
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