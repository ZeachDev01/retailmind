<?php
function current_role(): string { return 'admin'; }
$_SERVER['HTTP_X_RETAILMIND_NAVIGATION'] = $argv[1] ?? '';
require __DIR__ . '/../../includes/page_navigation.php';
echo stream_get_contents(STDIN);
