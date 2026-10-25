<?php
/** Durable result is written in the same transaction as the inventory change. */
final class InventorySubmission
{
    public function __construct(private PDO $pdo, private int $userId, private string $kind, private array $payload)
    {
    }

    private function token(): string
    {
        $token = $this->payload['operation_token'] ?? '';
        if (!is_string($token) || ($token !== '' && !preg_match('/^[a-f0-9]{48}$/D', $token))) {
            throw new RuntimeException('This inventory entry cannot be identified. Reload and resume your draft.');
        }
        return $token;
    }

    private function hash(): string
    {
        $payload = $this->payload;
        unset($payload['csrf_token'], $payload['operation_token'], $payload['receiving_nonce'], $payload['inventory_draft_id']);
        if (array_key_exists('product_image_fingerprint', $payload)) unset($payload['product_image']);
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function completed(): mixed
    {
        $token = $this->token();
        if ($token === '') return null;
        $stmt = $this->pdo->prepare('SELECT payload_hash, result_json FROM inventory_submissions WHERE user_id = ? AND operation_kind = ? AND operation_token = ?');
        $stmt->execute([$this->userId, $this->kind, $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!hash_equals($row['payload_hash'], $this->hash())) {
            throw new RuntimeException('This entry was already saved with different values. Check the saved record before starting another entry.');
        }
        return json_decode($row['result_json'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function remember(mixed $result): void
    {
        $token = $this->token();
        if ($token === '') return;
        if (!$this->pdo->inTransaction()) throw new LogicException('Submission results must share the inventory transaction.');
        $stmt = $this->pdo->prepare('INSERT INTO inventory_submissions (user_id, operation_kind, operation_token, payload_hash, result_json) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$this->userId, $this->kind, $token, $this->hash(), json_encode($result, JSON_THROW_ON_ERROR)]);
    }
}
