<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';
$GLOBALS['app']['storage_path'] = getenv('RM_SEED_TEST_STORAGE');
$openFoodFactsImageRequest = static function (string $url): array {
    static $number = 0;
    if (getenv('RM_SEED_TEST_PHOTO') === 'invalid' && ++$number === 3) return [200, '<html>Not a photo</html>'];
    return [200, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')];
};
if (getenv('RM_SEED_TEST_LIVE') === '1' && getenv('RM_SEED_TEST_PHOTO') !== 'invalid') unset($openFoodFactsImageRequest);
require __DIR__ . '/../../scripts/seed_philippine_products.php';
