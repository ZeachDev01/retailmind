<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);
require_once __DIR__ . '/../../../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../../../app/Forecasting/ModelPromotionService.php';

use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\ModelPromotionService;

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
$superAdminId = (int)($_SESSION['user_id'] ?? 0);
$pdo = $GLOBALS['pdo'] ?? \App\Core\Database::connection();
$evalService = new CandidateEvaluationService($pdo);
$promotionService = new ModelPromotionService($pdo, $evalService);

try {
    if ($action === 'promote') {
        $modelVersion = $_POST['model_version'] ?? '';
        if ($modelVersion === '') {
            throw new InvalidArgumentException("Model version is required");
        }
        $promotionService->promote($modelVersion, $superAdminId);
        echo json_encode(['success' => true, 'message' => "Model $modelVersion promoted successfully"]);
    } elseif ($action === 'rollback') {
        $promotionService->rollback($superAdminId);
        echo json_encode(['success' => true, 'message' => "Model rolled back successfully"]);
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
