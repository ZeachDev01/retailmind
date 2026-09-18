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
    public function refresh(int $userId, string $role, array $signals): array
    {
        $items = $this->rules->evaluate($role, $signals);
        $this->notifications->synchronize($userId, $items);

        return $items;
    }
}
