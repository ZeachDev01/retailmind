<?php

function ensure_backup_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS backup_history (
        backup_id BIGINT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        backup_type ENUM('manual','scheduled','restore') NOT NULL DEFAULT 'manual',
        file_size BIGINT NULL,
        status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
        performed_by INT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (performed_by) REFERENCES users(user_id) ON DELETE SET NULL
    )");
}

function backup_safe_identifier(string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function create_database_backup(PDO $pdo, string $destinationPath): array {
    ensure_backup_schema($pdo);
    $directory = dirname($destinationPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the backup directory.');
    }
    $handle = fopen($destinationPath, 'wb');
    if (!$handle) {
        throw new RuntimeException('Could not open the backup file for writing.');
    }

    $write = static function (string $value) use ($handle): void {
        if (fwrite($handle, $value) === false) {
            throw new RuntimeException('Could not write the backup file.');
        }
    };

    try {
        $write("-- RetailMind database backup\n");
        $write('-- Generated: ' . date('c') . "\n");
        $write("SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = [];
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row) {
            $tables[] = $row[0];
        }

        foreach ($tables as $table) {
            $quotedTable = backup_safe_identifier($table);
            $createRow = $pdo->query("SHOW CREATE TABLE {$quotedTable}")->fetch(PDO::FETCH_NUM);
            if (!$createRow) {
                continue;
            }
            $write("\n-- Table {$table}\nDROP TABLE IF EXISTS {$quotedTable};\n{$createRow[1]};\n");
            $stmt = $pdo->query("SELECT * FROM {$quotedTable}", PDO::FETCH_ASSOC);
            $columns = null;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($columns === null) {
                    $columns = array_keys($row);
                }
                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column];
                    $values[] = $value === null ? 'NULL' : $pdo->quote((string)$value);
                }
                $columnSql = implode(', ', array_map('backup_safe_identifier', $columns));
                $write("INSERT INTO {$quotedTable} ({$columnSql}) VALUES (" . implode(', ', $values) . ");\n");
            }
        }
        $write("\nSET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($handle);
    }

    return ['path' => $destinationPath, 'filename' => basename($destinationPath), 'size' => filesize($destinationPath) ?: 0];
}

function split_sql_statements(string $sql): array {
    $statements = [];
    $buffer = '';
    $quote = null;
    $escaped = false;
    $length = strlen($sql);
    $lineComment = false;
    $blockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($lineComment) {
            if ($char === "\n") {
                $lineComment = false;
                $buffer .= $char;
            }
            continue;
        }
        if ($blockComment) {
            if ($char === '*' && $next === '/') {
                $blockComment = false;
                $i++;
            }
            continue;
        }
        if ($quote === null && $char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
            $lineComment = true;
            $i++;
            continue;
        }
        if ($quote === null && $char === '#') {
            $lineComment = true;
            continue;
        }
        if ($quote === null && $char === '/' && $next === '*') {
            $blockComment = true;
            $i++;
            continue;
        }

        $buffer .= $char;
        if ($quote !== null) {
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === $quote) {
                if ($next === $quote) {
                    $buffer .= $next;
                    $i++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            continue;
        }
        if ($char === ';') {
            $statement = trim(substr($buffer, 0, -1));
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

function validate_backup_sql(string $sql): void {
    if (stripos($sql, '-- RetailMind database backup') === false) {
        throw new RuntimeException('Only backups generated by RetailMind can be restored.');
    }
    $forbidden = ['CREATE DATABASE', 'DROP DATABASE', 'GRANT ', 'REVOKE ', 'CREATE USER', 'ALTER USER', 'LOAD DATA', 'INTO OUTFILE', 'INTO DUMPFILE'];
    foreach ($forbidden as $phrase) {
        if (stripos($sql, $phrase) !== false) {
            throw new RuntimeException("The backup contains a forbidden statement: {$phrase}.");
        }
    }
}

function restore_database_backup(PDO $pdo, string $sourcePath): int {
    $sql = file_get_contents($sourcePath);
    if ($sql === false) {
        throw new RuntimeException('Could not read the uploaded backup.');
    }
    validate_backup_sql($sql);
    $statements = split_sql_statements($sql);
    if (!$statements) {
        throw new RuntimeException('The backup does not contain executable SQL.');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $executed = 0;
    try {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
            $executed++;
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    return $executed;
}

function record_backup_history(PDO $pdo, string $filename, string $type, int $size, string $status, ?int $userId, ?string $notes = null): void {
    ensure_backup_schema($pdo);
    $stmt = $pdo->prepare('INSERT INTO backup_history (filename, backup_type, file_size, status, performed_by, notes) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$filename, $type, $size, $status, $userId, $notes]);
}
