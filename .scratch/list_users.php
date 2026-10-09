<?php
$pdo = new PDO('mysql:host=localhost;dbname=inventory_system;charset=utf8mb4', 'root', '');
$stmt = $pdo->query('SELECT user_id, username, email, full_name, role_id, status FROM users');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

