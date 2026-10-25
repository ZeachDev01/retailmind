<?php
require __DIR__ . '/theme_fixture.php';
if ($argv[1] !== 'create') return;
$pdo->exec("INSERT INTO fiscal_periods (period_name,start_date,end_date,status,created_by) VALUES ('Inventory recovery test','2026-01-01','2099-12-31','open',1)");
$pdo->exec("INSERT INTO users (username,password_hash,full_name,email,role_id,status,branch_id) SELECT 'theme_inventory_other', password_hash, 'Other Inventory Manager', 'other-inventory@example.test', role_id, status, branch_id FROM users WHERE user_id = 3");
