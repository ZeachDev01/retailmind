<?php

namespace App\Attention;

use PDO;

final class AttentionCenter
{
    private ?DatabaseAttentionSignalSource $signals = null;
    private AttentionRuleService $rules;
    private ?AttentionNotificationService $notifications = null;
    private ?AttentionSettingsService $settings = null;
    private Clock $clock;
    private ?PDO $pdo = null;

    /**
     * Deepened constructor accepts PDO + optional Clock + optional AttentionSettingsService.
     * Also supports legacy test instantiation passing (AttentionRuleService, ?AttentionNotificationService).
     */
    public function __construct(
        PDO|AttentionRuleService $pdoOrRules,
        Clock|AttentionNotificationService|null $clockOrNotifications = null,
        ?AttentionSettingsService $settings = null
    ) {
        if ($pdoOrRules instanceof AttentionRuleService) {
            $this->rules = $pdoOrRules;
            $this->notifications = $clockOrNotifications instanceof AttentionNotificationService ? $clockOrNotifications : null;
            $this->clock = new SystemClock();
        } else {
            $this->pdo = $pdoOrRules;
            $this->clock = $clockOrNotifications instanceof Clock ? $clockOrNotifications : new SystemClock();
            $this->settings = $settings ?? new AttentionSettingsService($this->pdo, $this->clock);
            $this->signals = new DatabaseAttentionSignalSource($this->pdo, $this->clock);
            $this->rules = new AttentionRuleService($this->settings, $this->clock);
            $this->notifications = new AttentionNotificationService($this->pdo, $this->clock);
        }
    }

    /**
     * Read-only active items for dashboards.
     * Evaluates live conditions without mutating in-app notifications.
     *
     * @return AttentionItem[]
     */
    public function getAttention(int $userId, string $role, array $externalSignals = []): array
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

        if ($this->notifications === null) {
            return new AttentionRefreshResult([], []);
        }

        $signals = $this->collectSignals($role, $externalSignals);
        $evaluatedItems = $this->rules->evaluate($role, $signals);
        $workspacePrefix = $role === 'super_admin' ? 'platform.' : 'store.';
        return $this->notifications->synchronize($userId, $evaluatedItems, $createInApp, $workspacePrefix);
    }

    /**
     * Forwarding alias for getAttention() during migration.
     */
    public function refresh(int $userId, string $role, array $signals = [], bool $createInApp = true): array
    {
        if (!empty($signals) || $this->signals === null) {
            return $this->refreshResult($userId, $role, $signals, $createInApp)->dashboardItems();
        }
        $items = $this->getAttention($userId, $role, $signals);
        return array_map(static fn(AttentionItem $item): array => $item->toArray(), $items);
    }

    /**
     * Forwarding alias for synchronize() during migration.
     */
    public function refreshResult(
        int $userId,
        string $role,
        array $signals = [],
        bool $createInApp = true
    ): AttentionRefreshResult {
        if (!empty($signals) || $this->signals === null) {
            if ($this->notifications === null) {
                return new AttentionRefreshResult([], []);
            }
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
        if ($this->signals === null) {
            return $externalSignals;
        }

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
