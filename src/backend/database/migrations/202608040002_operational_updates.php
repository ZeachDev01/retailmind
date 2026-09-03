<?php


return [
    'key' => '202608040002_operational_updates',
    'description' => 'Operational workflow tables and columns',
    'up' => static function (\PDO $pdo): void {
        require_once dirname(__DIR__, 2) . '/includes/operational_schema.php';
        ensure_operational_updates_schema($pdo);
    },
];
