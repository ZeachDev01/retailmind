<?php
// app/Services/ProductCodeLookupService.php
//
// Shared, authenticated, read-only exact code lookup used by the barcode
// scan fields (ticket #78/#79). It resolves one active product in the single
// Store from an exact SKU, unit barcode, or case barcode, and reports which
// code kind matched so callers can pick the right quantity mode.

require_once __DIR__ . '/../Store/StoreScope.php';

class ProductCodeLookupService
{
    public const OUTCOME_MATCH = 'match';
    public const OUTCOME_UNKNOWN = 'unknown';
    public const OUTCOME_AMBIGUOUS = 'ambiguous';

    public const KIND_SKU = 'sku';
    public const KIND_UNIT_BARCODE = 'unit_barcode';
    public const KIND_CASE_BARCODE = 'case_barcode';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Resolves an exact product code.
     *
     * Never limits the result set, never filters on stock (zero-stock and
     * inventory-less products stay reachable), and never picks one product
     * when the code identifies more than one.
     *
     * @return array{
     *     outcome: string,
     *     code: string,
     *     matched_code_kind: ?string,
     *     product: ?array,
     *     candidates: array<int, array>
     * }
     */
    public function lookup(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return $this->result(self::OUTCOME_UNKNOWN, '', null, []);
        }

        [$scopeSql, $scopeParams] = $this->productScope();
        // The match flags reuse the same comparison as the WHERE clause so the
        // reported kind always agrees with whatever the engine matched on.
        $statement = $this->pdo->prepare(
            "SELECT p.product_id, p.sku, p.barcode, p.case_barcode, p.product_name,
                    COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand,
                    CASE WHEN p.sku = ? THEN 1 ELSE 0 END AS match_sku,
                    CASE WHEN p.barcode = ? THEN 1 ELSE 0 END AS match_unit_barcode,
                    CASE WHEN p.case_barcode = ? THEN 1 ELSE 0 END AS match_case_barcode
             FROM products p
             LEFT JOIN inventory i ON i.product_id = p.product_id
             WHERE p.status = 'active'{$scopeSql}
               AND (p.sku = ? OR p.barcode = ? OR p.case_barcode = ?)"
        );
        $statement->execute(array_merge(
            [$code, $code, $code],
            $scopeParams,
            [$code, $code, $code]
        ));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return $this->result(self::OUTCOME_UNKNOWN, $code, null, []);
        }

        $products = [];
        foreach ($rows as $row) {
            $productId = (int)$row['product_id'];
            $products[$productId] ??= [
                'product_id' => $productId,
                'sku' => (string)$row['sku'],
                'barcode' => (string)$row['barcode'],
                'case_barcode' => $row['case_barcode'] === null ? null : (string)$row['case_barcode'],
                'product_name' => (string)$row['product_name'],
                'quantity_on_hand' => (int)$row['quantity_on_hand'],
                'kinds' => [],
            ];
            $products[$productId]['kinds'] = array_values(array_unique(array_merge(
                $products[$productId]['kinds'],
                $this->matchedKinds($row)
            )));
        }

        if (count($products) > 1) {
            $candidates = array_map(
                static fn(array $product): array => [
                    'product_id' => $product['product_id'],
                    'sku' => $product['sku'],
                    'product_name' => $product['product_name'],
                ],
                array_values($products)
            );
            usort(
                $candidates,
                static fn(array $left, array $right): int => [$left['product_name'], $left['sku']]
                    <=> [$right['product_name'], $right['sku']]
            );

            return $this->result(self::OUTCOME_AMBIGUOUS, $code, null, $candidates);
        }

        $product = array_shift($products);
        $kinds = $product['kinds'];
        unset($product['kinds']);

        return $this->result(
            self::OUTCOME_MATCH,
            $code,
            $this->primaryKind($kinds),
            [],
            $product
        );
    }

    /** @return array<int, string> */
    private function matchedKinds(array $row): array
    {
        $kinds = [];
        if ((int)($row['match_sku'] ?? 0) === 1) {
            $kinds[] = self::KIND_SKU;
        }
        if ((int)($row['match_unit_barcode'] ?? 0) === 1) {
            $kinds[] = self::KIND_UNIT_BARCODE;
        }
        if ((int)($row['match_case_barcode'] ?? 0) === 1) {
            $kinds[] = self::KIND_CASE_BARCODE;
        }
        return $kinds;
    }

    /**
     * One code can legitimately equal more than one identifier on the same
     * product, so the reported kind is chosen deterministically.
     *
     * @param array<int, string> $kinds
     */
    private function primaryKind(array $kinds): ?string
    {
        foreach ([self::KIND_SKU, self::KIND_UNIT_BARCODE, self::KIND_CASE_BARCODE] as $kind) {
            if (in_array($kind, $kinds, true)) {
                return $kind;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function result(
        string $outcome,
        string $code,
        ?string $matchedKind,
        array $candidates,
        ?array $product = null
    ): array {
        return [
            'outcome' => $outcome,
            'code' => $code,
            'matched_code_kind' => $matchedKind,
            'product' => $product,
            'candidates' => $candidates,
        ];
    }

    private function productScope(): array
    {
        if (function_exists('store_product_scope')) {
            return store_product_scope('p');
        }

        return (new App\Store\StoreScope($this->pdo))->productScope('p');
    }
}
