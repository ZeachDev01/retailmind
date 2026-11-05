<?php

namespace App\Receipts;

use InvalidArgumentException;
use RuntimeException;

final class ReceiptLogo
{
    public static function fromUpload(mixed $file): string
    {
        $invalid = 'Choose a valid JPG or PNG logo, no larger than 2 MB.';
        if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException($invalid);
        }
        $path = $file['tmp_name'];
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 1 || $bytes > 2 * 1024 * 1024) {
            throw new InvalidArgumentException($invalid);
        }
        $info = @getimagesize($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || $mime !== image_type_to_mime_type($info[2])
            || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 4000000) {
            throw new InvalidArgumentException($invalid);
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Receipt logo processing is unavailable. Tell your Administrator.');
        }
        $image = @imagecreatefromstring(file_get_contents($path));
        if ($image === false) throw new InvalidArgumentException($invalid);
        $scale = min(1, 384 / $info[0], 192 / $info[1]);
        $width = max(1, (int)round($info[0] * $scale));
        $height = max(1, (int)round($info[1] * $scale));
        $scaled = imagecreatetruecolor($width, $height);
        imagefill($scaled, 0, 0, imagecolorallocate($scaled, 255, 255, 255));
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        $output = imagecreate($width, $height);
        imagecolorallocate($output, 255, 255, 255);
        $black = imagecolorallocate($output, 0, 0, 0);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($scaled, $x, $y);
                $brightness = 0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255);
                if ($brightness < 160) imagesetpixel($output, $x, $y, $black);
            }
        }
        ob_start();
        try {
            if (!imagepng($output, null, 9)) throw new RuntimeException('The receipt logo could not be processed. Try another image.');
            $png = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        // Keep the small image in the database so receipt snapshots and backups are self-contained.
        return 'data:image/png;base64,' . base64_encode($png);
    }

    public static function source(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 32768
            || preg_match('~\Adata:image/png;base64,([A-Za-z0-9+/]+={0,2})\z~', $value, $match) !== 1) return '';
        $png = base64_decode($match[1], true);
        $info = $png === false ? false : @getimagesizefromstring($png);
        return $info && $info[2] === IMAGETYPE_PNG && $info[0] <= 384 && $info[1] <= 192 ? $value : '';
    }
}
