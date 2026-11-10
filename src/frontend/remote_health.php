<?php

declare(strict_types=1);

// Public connectivity proof only: no session, database, credentials, or Store data.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
$marker = trim((string)@file_get_contents(__DIR__ . '/../backend/storage/tunnel/health.id'));
if (!preg_match('/^[a-f0-9]{32}$/D', $marker)) {
    http_response_code(503);
    exit;
}
echo 'RetailMind:' . $marker;
