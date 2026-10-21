<?php
// Bring small SQLite service fixtures up to the notification persistence contract.
function notification_test_schema(PDO $pdo): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(notifications)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['workspace' => "TEXT NOT NULL DEFAULT ''", 'inapp_enabled' => 'INTEGER NOT NULL DEFAULT 1',
        'email_pending' => 'INTEGER NOT NULL DEFAULT 0', 'attention_key' => 'TEXT', 'attention_severity' => 'TEXT',
        'attention_count' => 'INTEGER', 'attention_destination' => 'TEXT', 'is_read' => 'INTEGER DEFAULT 0'] as $name => $type) {
        if (!in_array($name, $columns, true)) $pdo->exec("ALTER TABLE notifications ADD COLUMN $name $type");
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_roles (user_id INTEGER, role_id INTEGER, is_primary INTEGER DEFAULT 0);
        CREATE TABLE IF NOT EXISTS notification_preferences (user_id INTEGER PRIMARY KEY);
        CREATE TABLE IF NOT EXISTS attention_states (user_id INTEGER, attention_key TEXT, fingerprint TEXT, is_active INTEGER,
            first_detected_at TEXT, last_detected_at TEXT, resolved_at TEXT, PRIMARY KEY (user_id, attention_key))');
    $stateColumns = array_column($pdo->query('PRAGMA table_info(attention_states)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('notification_level', $stateColumns, true)) $pdo->exec('ALTER TABLE attention_states ADD COLUMN notification_level INTEGER NOT NULL DEFAULT 0');
}
