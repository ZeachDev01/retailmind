<?php

namespace App\Receipts;

require_once __DIR__ . '/ReceiptEngine.php';

use PDO;

/**
 * Backwards-compatible facade over the unified ReceiptEngine.
 */
final class ReceiptDetailsService
{
    private ReceiptEngine $engine;

    public function __construct(private readonly PDO $pdo)
    {
        $this->engine = new ReceiptEngine($this->pdo);
    }

    public function fetchSale(int $saleId, ?int $storeId = null): ?array
    {
        return $this->engine->fetchSale($saleId, $storeId);
    }

    public function preserveSale(int $saleId): void
    {
        $this->engine->preserveSale($saleId);
    }

    public function storeInfo(): array
    {
        return $this->engine->storeInfo();
    }

    public function fetchItems(int $saleId): array
    {
        return $this->engine->fetchItems($saleId);
    }

    public static function verificationCode(array $sale): string
    {
        return ReceiptEngine::verificationCode($sale);
    }

    public static function renderMetadata(array $sale): string
    {
        return ReceiptEngine::renderMetadata($sale);
    }
}
