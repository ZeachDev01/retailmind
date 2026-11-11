<?php
declare(strict_types=1);

namespace App\Forecasting;

require_once __DIR__ . '/StoreTrainingEligibilityService.php';

/**
 * One shared read of the forecasting setup state: whether a model is approved and
 * how many Products already satisfy Store Training Eligibility.
 *
 * States:
 * - collecting: no approved model and no Product has enough usable history yet.
 * - ready: no approved model, but at least one Product is eligible for training.
 * - active: an approved model exists.
 */
final class ForecastSetupStatus
{
    private static ?\WeakMap $cache = null;

    /** @return array{state:string, approved_model:string, ready_products:int, total_products:int, required_days:int, best_usable_days:int} */
    public static function snapshot(\PDO $pdo, ?string $throughDate = null): array
    {
        // shortcut: eligibility is evaluated per Product on every request; add a
        // persisted snapshot if a Store reaches hundreds of Products.
        self::$cache ??= new \WeakMap();
        $throughDate ??= date('Y-m-d', strtotime('-1 day'));
        $cached = self::$cache[$pdo] ?? [];
        if (isset($cached[$throughDate])) {
            return $cached[$throughDate];
        }

        $approved = '';
        try {
            $approved = (string)($pdo->query(
                "SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'"
            )->fetchColumn() ?: '');
        } catch (\Throwable) {
        }

        $requiredDays = StoreTrainingEligibilityService::policy()['minimum_usable_days'];
        $total = 0;
        $ready = 0;
        $best = 0;
        // An approved model makes the process state irrelevant; skip the scan.
        if ($approved === '') {
            try {
                $service = new StoreTrainingEligibilityService();
                foreach ($service->evaluateAllProducts($pdo, $throughDate) as $row) {
                    $total++;
                    $usable = (int)($row['accumulated_usable_days'] ?? 0);
                    $best = max($best, $usable);
                    if (!empty($row['eligible'])) {
                        $ready++;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $state = self::state($approved, $ready);

        $cached[$throughDate] = [
            'state' => $state,
            'approved_model' => $approved,
            'ready_products' => $ready,
            'total_products' => $total,
            'required_days' => $requiredDays,
            'best_usable_days' => $best,
        ];
        self::$cache[$pdo] = $cached;

        return $cached[$throughDate];
    }

    public static function state(string $approvedModel, int $readyProducts): string
    {
        if ($approvedModel !== '') {
            return 'active';
        }
        return $readyProducts > 0 ? 'ready' : 'collecting';
    }

    /** Drops the request-scoped snapshot after a model or history change. */
    public static function forget(?\PDO $pdo = null): void
    {
        if ($pdo === null || self::$cache === null) {
            self::$cache = null;
            return;
        }
        unset(self::$cache[$pdo]);
    }
}
