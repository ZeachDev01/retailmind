<?php
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }
// TestSprite's opaque tunnel strips remote metadata; model a LAN peer explicitly.
$remoteState = getenv('RM_BACKUP_REMOTE_STATE');
if ($remoteState && is_file($remoteState)) {
    $_SERVER['REMOTE_ADDR'] = '192.168.1.20';
}
return require __DIR__ . '/theme_router.php';
