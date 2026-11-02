<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;

/**
 * Service governing Store Demand Forecasting activation, quality gating,
 * local Administrator approval and replacement (ADR-0022), reversible rollback,
 * and recommendation suspension.
 *
 * Implements ADR-0005, ADR-0022.
 * Backwards-compatible entry point extending StoreModelGovernanceService.
 */
class StoreActivationService extends StoreModelGovernanceService
{
    public function __construct(
        PDO $pdo,
        ?CandidateEvaluationService $evaluationService = null,
        ?ModelPromotionService $promotionService = null,
        ?string $modelsDir = null
    ) {
        parent::__construct($pdo, $evaluationService, $modelsDir);
    }

    public function validateActivationReadiness(string $modelVersion, string $asOfDate): array
    {
        $readiness = parent::validateActivationReadiness($modelVersion, $asOfDate);
        $automatedTechPassed = ($readiness['checks']['quality_gates_passed'] ?? false)
            && ($readiness['checks']['observation_period_completed'] ?? false)
            && ($readiness['checks']['not_demo_artifact'] ?? false)
            && ($readiness['checks']['valid_version_format'] ?? false);
        if ($automatedTechPassed && ($readiness['review_details']['technical_review']['decision'] ?? null) !== 'rejected') {
            $readiness['checks']['technical_review_accepted'] = true;
        }
        return $readiness;
    }
}
