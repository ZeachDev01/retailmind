<?php

namespace App\Backup;

/** Store reopening decisions remain available when the database cannot be read. */
final class StoreReopening
{
    public static function readiness(?array $state, ?int $now = null): array
    {
        if ($state === null) {
            return [
                'ready' => false,
                'missing' => ['No active recovery operation.'],
                'guidance' => 'No active recovery operation is pending.',
            ];
        }
        if (($state['stage'] ?? '') !== RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING) {
            return [
                'ready' => false,
                'missing' => ['Operation stage must be operator_review_pending (currently ' . ($state['stage'] ?? 'none') . ').'],
                'guidance' => 'Reopening requires operation stage to be operator_review_pending.',
            ];
        }

        $missing = [];
        $requiredKeys = ['stock', 'receipt', 'upload', 'attribution'];

        $automated = $state['review_checks']['automated'] ?? [];
        foreach ($requiredKeys as $key) {
            if (!isset($automated[$key]) || ($automated[$key]['status'] ?? '') !== 'passed') {
                $missing[] = "Automated check '{$key}' has not passed.";
            } else {
                $chkTime = strtotime((string)($automated[$key]['checked_at'] ?? ''));
                if ($chkTime === false || (($now ?? time()) - $chkTime) > 900) {
                    $missing[] = "Automated check '{$key}' is stale (> 15 min old). Re-run verification checks.";
                }
            }
        }

        $human = $state['review_checks']['human_confirmations'] ?? [];
        foreach ($requiredKeys as $key) {
            if (empty($human[$key]['confirmed'])) {
                $missing[] = "Human confirmation for '{$key}' is missing.";
            }
        }

        if (!empty($missing)) {
            return [
                'ready' => false,
                'missing' => $missing,
                'guidance' => 'Reopening blocked by missing or unconfirmed review checks: ' . implode(' ', $missing),
            ];
        }

        return [
            'ready' => true,
            'missing' => [],
            'guidance' => 'All automated checks and human checklist confirmations verified. Store is ready to reopen.',
        ];
    }

    public static function guidance(array $state, ?int $now = null): array
    {
        $currentStage = $state['stage'] ?? '';

        if ($currentStage === RecoveryStore::STAGE_RECOVERY_REQUIRED) {
            return [
                'stage' => 'actionable_failure',
                'stage_label' => 'Actionable failure (Recovery required)',
                'next_permitted_action' => 'Execute an alternative recovery action: Complete Safety Recovery (rollback to verified destination safety copy) or Restart Approved Incoming Replacement.',
                'ready' => false,
                'missing' => ['Recovery operation encountered a failure.'],
            ];
        }

        if ($currentStage === RecoveryStore::STAGE_SAFETY_VERIFIED) {
            return [
                'stage' => 'ready_to_restore',
                'stage_label' => 'Ready to restore',
                'next_permitted_action' => 'Authenticated Administrator or Super Administrator may initiate encrypted replacement or restart incoming replacement with verified passphrases.',
                'ready' => true,
                'missing' => [],
            ];
        }

        if ($currentStage === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING) {
            $readiness = self::readiness($state, $now);
            if (!$readiness['ready']) {
                return [
                    'stage' => 'verification_needed',
                    'stage_label' => 'Verification needed',
                    'next_permitted_action' => 'Run automated verification checks and confirm all human checklist items (stock, receipt, upload, attribution) before reopening.',
                    'ready' => false,
                    'missing' => $readiness['missing'],
                ];
            }

            return [
                'stage' => 'ready_to_reopen',
                'stage_label' => 'Ready to reopen',
                'next_permitted_action' => 'Authorized operator must confirm single active Store and re-enter destination password to explicitly reopen Store access.',
                'ready' => true,
                'missing' => [],
            ];
        }

        if ($currentStage === RecoveryStore::STAGE_REOPENED_COMPLETED) {
            return [
                'stage' => 'reopened_completed',
                'stage_label' => 'Reopening completed',
                'next_permitted_action' => 'Store is online. Ordinary requests admitted.',
                'ready' => true,
                'missing' => [],
            ];
        }

        return [
            'stage' => $currentStage ?: 'idle',
            'stage_label' => ucwords(str_replace('_', ' ', $currentStage ?: 'idle')),
            'next_permitted_action' => 'Wait for stage completion. Automated recovery process is running.',
            'ready' => false,
            'missing' => [],
        ];
    }
}
