<?php

return [
    'key' => '202610100001_forecast_history_import',
    'description' => 'Imported daily demand history for forecasting',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS forecast_sales_imports (
            product_id INT NOT NULL,
            sale_date DATE NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            imported_by INT NULL,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (product_id, sale_date),
            FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
            FOREIGN KEY (imported_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
    },
];
