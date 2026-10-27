<?php

return [
    'key' => '202610270001_starter_product_categories',
    'description' => 'Add common Product categories when the Store has none',
    'up' => static function (PDO $pdo): void {
        if ((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() !== 0) {
            return;
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO categories (category_name) VALUES (?)');
            foreach (['Food', 'Snacks', 'Drinks', 'Bread', 'Personal', 'Household', 'School'] as $name) {
                $insert->execute([$name]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    },
];
