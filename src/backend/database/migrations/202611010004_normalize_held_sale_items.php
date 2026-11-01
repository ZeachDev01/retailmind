<?php

use App\Database\Schema;

return [
    'key' => '202611010004_normalize_held_sale_items',
    'description' => 'Store held sale cart lines as atomic rows',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS held_sale_items (
            held_sale_item_id INT AUTO_INCREMENT PRIMARY KEY,
            held_sale_id INT NOT NULL,
            cart_key VARCHAR(100) NOT NULL,
            line_position INT NOT NULL,
            product_id INT NOT NULL,
            selling_form ENUM('piece','pack') NOT NULL,
            pack_size INT NULL,
            product_name TEXT NOT NULL,
            quantity INT NOT NULL,
            price DECIMAL(12,2) NOT NULL,
            server_price DECIMAL(12,2) NOT NULL,
            stock INT NULL,
            reorder_level INT NOT NULL DEFAULT 0,
            safety_stock INT NOT NULL DEFAULT 0,
            sku VARCHAR(255) NULL,
            barcode VARCHAR(255) NULL,
            product_image VARCHAR(255) NULL,
            UNIQUE KEY uq_held_sale_items_key (held_sale_id, cart_key),
            INDEX idx_held_sale_items_order (held_sale_id, line_position),
            CONSTRAINT fk_held_sale_items_sale FOREIGN KEY (held_sale_id) REFERENCES held_sales (held_sale_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if (Schema::columnExists($pdo, 'held_sales', 'cart_json')) {
            $rows = $pdo->query('SELECT held_sale_id, cart_json FROM held_sales ORDER BY held_sale_id')->fetchAll(PDO::FETCH_ASSOC);
            $insert = $pdo->prepare(
                'INSERT INTO held_sale_items
                    (held_sale_id, cart_key, line_position, product_id, selling_form, pack_size, product_name, quantity, price, server_price, stock, reorder_level, safety_stock, sku, barcode, product_image)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE line_position = VALUES(line_position), product_id = VALUES(product_id), selling_form = VALUES(selling_form), pack_size = VALUES(pack_size), product_name = VALUES(product_name), quantity = VALUES(quantity), price = VALUES(price), server_price = VALUES(server_price), stock = VALUES(stock), reorder_level = VALUES(reorder_level), safety_stock = VALUES(safety_stock), sku = VALUES(sku), barcode = VALUES(barcode), product_image = VALUES(product_image)'
            );
            $allowed = ['product_id', 'selling_form', 'pack_size', 'name', 'qty', 'price', 'server_price', 'stock', 'reorder_level', 'safety_stock', 'sku', 'barcode', 'product_image'];

            foreach ($rows as $row) {
                try {
                    $cart = json_decode((string)$row['cart_json'], true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new \RuntimeException('Cannot normalize held sale ' . $row['held_sale_id'] . ': its saved cart is invalid JSON.', 0, $exception);
                }
                if (!is_array($cart)) {
                    throw new \RuntimeException('Cannot normalize held sale ' . $row['held_sale_id'] . ': its saved cart is not a line list.');
                }

                $position = 0;
                foreach ($cart as $key => $line) {
                    if ((!is_string($key) && !is_int($key)) || !is_array($line) || array_diff(array_keys($line), $allowed) !== []) {
                        throw new \RuntimeException('Cannot normalize held sale ' . $row['held_sale_id'] . ': it contains unsupported cart fields.');
                    }
                    if (strlen((string)$key) > 100 || !isset($line['product_id'], $line['selling_form'], $line['name'], $line['qty'], $line['price'])) {
                        throw new \RuntimeException('Cannot normalize held sale ' . $row['held_sale_id'] . ': a cart line is missing required values.');
                    }
                    $insert->execute([
                        (int)$row['held_sale_id'], (string)$key, $position++, (int)$line['product_id'],
                        (string)$line['selling_form'], $line['pack_size'] ?? null, (string)$line['name'],
                        (int)$line['qty'], (float)$line['price'], (float)($line['server_price'] ?? $line['price']),
                        isset($line['stock']) && is_numeric($line['stock']) ? (int)$line['stock'] : null,
                        (int)($line['reorder_level'] ?? 0), (int)($line['safety_stock'] ?? 0),
                        $line['sku'] ?? null, $line['barcode'] ?? null, $line['product_image'] ?? null,
                    ]);
                }
            }

            $pdo->exec('ALTER TABLE held_sales DROP COLUMN cart_json');
        }
    },
];
