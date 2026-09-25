<?php

use App\Database\Schema;

return [
    'key' => '202609250001_user_multi_roles',
    'description' => 'Allow users to hold multiple role templates',
    'up' => static function (PDO $pdo): void {
        if (!Schema::tableExists($pdo, 'user_roles')) {
            $pdo->exec(
                "CREATE TABLE user_roles (
                    user_id INT NOT NULL,
                    role_id INT NOT NULL,
                    is_primary TINYINT(1) NOT NULL DEFAULT 0,
                    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, role_id),
                    KEY idx_user_roles_role (role_id),
                    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        $pdo->exec(
            "INSERT IGNORE INTO user_roles (user_id, role_id, is_primary)
             SELECT user_id, role_id, 1 FROM users WHERE role_id IS NOT NULL"
        );
    },
];
