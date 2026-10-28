<?php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Store\StoreWriteGate;
use DomainException;
use PDO;
use Throwable;

/** One-sale viewing access; financial approval is checked afresh at submission. */
final class RefundExceptionService
{
    public function __construct(private PDO $pdo) {}

    public function authorize(string $username, string $password): array
    {
        $approvedBy = SupervisorApprovalService::authorize(
            $this->pdo,
            $username,
            $password,
            'An Administrator must authorize this specific refund.'
        );
        return ['approved_by' => $approvedBy];
    }

    public function openSale(int $cashierId, string $workspace, int $saleId, string $username, string $password, string $reason): string
    {
        if ($workspace !== 'cashier') throw new DomainException('Switch to your Cashier workspace.');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) throw new DomainException('Explain why the original Cashier is absent or disabled (up to 255 characters).');
        StoreWriteGate::begin($this->pdo);
        try {
            $stmt = $this->pdo->prepare("SELECT shift_id FROM cashier_shifts WHERE cashier_id=? AND status='open' AND locked_at IS NULL" . $this->lock());
            $stmt->execute([$cashierId]);
            $shiftId = $stmt->fetchColumn();
            if (!$shiftId) throw new DomainException('Open and unlock your Cashier Shift first.');
            $stmt = $this->pdo->prepare('SELECT cashier_id FROM sales WHERE sale_id=?' . $this->lock());
            $stmt->execute([$saleId]);
            $seller = $stmt->fetchColumn();
            if (!$seller || (int)$seller === $cashierId) throw new DomainException('Use the ordinary refund form for your own sale, or check the receipt number.');
            $approval = $this->authorize($username, $password);
            $token = bin2hex(random_bytes(32));
            $this->pdo->prepare('INSERT INTO cash_refund_exception_access
                (token_hash,sale_id,cashier_id,shift_id,approved_by,exception_reason,expires_at)
                VALUES (?,?,?,?,?,?,?)')->execute([hash('sha256',$token),$saleId,$cashierId,$shiftId,$approval['approved_by'],$reason,time()+900]);
            $this->pdo->prepare('INSERT INTO activity_log (user_id,action,category,module,record_id,new_value,ip_address) VALUES (?,?,?,?,?,?,?)')
                ->execute([$approval['approved_by'],'Cash refund exception sale access',AuditRecordCategory::STORE_OPERATION,'Cash Refunds',$saleId,
                    json_encode(['sale_id'=>$saleId,'original_cashier_id'=>(int)$seller,'issuing_cashier_id'=>$cashierId,'shift_id'=>(int)$shiftId,'exception_reason'=>$reason,'authorization'=>$approval],JSON_THROW_ON_ERROR),'system']);
            $this->pdo->commit();
            return $token;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function access(string $token, int $cashierId, int $saleId, ?int $shiftId = null): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cash_refund_exception_access WHERE token_hash=? AND cashier_id=? AND sale_id=?' . $this->lock());
        $stmt->execute([hash('sha256',$token),$cashierId,$saleId]);
        $access = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$access || $access['used_refund_id'] !== null || (int)$access['expires_at'] <= time() || ($shiftId !== null && (int)$access['shift_id'] !== $shiftId)) {
            throw new DomainException('This refund exception is expired, already used, or does not match this sale and Cashier Shift. Request a new Administrator authorization.');
        }
        $stmt = $this->pdo->prepare("SELECT shift_id FROM cashier_shifts WHERE shift_id=? AND cashier_id=? AND status='open'");
        $stmt->execute([$access['shift_id'],$cashierId]);
        if (!$stmt->fetchColumn()) throw new DomainException('This refund exception belongs to a different Cashier Shift.');
        return $access;
    }

    public function consume(array $access, int $refundId): void
    {
        $this->pdo->prepare('UPDATE cash_refund_exception_access SET used_refund_id=? WHERE access_id=? AND used_refund_id IS NULL')
            ->execute([$refundId,$access['access_id']]);
    }

    private function lock(): string
    {
        return $this->pdo->inTransaction() && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
