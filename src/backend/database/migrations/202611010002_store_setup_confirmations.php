<?php

return [
    'key' => '202611010002_store_setup_confirmations',
    'description' => 'Persist shared Store setup confirmations across Workspaces',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `store_setup_confirmations` (
  `task_key` VARCHAR(50) NOT NULL,
  `confirmed_by_user_id` INT NOT NULL,
  `confirmed_workspace` VARCHAR(30) NOT NULL,
  `confirmed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`task_key`),
  CONSTRAINT `fk_store_setup_confirmations_user` FOREIGN KEY (`confirmed_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    },
];
