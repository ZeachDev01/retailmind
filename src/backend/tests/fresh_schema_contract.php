<?php

$schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Fresh schema contract failed: schema.sql could not be read.\n");
    exit(1);
}

$normalizedSchema = preg_replace('/\s+/', ' ', str_replace('`', '', $schema));
$failures = [];
$assertMatches = static function (string $pattern, string $message) use ($normalizedSchema, &$failures): void {
    if ($normalizedSchema === null || preg_match($pattern, $normalizedSchema) !== 1) {
        $failures[] = $message;
    }
};

$assertMatches('/\bis_recovery_account\s+(?:BOOLEAN|TINYINT\(1\))\s+NOT NULL\s+DEFAULT\s+(?:FALSE|\'?0\'?)/i', 'users.is_recovery_account is missing');
$assertMatches('/CREATE TABLE(?: IF NOT EXISTS)? recovery_accounts\s*\(/i', 'recovery_accounts table is missing');
$assertMatches('/CREATE TABLE(?: IF NOT EXISTS)? attention_settings\s*\(/i', 'attention_settings table is missing');
$assertMatches('/CREATE TABLE(?: IF NOT EXISTS)? attention_states\s*\(/i', 'attention_states table is missing');
$assertMatches('/attention_key\s+VARCHAR\(120\)(?:\s+DEFAULT)?\s+NULL/i', 'notifications.attention_key is missing');
$assertMatches('/attention_severity\s+VARCHAR\(20\)(?:\s+DEFAULT)?\s+NULL/i', 'notifications.attention_severity is missing');
$assertMatches('/attention_count\s+INT(?:\(11\))?(?:\s+DEFAULT)?\s+NULL/i', 'notifications.attention_count is missing');
$assertMatches('/attention_destination\s+VARCHAR\(255\)(?:\s+DEFAULT)?\s+NULL/i', 'notifications.attention_destination is missing');
$assertMatches('/(?:INDEX|KEY)\s+idx_notifications_attention\s*\(user_id,\s*attention_key,\s*created_at\)/i', 'notifications attention index is missing');

if (preg_match_all('/CONSTRAINT\s+`?([^`\s]+)`?\s+CHECK\s*\(/i', $schema, $checkConstraintMatches)) {
    $constraintNames = array_map('strtolower', $checkConstraintMatches[1]);
    $duplicates = array_keys(array_filter(array_count_values($constraintNames), static fn(int $count): bool => $count > 1));
    if ($duplicates) {
        $failures[] = 'CHECK constraint names must be unique for MySQL 8: ' . implode(', ', $duplicates);
    }
}

if ($failures) {
    fwrite(STDERR, "Fresh schema contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Fresh schema contract: passed\n";
