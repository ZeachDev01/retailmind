"""Imported Store observations feed the current worker feature contract."""
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

import pandas as pd

backend = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(backend / "scripts"))
from feature_pipeline import build_feature_matrix, build_training_rows

php = r"""
require 'bootstrap/app.php';
set_exception_handler(function (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); });
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("
    CREATE TABLE forecast_sales_imports (product_id INT, sale_date TEXT, quantity INT);
    CREATE TABLE sales (sale_id INT, sale_date TEXT);
    CREATE TABLE sale_items (sale_item_id INT, sale_id INT, product_id INT, quantity INT);
    CREATE TABLE stock_movements (product_id INT, change_qty INT, moved_at TEXT);
    CREATE TABLE store_calendar (calendar_id INTEGER PRIMARY KEY, calendar_date TEXT, status TEXT, recorded_at TEXT);
    CREATE TABLE cash_refunds (refund_id INT, created_at TEXT);
    CREATE TABLE cash_refund_items (refund_id INT, sale_item_id INT, product_id INT, quantity INT);
    CREATE TABLE sale_reversals (reversal_id INT, status TEXT, approved_at TEXT);
    CREATE TABLE sale_reversal_items (reversal_id INT, sale_item_id INT, product_id INT, quantity INT);
");
$insert = $pdo->prepare('INSERT INTO forecast_sales_imports VALUES (1, ?, ?)');
$day = new DateTimeImmutable('2024-01-01');
for ($i = 0; $i < 120; $i++) {
    $insert->execute([$day->modify("+$i days")->format('Y-m-d'), $i === 1 ? 0 : 10]);
}
$pdo->exec("INSERT INTO sales VALUES (1, '2024-01-03 10:00:00');
    INSERT INTO sale_items VALUES (1, 1, 1, 4);
    INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2024-01-03', 'open', '2024-01-01 00:00:00');
    UPDATE forecast_sales_imports SET quantity = 999 WHERE sale_date = '2024-01-03';");
echo json_encode((new App\Forecasting\DemandHistoryService())->getObservedHistory($pdo, '2024-04-29'), JSON_THROW_ON_ERROR);
"""
with tempfile.TemporaryDirectory(prefix="retailmind-history-") as recovery:
    result = subprocess.run([os.environ.get("PHP_BINARY", "php")], input="<?php\n" + php, cwd=backend,
                            env={**os.environ, "BACKUP_STORAGE_PATH": recovery},
                            capture_output=True, text=True)
    assert result.returncode == 0, result.stderr
    history = pd.DataFrame(json.loads(result.stdout))

assert len(history) == 120
assert list(history.units_sold[:3]) == [10, 0, 4], "Explicit zero preserved; POS wins"
features = build_feature_matrix(history, "2024-01-28")
assert len(features) == 28 and features.lag_28.eq(10).all()
rows = build_training_rows(history, "2024-04-29")
assert len(rows) > 0 and rows.units_sold.notna().all(), "Imports yield real training rows"
gapped = history.copy()
gapped.loc[gapped.date == "2024-01-05", "units_sold"] = None
try:
    build_feature_matrix(gapped, "2024-01-28")
except ValueError as error:
    assert "28 consecutive observed days" in str(error)
else:
    raise AssertionError("Unknown imported days must not become zero-valued features")
print("Forecast history source checks passed (current worker, training rows, zero, POS precedence, unknown gaps)")
