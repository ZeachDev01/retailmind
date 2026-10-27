<?php

$migration = require __DIR__ . '/../database/migrations/202610270001_starter_product_categories.php';
$names = ['Food', 'Snacks', 'Drinks', 'Bread', 'Personal', 'Household', 'School'];

foreach ([false, true] as $hasExistingCategory) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE categories (category_id INTEGER PRIMARY KEY, category_name TEXT NOT NULL)');
    if ($hasExistingCategory) {
        $pdo->exec("INSERT INTO categories (category_name) VALUES ('Beverages')");
    }

    $migration['up']($pdo);
    $migration['up']($pdo);
    $actual = $pdo->query('SELECT category_name FROM categories ORDER BY category_id')->fetchAll(PDO::FETCH_COLUMN);
    $expected = $hasExistingCategory ? ['Beverages'] : $names;
    if ($actual !== $expected) {
        throw new RuntimeException('Starter categories changed an existing catalog or were not created once.');
    }
}

echo "Starter Product category checks passed.\n";
