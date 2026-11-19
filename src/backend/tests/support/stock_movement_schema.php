<?php
declare(strict_types=1);

function createStockMovementSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, sale_date TEXT);
        CREATE TABLE sale_items (sale_id INTEGER, product_id INTEGER, quantity INTEGER, selling_form TEXT, pack_size INTEGER);
        CREATE TABLE stock_movements (movement_id INTEGER PRIMARY KEY, product_id INTEGER, change_qty INTEGER, reason TEXT, moved_at TEXT)');
}
