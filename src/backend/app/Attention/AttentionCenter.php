<?php

namespace App\Attention;

use PDO;

final class AttentionCenter
{
    private DatabaseAttentionSignalSource $signals;
    private AttentionRuleService $rules;
    private AttentionNotificationService $notifications;
    private AttentionSettingsService $settings;
    private Clock $clock;
    private PDO $pdo;

    public function __construct(
        PDO $pdo,
        ?Clock $clock = null,
        ?AttentionSettingsService $settings = null
    ) {
        $this->pdo = $pdo;
        $this->clock = $clock ?? new SystemClock();
        $this->settings = $settings ?? new AttentionSettingsService($this->pdo, $this->clock);
        $this->signals = new DatabaseAttentionSignalSource($this->pdo, $this->clock);
        $this->rules = new AttentionRuleService($this->settings, $this->clock);
        $this->notifications = new AttentionNotificationService($this->pdo, $this->clock);
    }

    /**
     * Read-only active items for dashboards.
     * Evaluates live conditions without mutating in-app notifications.
     *
     * @return AttentionItem[]
     */
    public function getAttention(int $userId = 0, string $role = '', array $healthChecks = []): array
    {
        if ($role !== 'admin' && $role !== 'super_admin') {
            return [];
        }

        $signals = $this->collectSignals($role, $healthChecks);
        return $this->rules->evaluate($role, $signals);
    }

    /**
     * Evaluates items and synchronizes in-app notifications.
     */
    public function synchronize(
        int $userId,
        string $role,
        array $healthChecks = [],
        bool $createInApp = true
    ): AttentionRefreshResult {
        if ($role !== 'admin' && $role !== 'super_admin') {
            return new AttentionRefreshResult([], []);
        }

        $signals = $this->collectSignals($role, $healthChecks);
        $evaluatedItems = $this->rules->evaluate($role, $signals);
        $workspacePrefix = $role === 'super_admin' ? 'platform.' : 'store.';
        return $this->synchronizeItems($userId, $evaluatedItems, $createInApp, $workspacePrefix);
    }

    /** @param AttentionItem[] $items Prepared conditions from other operational workflows. */
    public function synchronizeItems(int $userId, array $items, bool $createInApp = true, string $scope = ''): AttentionRefreshResult
    {
        return $this->notifications->synchronize($userId, $items, $createInApp, $scope);
    }

    private function collectSignals(string $role, array $healthChecks = []): array
    {
        if ($role === 'super_admin') {
            return $this->signals->platform($healthChecks);
        }

        if ($role === 'admin') {
            return $this->signals->store();
        }

        return [];
    }
}
