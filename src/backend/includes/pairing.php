<?php
// Short-lived barcode scanner pairing with per-device access tokens.

function pairing_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS barcode_pairings (
        pairing_id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(12) NOT NULL UNIQUE,
        created_by INT NOT NULL,
        status ENUM('pending','connected','expired') NOT NULL DEFAULT 'pending',
        device_label VARCHAR(120) NULL,
        expires_at DATETIME NOT NULL,
        connected_at DATETIME NULL,
        last_seen_at DATETIME NULL,
        access_token_hash VARCHAR(255) NULL,
        joined_ip VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_barcode_pairings_code (code),
        INDEX idx_barcode_pairings_expires_at (expires_at),
        FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
    )");
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM barcode_pairings')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }
    if (!isset($columns['access_token_hash'])) {
        $pdo->exec('ALTER TABLE barcode_pairings ADD COLUMN access_token_hash VARCHAR(255) NULL');
    }
    if (!isset($columns['joined_ip'])) {
        $pdo->exec('ALTER TABLE barcode_pairings ADD COLUMN joined_ip VARCHAR(45) NULL');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS barcode_scans (
        scan_id INT AUTO_INCREMENT PRIMARY KEY,
        pairing_id INT NOT NULL,
        barcode VARCHAR(255) NOT NULL,
        payload TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_barcode_scans_pairing_scan (pairing_id, scan_id),
        FOREIGN KEY (pairing_id) REFERENCES barcode_pairings(pairing_id) ON DELETE CASCADE
    )");
    $ensured = true;
}

function pairing_create(PDO $pdo, int $userId, int $ttlMinutes = 10): array
{
    pairing_ensure_schema($pdo);
    pairing_expire_old($pdo);
    $ttlMinutes = max(1, min($ttlMinutes, 30));
    $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = pairing_generate_code();
        try {
            $stmt = $pdo->prepare('INSERT INTO barcode_pairings (code, created_by, expires_at) VALUES (?, ?, ?)');
            $stmt->execute([$code, $userId, $expiresAt]);
            return [
                'pairing_id' => (int)$pdo->lastInsertId(),
                'code' => $code,
                'ttl_minutes' => $ttlMinutes,
                'expires_at' => $expiresAt,
            ];
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }
        }
    }
    throw new RuntimeException('Could not generate a unique pairing code.');
}

function pairing_find_by_code(PDO $pdo, string $code): ?array
{
    pairing_ensure_schema($pdo);
    pairing_expire_old($pdo);
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM barcode_pairings WHERE code = ? AND expires_at >= NOW() AND status <> 'expired' LIMIT 1");
    $stmt->execute([$code]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pairing_mark_connected(PDO $pdo, int $pairingId, ?string $deviceLabel = null): string
{
    pairing_ensure_schema($pdo);
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        "UPDATE barcode_pairings
         SET status = 'connected', device_label = ?, connected_at = COALESCE(connected_at, NOW()),
             last_seen_at = NOW(), access_token_hash = ?, joined_ip = ?
         WHERE pairing_id = ?"
    );
    $stmt->execute([
        $deviceLabel !== null ? substr($deviceLabel, 0, 120) : null,
        hash('sha256', $token),
        get_client_ip_address(),
        $pairingId,
    ]);
    return $token;
}

function pairing_validate_access(PDO $pdo, string $code, string $token): ?array
{
    $pairing = pairing_find_by_code($pdo, $code);
    if (!$pairing || $pairing['status'] !== 'connected' || empty($pairing['access_token_hash']) || $token === '') {
        return null;
    }
    if (!hash_equals((string)$pairing['access_token_hash'], hash('sha256', $token))) {
        return null;
    }
    pairing_touch($pdo, (int)$pairing['pairing_id']);
    return $pairing;
}

function pairing_assert_owner(array $pairing, int $userId): bool
{
    return (int)$pairing['created_by'] === $userId;
}

function pairing_touch(PDO $pdo, int $pairingId): void
{
    $pdo->prepare('UPDATE barcode_pairings SET last_seen_at = NOW() WHERE pairing_id = ?')->execute([$pairingId]);
}

function pairing_log_scan(PDO $pdo, int $pairingId, string $barcode, array $payload = []): void
{
    $stmt = $pdo->prepare('INSERT INTO barcode_scans (pairing_id, barcode, payload) VALUES (?, ?, ?)');
    $stmt->execute([
        $pairingId,
        substr($barcode, 0, 255),
        $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
    ]);
}

function pairing_scans_since(PDO $pdo, int $pairingId, int $afterId): array
{
    $stmt = $pdo->prepare('SELECT scan_id, barcode, payload, created_at FROM barcode_scans WHERE pairing_id = ? AND scan_id > ? ORDER BY scan_id ASC LIMIT 50');
    $stmt->execute([$pairingId, max(0, $afterId)]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $decoded = $row['payload'] ? json_decode($row['payload'], true) : [];
        $row['payload'] = is_array($decoded) ? $decoded : [];
    }
    unset($row);
    return $rows;
}

function pairing_expire_old(PDO $pdo): void
{
    $pdo->exec("UPDATE barcode_pairings SET status = 'expired', access_token_hash = NULL WHERE expires_at < NOW() AND status <> 'expired'");
}

function pairing_generate_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < 6; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }
    return $code;
}
