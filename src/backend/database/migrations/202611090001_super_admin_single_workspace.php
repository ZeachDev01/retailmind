<?php

return [
    'key' => '202611090001_super_admin_single_workspace',
    'description' => 'Keep the Super Administrator account on platform governance only',
    'up' => static function (PDO $pdo): void {
        $superRoleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name = 'super_admin'")->fetchColumn();
        if ($superRoleId < 1) {
            return;
        }
        // Platform governance is one Workspace; never combine it with store Workspaces.
        $userIds = $pdo->query("SELECT user_id FROM user_roles WHERE role_id = {$superRoleId}")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($userIds as $userId) {
            $pdo->prepare('DELETE FROM user_roles WHERE user_id = ? AND role_id <> ?')->execute([(int)$userId, $superRoleId]);
            $pdo->prepare('UPDATE user_roles SET is_primary = 1 WHERE user_id = ? AND role_id = ?')->execute([(int)$userId, $superRoleId]);
            $pdo->prepare('UPDATE users SET role_id = ? WHERE user_id = ?')->execute([$superRoleId, (int)$userId]);
        }
    },
];
