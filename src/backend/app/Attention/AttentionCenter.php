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
    public function getAttention(int $userId = 0, string $role = '', array $externalSignals = []): array
    {
        if ($role !== 'admin' && $role !== 'super_admin') {
            return [];
        }

        $signals = $this->collectSignals($role, $externalSignals);
        return $this->rules->evaluate($role, $signals);
    }

    /**
     * Evaluates items and synchronizes in-app notifications.
     */
    public function synchronize(
        int $userId,
        string $role,
        array $externalSignals = [],
        bool $createInApp = true
    ): AttentionRefreshResult {
        if ($role !== 'admin' && $role !== 'super_admin') {
            return new AttentionRefreshResult([], []);
        }

        $signals = $this->collectSignals($role, $externalSignals);
        $evaluatedItems = $this->rules->evaluate($role, $signals);
        $workspacePrefix = $role === 'super_admin' ? 'platform.' : 'store.';
        return $this->notifications->synchronize($userId, $evaluatedItems, $createInApp, $workspacePrefix);
    }

    /**
     * Forwarding alias for synchronize() returning array representation of items.
     */
    public function refresh(int $userId, string $role, array $signals = [], bool $createInApp = true): array
    {
        return $this->refreshResult($userId, $role, $signals, $createInApp)->dashboardItems();
    }

    /**
     * Forwarding alias for synchronize().
     */
    public function refreshResult(
        int $userId,
        string $role,
        array $signals = [],
        bool $createInApp = true
    ): AttentionRefreshResult {
        if (!empty($signals)) {
            return $this->notifications->synchronize(
                $userId,
                $this->rules->evaluate($role, $signals),
                $createInApp,
                $role === 'super_admin' ? 'platform.' : 'store.'
            );
        }
        return $this->synchronize($userId, $role, $signals, $createInApp);
    }

    private function collectSignals(string $role, array $externalSignals = []): array
    {
        if ($role === 'super_admin') {
            $platform = $this->signals->platform($externalSignals);
            return array_merge($platform, $externalSignals);
        }

        if ($role === 'admin') {
            $store = $this->signals->store();
            return array_merge($store, $externalSignals);
        }

        return $externalSignals;
    }
}
