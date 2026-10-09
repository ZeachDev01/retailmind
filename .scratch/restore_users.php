<?php
$pdo = new PDO('mysql:host=localhost;dbname=inventory_system;charset=utf8mb4', 'root', '');

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("DELETE FROM users");

$sql = 'INSERT INTO `users` (`user_id`, `full_name`, `username`, `email`, `profile_image`, `password_hash`, `role_id`, `status`, `failed_login_attempts`, `locked_until`, `last_login_at`, `session_version`, `password_changed_at`, `must_change_password`, `branch_id`, `is_recovery_account`, `created_at`) VALUES (1,\'Super Admin\',\'superadmin\',\'superadmin@inventory.local\',\'b8603d0f10c1be3fc3c819919e417a58429f910bcb825a291689da684886960d.png\',\'$2y$10$jyoib.jrVfRdSWBvxaKT9eBEqBXT7a9YIuxfSAmpzp9hemBttdO4y\',1,\'active\',0,NULL,\'2026-09-18 14:14:22\',2,\'2026-09-14 15:30:05\',0,NULL,0,\'2026-09-14 07:29:33\'),(2,\'MIKE SORDILLA\',\'Mike\',\'sordillamike1@gmail.com\',NULL,\'$2y$10$SeRPHt6JdMTWd7EnxPfCBe/Z8HapmxoUHOEOwI93YNne042BIPeiy\',2,\'active\',0,NULL,\'2026-09-17 21:33:41\',5,\'2026-09-17 21:37:42\',0,1,0,\'2026-09-14 22:27:46\'),(9,\'RetailMind Sales Trend Seed\',\'rm_seed_sales_trend\',\'rm-seed-sales-trend@example.test\',NULL,\'$2y$12$8epqsnlICawcEEQb5rgyt.m5e9/rLuvicEGJNJMH.XRAYWXFPB4..\',4,\'disabled\',0,NULL,NULL,1,NULL,1,1,0,\'2026-09-17 00:39:26\'),(11,\'RetailMind Sales Seed Receiver\',\'rm_seed_sales_receiver\',\'rm-seed-sales-receiver@example.test\',\'b1e1ee08e88abf5c8f0eda5f2e4352ac6f64c3db035d22e3ac8b6751dfa882f7.png\',\'$2y$12$x9KFvnz7f2g8.dy827WKI.fb0SRc2QxFW2jJUkbwlHuKz0IiV9PMq\',2,\'disabled\',0,NULL,NULL,1,NULL,1,1,0,\'2026-09-17 00:43:42\')';

$pdo->exec($sql);
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$stmt = $pdo->query('SELECT user_id, username, full_name, password_hash FROM users');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

