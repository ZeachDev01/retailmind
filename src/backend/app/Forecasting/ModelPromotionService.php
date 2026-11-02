<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;

/**
 * Service governing Model Promotion, Technical and Operational Reviews.
 *
 * Implements ADR-0005, ADR-0022.
 * Backwards-compatible entry point extending StoreModelGovernanceService.
 */
class ModelPromotionService extends StoreModelGovernanceService
{
    public function __construct(
        PDO $pdo,
        ?CandidateEvaluationService $evaluationService = null
    ) {
        parent::__construct($pdo, $evaluationService);
    }
}
