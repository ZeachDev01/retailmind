<?php

namespace App\Services;

use App\GCash\AdminOverviewReport;
use App\GCash\GCashActivityScopeReport;
use App\GCash\GCashCashierOverviewReport;
use App\GCash\GCashExchangeReview;
use App\GCash\GCashExchangeWorkflow;
use App\GCash\GCashLiveCustodySession;
use App\GCash\GCashPricing;
use App\GCash\GCashReference;
use App\Store\StoreWriteGate;
use DomainException;
use PDO;
use PDOException;
use Throwable;

require_once __DIR__ . '/FiscalPeriodGuardService.php';

/**
 * GCash facade. Preserves the legacy public interface; delegates to deep modules
 * (GCashLiveCustodySession, GCashActivityScopeReport, GCashExchangeWorkflow, GCashExchangeReview).
 */
final class GCashTransactionService
{
    private ?\FiscalPeriodGuardService $fiscalPeriodGuard = null;
    private ?GCashLiveCustodySession $custodyModule = null;
    private ?GCashActivityScopeReport $activityModule = null;
    private ?GCashExchangeReview $reviewModule = null;
    private ?GCashExchangeWorkflow $workflowModule = null;
    private ?AdminOverviewReport $overviewReportModule = null;
    private ?GCashCashierOverviewReport $cashierOverviewReportModule = null;

    public function __construct(private ?PDO $pdo = null, private array $rules = [])
    {
        if ($pdo) {
            $this->fiscalPeriodGuard = new \FiscalPeriodGuardService($pdo);
        }
    }

    private function pdo(): PDO
    {
        if (!$this->pdo) {
            throw new DomainException('Database connection required for this operation.');
        }
        return $this->pdo;
    }

    private function fiscalPeriodGuard(): \FiscalPeriodGuardService
    {
        if (!$this->fiscalPeriodGuard) {
            $this->fiscalPeriodGuard = new \FiscalPeriodGuardService($this->pdo());
        }
        return $this->fiscalPeriodGuard;
    }

    private function custody(): GCashLiveCustodySession
    {
        return $this->custodyModule ??= new GCashLiveCustodySession($this->pdo());
    }

    private function activity(): GCashActivityScopeReport
    {
        return $this->activityModule ??= new GCashActivityScopeReport($this->pdo());
    }

    private function reviewModule(): GCashExchangeReview
    {
        return $this->reviewModule ??= new GCashExchangeReview($this->pdo(), $this->rules, $this->custody());
    }

    private function workflow(): GCashExchangeWorkflow
    {
        return $this->workflowModule ??= new GCashExchangeWorkflow($this->pdo(), $this->rules, $this->custody(), $this->reviewModule());
    }

    private function overviewReport(): AdminOverviewReport
    {
        return $this->overviewReportModule ??= new AdminOverviewReport($this->custody(), $this->activity(), $this->reviewModule());
    }

    private function cashierOverviewReport(): GCashCashierOverviewReport
    {
        return $this->cashierOverviewReportModule ??= new GCashCashierOverviewReport($this->pdo(), $this->custody(), $this->reviewModule());
    }

    public function calculateFee(mixed $principal): array { return GCashPricing::calculateFee($principal); }
    public function calculatePreview(string $type, mixed $principal, ?string $feeMethod = null): array { return GCashPricing::calculatePreview($type, $principal, $feeMethod); }
    public function validatePrincipal(mixed $principal): int { return GCashPricing::validatePrincipal($principal); }
    public function feeForPrincipal(int $principal): float { return GCashPricing::feeForPrincipal($principal); }
    private function normalizeCashOutFeeMethod(?string $method): string { return GCashPricing::normalizeCashOutFeeMethod($method); }
    public function reference(string $value): string { return GCashReference::normalize($value, (int)($this->rules['min_length'] ?? 13), (int)($this->rules['max_length'] ?? 13)); }
    public function exists(string $reference): bool { return $this->workflow()->exists($reference); }

    public function cashierOverview(int $cashierId): array
    {
        return $this->cashierOverviewReport()->cashierOverview($cashierId);
    }

    public function history(int $cashierId, int $page): array { return $this->activity()->cashierHistory($cashierId, $page); }
    public function adminTransactions(array $filters = []): \Generator { return $this->activity()->adminTransactions($filters); }
    public function overview(array $filters = []): array { return $this->adminOverview($filters); }

    public function adminOverview(array $filters = []): array
    {
        $role = (function_exists('current_role') ? current_role() : null) ?? ($_SESSION['role'] ?? null) ?? ($filters['actor_role'] ?? null);
        if (!in_array($role, ['admin', 'super_admin'], true)) {
            if (!empty($filters['approver_username']) && !empty($filters['approver_password'])) {
                $this->reviewModule()->authorizeAdmin($filters['approver_username'], $filters['approver_password']);
            } else {
                throw new DomainException('Administrator authorization required.', 403);
            }
        }
        return $this->overviewReport()->overview($filters);
    }

    /** HTTP GET adapter: one filter-bag seam shared with the admin overview page. */
    public function adminOverviewFromQuery(array $query, string $role, int $actorId): array
    {
        return $this->adminOverview($this->overviewReport()->filters($query, $role, $actorId));
    }

    public function getCurrentSession(): ?array { return $this->custody()->getCurrentSession(); }
    public function getSession(int $sessionId): array { return $this->custody()->getSession($sessionId); }
    public function lastClosedSession(): ?array { return $this->custody()->lastClosedSession(); }
    public function calculateSessionExpected(int $sessionId): array { return $this->custody()->calculateSessionExpected($sessionId); }
    public function openSession(int $cashierId, float $openingServiceCash, float $openingWalletBalance): int { return $this->custody()->openSession($cashierId, $openingServiceCash, $openingWalletBalance); }
    public function closeSession(int $cashierId, float $actualServiceCash, float $actualWalletBalance, string $notes, int $unresolvedCarryover = 0, ?int $responsibleUserId = null, ?string $transferReason = null): array { return $this->custody()->closeSession($cashierId, $actualServiceCash, $actualWalletBalance, $notes, $unresolvedCarryover, $responsibleUserId, $transferReason); }
    public function eligibleHandoverRecipients(): array { return $this->custody()->eligibleHandoverRecipients(); }
    public function handoverSession(int $outgoingCashierId, int $incomingCashierId, float $countedCash, float $checkedWallet, string $notes, ?array $acceptedTransactionIds = null, ?int $differenceResponsibleUserId = null): array { return $this->custody()->handoverSession($outgoingCashierId, $incomingCashierId, $countedCash, $checkedWallet, $notes, $acceptedTransactionIds, $differenceResponsibleUserId); }
    public function emergencyHandover(int $adminId, ?int $incomingCashierId, float $countedCash, float $checkedWallet, string $reason, ?array $acceptedTransactionIds = null, ?int $differenceResponsibleUserId = null): array { return $this->custody()->emergencyHandover($adminId, $incomingCashierId, $countedCash, $checkedWallet, $reason, $acceptedTransactionIds, $differenceResponsibleUserId); }

    public function save(int $cashierId, string $reference, string $amount, string $type): int { return $this->workflow()->save($cashierId, $reference, $amount, $type); }
    public function review(int $cashierId, int $id, string $status, bool $confirmed, string $note): void { $this->workflow()->review($cashierId, $id, $status, $confirmed, $note); }
    public function recordCashIn(int $cashierId, array $data): int { return $this->workflow()->recordCashIn($cashierId, $data); }
    public function recordCashOut(int $cashierId, array $data): int { return $this->workflow()->recordCashOut($cashierId, $data); }
    public function authorizeAdmin(string $username, string $password): int { return $this->reviewModule()->authorizeAdmin($username, $password); }
    public function previewProductDrawerFunding(int $cashierId, float $amount): array { return $this->workflow()->previewProductDrawerFunding($cashierId, $amount); }
    public function recordShortageRecovery(int $cashierId, array $data): int { return $this->workflow()->recordShortageRecovery($cashierId, $data); }
    public function getMoneyHistory(array $filters = []): array { return $this->workflow()->getMoneyHistory($filters); }
    public function recordFundingMovement(int $actorId, string $actorRole, array $data): int { return $this->workflow()->recordFundingMovement($actorId, $actorRole, $data); }

    public function previewLinkedCorrection(int $transactionId, string $mode, float $cashDelta, float $walletDelta): array { return $this->reviewModule()->previewLinkedCorrection($transactionId, $mode, $cashDelta, $walletDelta); }
    public function recordLinkedCorrection(int $adminId, string $adminRole, int $transactionId, float $cashDelta, float $walletDelta, string $reason, ?int $sessionId = null, string $mode = 'money_movement', ?int $recordedBy = null): int { return $this->reviewModule()->recordLinkedCorrection($adminId, $adminRole, $transactionId, $cashDelta, $walletDelta, $reason, $sessionId, $mode, $recordedBy); }
    public function resolveNeedsChecking(int $actorId, int $transactionId, string $action, array $data = []): array { return $this->reviewModule()->resolveNeedsChecking($actorId, $transactionId, $action, $data); }
    public function saveReviewDraft(int $adminId, string $adminRole, int $sessionId, string $draftNotes): array { return $this->reviewModule()->saveReviewDraft($adminId, $adminRole, $sessionId, $draftNotes); }
    public function markReviewed(int $adminId, string $adminRole, int $sessionId, array|string $reviewData, ?float $remainingShortage = null): array { return $this->reviewModule()->markReviewed($adminId, $adminRole, $sessionId, $reviewData, $remainingShortage); }
    public function reopenReview(int $adminId, string $adminRole, int $sessionId, string $reason): array { return $this->reviewModule()->reopenReview($adminId, $adminRole, $sessionId, $reason); }
    public function markClosingDifferenceReviewed(int $adminId, string $adminRole, int $sessionId, array|string $reviewData, ?float $remainingShortage = null): array { return $this->reviewModule()->markClosingDifferenceReviewed($adminId, $adminRole, $sessionId, $reviewData, $remainingShortage); }
    public function reopenClosingDifferenceReview(int $adminId, string $adminRole, int $sessionId, string $reason): array { return $this->reviewModule()->reopenClosingDifferenceReview($adminId, $adminRole, $sessionId, $reason); }
    public function waiveDiscrepancy(int $adminId, string $adminRole, int $sessionId, string $waiverNotes, array $checks = []): void { $this->reviewModule()->waiveDiscrepancy($adminId, $adminRole, $sessionId, $waiverNotes, $checks); }
    public function resolveEligibleRole(int $userId): string { return $this->reviewModule()->resolveEligibleRole($userId); }
    public function transferExchangeResponsibility(int $actorId, int $transactionId, int $newResponsibleUserId, string $reason, bool $acceptOnly = false): array { return $this->reviewModule()->transferExchangeResponsibility($actorId, $transactionId, $newResponsibleUserId, $reason, $acceptOnly); }
    public function transferDifferenceResponsibility(int $actorId, int $sessionId, int $newResponsibleUserId, string $reason): array { return $this->reviewModule()->transferDifferenceResponsibility($actorId, $sessionId, $newResponsibleUserId, $reason); }
    public function recordFailedExchangeReturn(int $actorId, int $transactionId, array $data): array { return $this->reviewModule()->recordFailedExchangeReturn($actorId, $transactionId, $data); }
}
