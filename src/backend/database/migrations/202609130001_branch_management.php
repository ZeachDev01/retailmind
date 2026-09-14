<?php

use App\Database\Schema;

return [
    'key' => '202609130001_branch_management',
    'description' => 'Branches, branch-scoped users and privilege assignments',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS branches (
            branch_id INT AUTO_INCREMENT PRIMARY KEY,
            branch_name VARCHAR(100) NOT NULL UNIQUE,
            branch_code VARCHAR(30) NOT NULL UNIQUE,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_branches_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS privileges (
            privilege_id INT AUTO_INCREMENT PRIMARY KEY,
            privilege_key VARCHAR(80) NOT NULL UNIQUE,
            privilege_name VARCHAR(120) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS role_privileges (
            role_id INT NOT NULL,
            privilege_id INT NOT NULL,
            PRIMARY KEY (role_id, privilege_id),
            FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
            FOREIGN KEY (privilege_id) REFERENCES privileges(privilege_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_privileges (
            user_id INT NOT NULL,
            privilege_id INT NOT NULL,
            allowed BOOLEAN NOT NULL DEFAULT TRUE,
            PRIMARY KEY (user_id, privilege_id),
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (privilege_id) REFERENCES privileges(privilege_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        Schema::addColumnIfMissing($pdo, 'users', 'branch_id', 'INT NULL AFTER role_id');
        Schema::addColumnIfMissing($pdo, 'products', 'branch_id', 'INT NULL AFTER created_by');
        Schema::addIndexIfMissing($pdo, 'users', 'idx_users_branch_id', 'branch_id');
        Schema::addIndexIfMissing($pdo, 'products', 'idx_products_branch_id', 'branch_id');
        Schema::addForeignKeyIfMissing($pdo, 'users', 'fk_users_branch', 'branch_id', 'branches', 'branch_id', 'SET NULL');
        Schema::addForeignKeyIfMissing($pdo, 'products', 'fk_products_branch', 'branch_id', 'branches', 'branch_id', 'SET NULL');

        $pdo->exec("INSERT IGNORE INTO roles (role_name) VALUES ('seller')");
        $pdo->exec("INSERT IGNORE INTO privileges (privilege_key, privilege_name) VALUES
            ('manage_users', 'Manage user accounts'),
            ('manage_roles', 'Assign user roles'),
            ('manage_privileges', 'Manage user privileges'),
            ('manage_branches', 'Create and manage branches'),
            ('manage_inventory', 'Manage branch inventory'),
            ('view_inventory', 'View branch inventory')");

        $pdo->exec("INSERT IGNORE INTO role_privileges (role_id, privilege_id)
            SELECT r.role_id, p.privilege_id FROM roles r CROSS JOIN privileges p
            WHERE r.role_name IN ('super_admin', 'admin')");
        $pdo->exec("INSERT IGNORE INTO role_privileges (role_id, privilege_id)
            SELECT r.role_id, p.privilege_id FROM roles r CROSS JOIN privileges p
            WHERE r.role_name = 'inventory_manager' AND p.privilege_key IN ('manage_inventory', 'view_inventory')");
        $pdo->exec("INSERT IGNORE INTO role_privileges (role_id, privilege_id)
            SELECT r.role_id, p.privilege_id FROM roles r CROSS JOIN privileges p
            WHERE r.role_name IN ('seller', 'cashier') AND p.privilege_key = 'view_inventory'");
    },
];