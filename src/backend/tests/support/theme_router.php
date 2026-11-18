<?php
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }
$database = getenv('RM_THEME_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/', $database)) throw new RuntimeException('Disposable database required.');
// Real application routes; only a disposable database is permitted.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = realpath(__DIR__ . '/../../../frontend' . ($path === '/' ? '/index.php' : $path));
$root = realpath(__DIR__ . '/../../../frontend');
if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) { http_response_code(404); exit; }
if (!str_ends_with($file, '.php')) return false;
require_once __DIR__ . '/../../bootstrap/app.php';
$_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $database;
$_ENV['APP_ENV'] = 'development'; // Includes the existing development-only forecast view.
if (getenv('RM_TEST_PRODUCT_CATALOG') === '1') {
    $openFoodFactsRequest = require __DIR__ . '/open_food_facts_fixture.php';
    $imageFixtureState = getenv('RM_TEST_PRODUCT_PHOTO_STATE');
    $testStorage = getenv('BACKUP_STORAGE_PATH');
    if (!$testStorage) throw new RuntimeException('Disposable storage required for catalog fixtures.');
    $GLOBALS['app']['storage_path'] = $testStorage . '/app-storage';
    $openFoodFactsImageRequest = static function (string $url) use ($imageFixtureState): array {
        if (!preg_match('~^https://images\.openfoodfacts\.org/images/products/(?:001/234/567/8905|[0-9]{8})/front_en\.4\.400\.jpg$~D', $url)) {
            throw new RuntimeException('Unexpected Open Food Facts image request.');
        }
        $outcome = $imageFixtureState ? trim(file_get_contents($imageFixtureState)) : 'ok';
        return match ($outcome) {
            'missing' => [503, 'Unavailable'],
            'invalid' => [200, '<html>Not a photo</html>'],
            'large' => [200, str_repeat('x', 5 * 1024 * 1024 + 1)],
            default => [200, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')],
        };
    };
}
require $file;
