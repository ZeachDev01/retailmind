<?php

use App\Database\Schema;

return [
    'key' => '202609290006_append_only_cash_refunds',
    'description' => 'Record append-only Cash Refunds against completed sales (ticket #92)',
    'up' => static function (PDO $pdo): void {
        // Ticket #92. A refund is a new record, never an edit of the sale it
        // reverses. The completed sale stays exactly as it was written: nothing
        // here updates, deletes, or voids a sale, and the sales ledger remains
        // the authoritative account of what was sold.
        //
        // This must match the `cash_refunds` and `cash_refund_items` tables in
        // database/sql/schema.sql so a fresh install and an upgraded install agree.

        if (!Schema::tableExists($pdo, 'cash_refunds')) {
            $pdo->exec("CREATE TABLE cash_refunds (
                refund_id INT NOT NULL AUTO_INCREMENT,
                sale_id INT NOT NULL,
                shift_id INT NOT NULL,
                cashier_id INT NOT NULL,
                refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                payment_method ENUM('cash','card','ewallet') NOT NULL DEFAULT 'cash',
                reason VARCHAR(50) NOT NULL,
                note VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (refund_id),
                KEY idx_cash_refunds_sale (sale_id),
                KEY idx_cash_refunds_shift (shift_id),
                KEY idx_cash_refunds_cashier (cashier_id),
                CONSTRAINT fk_cash_refunds_sale
                    FOREIGN KEY (sale_id) REFERENCES sales(sale_id) ON DELETE RESTRICT,
                CONSTRAINT fk_cash_refunds_shift
                    FOREIGN KEY (shift_id) REFERENCES cashier_shifts(shift_id) ON DELETE RESTRICT,
                CONSTRAINT fk_cash_refunds_cashier
                    FOREIGN KEY (cashier_id) REFERENCES users(user_id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!Schema::tableExists($pdo, 'cash_refund_items')) {
            $pdo->exec("CREATE TABLE cash_refund_items (
                refund_item_id INT NOT NULL AUTO_INCREMENT,
                refund_id INT NOT NULL,
                sale_item_id INT NOT NULL,
                product_id INT NOT NULL,
                quantity INT NOT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                disposition ENUM('restockable','damaged') NOT NULL DEFAULT 'restockable',
                PRIMARY KEY (refund_item_id),
                UNIQUE KEY uq_cash_refund_items_refund_line (refund_id, sale_item_id),
                KEY idx_cash_refund_items_sale_item (sale_item_id),
                KEY idx_cash_refund_items_product (product_id),
                CONSTRAINT fk_cash_refund_items_refund
                    FOREIGN KEY (refund_id) REFERENCES cash_refunds(refund_id) ON DELETE RESTRICT,
                CONSTRAINT fk_cash_refund_items_sale_item
                    FOREIGN KEY (sale_item_id) REFERENCES sale_items(sale_item_id) ON DELETE RESTRICT,
                CONSTRAINT fk_cash_refund_items_product
                    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // The three questions the reconciliation asks are "what is still
        // refundable on this sale", "how much cash did this drawer hand back",
        // and "what did this Cashier refund", so each gets the index its own
        // read leads with. They are added unconditionally rather than only on
        // creation so an install that predates an index fix converges too.
        Schema::addIndexIfMissing($pdo, 'cash_refunds', 'idx_cash_refunds_sale', '`sale_id`');
        Schema::addIndexIfMissing($pdo, 'cash_refunds', 'idx_cash_refunds_shift', '`shift_id`');
        Schema::addIndexIfMissing($pdo, 'cash_refunds', 'idx_cash_refunds_cashier', '`cashier_id`');
        Schema::addIndexIfMissing($pdo, 'cash_refund_items', 'idx_cash_refund_items_sale_item', '`sale_item_id`');
        Schema::addIndexIfMissing($pdo, 'cash_refund_items', 'idx_cash_refund_items_product', '`product_id`');

        // A sold line can appear in one refund at most once, so a hand-crafted
        // request cannot count the same line's quantity twice inside a single
        // refund. MySQL has no filtered unique index for the cross-refund cap;
        // that one is settled by locking the sale row (CashRefundService).
        Schema::addUniqueKeyIfMissing(
            $pdo,
            'cash_refund_items',
            'uq_cash_refund_items_refund_line',
            '`refund_id`, `sale_item_id`'
        );

        // RESTRICT throughout: a sale, a sold line, a Cashier Shift, and a Cashier
        // are all retained history, and a refund that names one of them is the
        // proof that it happened. A Disabled Cashier keeps its historical
        // attribution, so the account is never removed either.
        Schema::addForeignKeyIfMissing($pdo, 'cash_refunds', 'fk_cash_refunds_sale', 'sale_id', 'sales', 'sale_id', 'RESTRICT');
        Schema::addForeignKeyIfMissing($pdo, 'cash_refunds', 'fk_cash_refunds_shift', 'shift_id', 'cashier_shifts', 'shift_id', 'RESTRICT');
        Schema::addForeignKeyIfMissing($pdo, 'cash_refunds', 'fk_cash_refunds_cashier', 'cashier_id', 'users', 'user_id', 'RESTRICT');
        Schema::addForeignKeyIfMissing($pdo, 'cash_refund_items', 'fk_cash_refund_items_refund', 'refund_id', 'cash_refunds', 'refund_id', 'RESTRICT');
        Schema::addForeignKeyIfMissing($pdo, 'cash_refund_items', 'fk_cash_refund_items_sale_item', 'sale_item_id', 'sale_items', 'sale_item_id', 'RESTRICT');
        Schema::addForeignKeyIfMissing($pdo, 'cash_refund_items', 'fk_cash_refund_items_product', 'product_id', 'products', 'product_id', 'RESTRICT');
    },
];
