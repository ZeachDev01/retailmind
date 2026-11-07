<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../../../app/Forecasting/StoreModelGovernanceService.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\StoreModelGovernanceService;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    csrf_verify();
} catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}

$action = $_POST['action'] ?? '';
$actorId = (int)($_SESSION['user_id'] ?? 0);
$actorRole = (string)(function_exists('current_role') ? current_role() : ($_SESSION['role'] ?? ''));

// Local Administrators with store management capabilities (STORE_OPERATIONS) are authorized
// for operational review, store activation, rollback, and recommendation suspension per ADR-0022.
// Technical review, candidate promotion, and platform-level rollback require PLATFORM_GOVERNANCE.
if (in_array($action, ['operational_review', 'activate', 'rollback_store', 'suspend_recommendations', 'resume_recommendations'], true)) {
    require_any_capability([RoleCapabilityPolicy::STORE_OPERATIONS, RoleCapabilityPolicy::PLATFORM_GOVERNANCE]);
} else {
    require_capability(RoleCapabilityPolicy::PLATFORM_GOVERNANCE);
}

$pdo = $GLOBALS['pdo'] ?? \App\Core\Database::connection();
$governanceService = new StoreModelGovernanceService($pdo);

try {
    if ($action === 'promote') {
        $modelVersion = $_POST['model_version'] ?? '';
        if ($modelVersion === '') {
            throw new InvalidArgumentException("Model version is required");
        }
        $governanceService->promote($modelVersion, $actorId);
        echo json_encode(['success' => true, 'message' => "Model $modelVersion promoted successfully"]);
    } elseif ($action === 'rollback') {
        $governanceService->rollback($actorId);
        echo json_encode(['success' => true, 'message' => "Model rolled back successfully"]);
    } elseif ($action === 'technical_review') {
        $modelVersion = $_POST['model_version'] ?? '';
        $decision = $_POST['decision'] ?? '';
        $notes = $_POST['notes'] ?? '';
        if ($modelVersion === '' || !in_array($decision, ['accepted', 'rejected'], true)) {
            throw new InvalidArgumentException("Valid model version and decision (accepted/rejected) required");
        }
        $governanceService->recordTechnicalReview($modelVersion, $decision, $notes, $actorId, $actorRole);
        echo json_encode(['success' => true, 'message' => "Technical review recorded for $modelVersion"]);
    } elseif ($action === 'operational_review') {
        $modelVersion = $_POST['model_version'] ?? '';
        $decision = $_POST['decision'] ?? '';
        $notes = $_POST['notes'] ?? '';
        if ($modelVersion === '' || !in_array($decision, ['accepted', 'rejected'], true)) {
            throw new InvalidArgumentException("Valid model version and decision (accepted/rejected) required");
        }
        $governanceService->recordOperationalReview($modelVersion, $decision, $notes, $actorId, $actorRole);
        echo json_encode(['success' => true, 'message' => "Operational review recorded for $modelVersion"]);
    } elseif ($action === 'activate') {
        $modelVersion = $_POST['model_version'] ?? '';
        $asOfDate = $_POST['as_of_date'] ?? date('Y-m-d');
        $justification = $_POST['justification'] ?? '';
        if ($modelVersion === '') {
            throw new InvalidArgumentException("Model version is required");
        }
        $record = $governanceService->activateStoreModel($modelVersion, $asOfDate, $actorId, $justification, $actorRole);
        echo json_encode(['success' => true, 'message' => "Model $modelVersion activated successfully", 'activation' => $record]);
    } elseif ($action === 'rollback_store') {
        $reason = $_POST['reason'] ?? '';
        $record = $governanceService->rollbackStoreModel($actorId, $reason, $actorRole);
        echo json_encode(['success' => true, 'message' => "Store model rolled back successfully", 'rollback' => $record]);
    } elseif ($action === 'suspend_recommendations') {
        $reason = $_POST['reason'] ?? '';
        $governanceService->suspendRecommendations($actorId, $reason, $actorRole);
        echo json_encode(['success' => true, 'message' => "Replenishment recommendations suspended"]);
    } elseif ($action === 'resume_recommendations') {
        $governanceService->resumeRecommendations($actorId, $actorRole);
        echo json_encode(['success' => true, 'message' => "Replenishment recommendations resumed"]);
    } else {
        throw new InvalidArgumentException("Invalid action");
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(409); // Conflict or unprocessable
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred']);
}
