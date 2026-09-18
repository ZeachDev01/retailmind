<?php

namespace App\Attention;

final class AttentionCenter
{
    public function __construct(
        private AttentionRuleService $rules,
        private AttentionNotificationService $notifications
    ) {
    }

    /**
     * Evaluates live conditions once, then uses that exact result for both
     * dashboard rendering and notification synchronization.
     */
    public function refresh(int $userId, string $role, array $signals, bool $createInApp = true): array
    {
        return $this->refreshResult($userId, $role, $signals, $createInApp)->dashboardItems();
    }

    public function refreshResult(
        int $userId,
        string $role,
        array $signals,
        bool $createInApp = true
    ): AttentionRefreshResult {
        return $this->notifications->synchronize(
            $userId,
            $this->rules->evaluate($role, $signals),
            $createInApp
        );
    }
}
