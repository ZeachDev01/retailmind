<?php
// Exercise the production predicates with fixed day boundaries, without a Store database.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, sale_date TEXT)');
$pdo->exec('CREATE INDEX idx_sales_sale_date ON sales (sale_date)');
$insert = $pdo->prepare('INSERT INTO sales VALUES (?, ?)');
foreach ([
    '2026-09-30 23:59:59',
    '2026-10-01 00:00:00',
    '2026-10-01 12:00:00',
    '2026-10-01 23:59:59.999999',
    '2026-10-02 00:00:00',
    null,
] as $id => $date) {
    $insert->execute([$id, $date]);
}

$count = 0;
foreach (['DashboardService.php' => 3, 'SalesService.php' => 1] as $file => $expectedCount) {
    $source = file_get_contents(__DIR__ . '/../app/Services/' . $file);
    preg_match_all('/(?:s\.)?sale_date >= CURDATE\(\) AND (?:s\.)?sale_date < DATE_ADD\(CURDATE\(\), INTERVAL 1 DAY\)/', $source, $matches);
    if (count($matches[0]) !== $expectedCount || preg_match('/DATE\((?:s\.)?sale_date\) = CURDATE\(\)/', $source)) {
        throw new RuntimeException("Daily sales predicates must use indexable ranges in {$file}");
    }
    foreach ($matches[0] as $predicate) {
        // Adapt only MySQL's date expressions; execute the actual comparison operators.
        $predicate = str_replace(
            ['DATE_ADD(CURDATE(), INTERVAL 1 DAY)', 'CURDATE()'],
            ["'2026-10-02'", "'2026-10-01'"],
            $predicate
        );
        $sql = 'SELECT sale_id FROM sales s WHERE ' . $predicate;
        $ids = $pdo->query($sql . ' ORDER BY sale_id')->fetchAll(PDO::FETCH_COLUMN);
        if ($ids !== [1, 2, 3]) {
            throw new RuntimeException('Daily range must include today only, including fractional seconds');
        }
        $plan = $pdo->query('EXPLAIN QUERY PLAN ' . $sql)->fetchAll(PDO::FETCH_COLUMN, 3);
        if (!str_contains(implode(' ', $plan), 'SEARCH s USING COVERING INDEX idx_sales_sale_date (sale_date>? AND sale_date<?)')) {
            throw new RuntimeException('Daily range must support an indexed timestamp lookup');
        }
        $count++;
    }
}
echo "PASS: {$count} daily sales predicates preserve boundaries and support index range lookups.\n";
