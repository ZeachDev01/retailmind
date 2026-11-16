<?php
declare(strict_types=1);

function createReplenishmentBatchSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE product_batches (
        product_id INTEGER NOT NULL,
        remaining_quantity INTEGER NOT NULL DEFAULT 0,
        expiration_date DATE,
        sealed_quantity INTEGER NOT NULL DEFAULT 0,
        loose_quantity INTEGER NOT NULL DEFAULT 0
    )');
}
