<?php
// Shared Database Backup / Database Restore module (#69).
//
// The encrypted database/upload capture and delivery workflow lives in
// App\Backup\DatabaseBackupService so the Administrator and the Super
// Administrator share one workflow. The helpers below remain the shared
// internals: the SQL statement splitter and backup history writer. All restore
// validation and execution goes through DatabaseRestoreService/SqlBackupFormat.

/** Review drafts contain ciphertext and account choices, never passwords or keys. */
function backup_review_draft(): ?array {
    $draft = $_SESSION['backup_review'] ?? null;
    if (!is_array($draft) || ($draft['user_id'] ?? null) !== ($_SESSION['user_id'] ?? null)
        || ($draft['role'] ?? null) !== ($_SESSION['role'] ?? null) || ($draft['expires_at'] ?? 0) < time()) {
        unset($_SESSION['backup_review']);
        return null;
    }
    return $draft;
}

/** Resolve a new upload or this session's exact reviewed encrypted package. */
function backup_review_source(): array {
    $file = $_FILES['backup_file'] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        return $file + ['temporary' => false];
    }
    $draft = backup_review_draft();
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE || !$draft
        || !is_string($_POST['review_token'] ?? null) || !hash_equals($draft['token'], $_POST['review_token'])
        || !is_string($_POST['package_hash'] ?? null) || !hash_equals($draft['package_hash'], $_POST['package_hash'])) {
        throw new DomainException('Select the encrypted Store package or resume its current review.');
    }
    $path = tempnam(\App\Backup\RecoveryStore::directory(), 'review-');
    if ($path === false) throw new RuntimeException('The reviewed package could not be prepared.');
    try {
        if (!chmod($path, 0600) || file_put_contents($path, $draft['encrypted_package']) !== strlen($draft['encrypted_package'])
            || !hash_equals($draft['package_hash'], hash_file('sha256', $path))) {
            throw new RuntimeException('The reviewed package could not be verified.');
        }
    } catch (Throwable $exception) {
        unlink($path);
        throw $exception;
    }
    return ['tmp_name' => $path, 'name' => $draft['filename'], 'error' => UPLOAD_ERR_OK, 'temporary' => true];
}

/**
 * Repair a partially upgraded installation through the normal migration path.
 * Runs at most once per request and only when the history metadata is missing.
 */
function ensure_backup_storage(PDO $pdo): void {
    static $repaired = [];
    $connection = spl_object_id($pdo);
    if (isset($repaired[$connection])) return;
    $missing = !App\Database\Schema::tableExists($pdo, 'backup_history')
        || !App\Database\Schema::columnExists($pdo, 'backup_history', 'snapshot_at')
        || !App\Database\Schema::columnExists($pdo, 'backup_history', 'key_fingerprint');
    if ($missing) {
        (new App\Database\MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations'))->runPending();
    }
    $repaired[$connection] = true;
}

function backup_safe_identifier(string $identifier): string {
    return \App\Backup\DatabaseSnapshotWriter::identifier($identifier);
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

function record_backup_history(PDO $pdo, string $filename, string $type, int $size, string $status, ?int $userId, ?string $notes = null): void {
    try {
        ensure_backup_storage($pdo);
        $stmt = $pdo->prepare('INSERT INTO backup_history (filename, backup_type, file_size, status, performed_by, notes, snapshot_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$filename, $type, $size, $status, $userId, $notes, gmdate('Y-m-d H:i:s')]);
    } catch (Throwable $exception) {
        // Never mask the original backup failure with a history write error.
        error_log('Backup history write failed: ' . $exception->getMessage());
    }
}
