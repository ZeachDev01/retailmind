<?php

return [
    'key' => '202611010001_workspace_guidance',
    'description' => 'Remember optional guidance per staff account and Workspace',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `workspace_guidance` (
  `user_id` INT NOT NULL,
  `workspace` VARCHAR(30) NOT NULL,
  `status` ENUM('skipped','deferred','completed') NOT NULL,
  PRIMARY KEY (`user_id`, `workspace`),
  CONSTRAINT `fk_workspace_guidance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    },
];
