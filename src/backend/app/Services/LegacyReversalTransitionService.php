<?php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Store\StoreWriteGate;
use DomainException;
use PDO;
use PDOException;
use Throwable;

require_once __DIR__ . '/CashierShiftService.php';
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../Audit/AuditRecordCategory.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';

/** Explicit decisions for retained requests; never creates a replacement refund. */
final class LegacyReversalTransitionService
{
    public function __construct(private PDO $pdo) {}

    public function decide(int $id, int $actor, string $outcome, string $reason, array $evidence): void
    {
        $reason = trim($reason);
        if (!in_array($outcome, ['approved', 'rejected'], true) || $reason === '' || mb_strlen($reason) > 255) {
            throw new DomainException('Explain the Administrator decision (up to 255 characters).');
        }
        if (empty($evidence['no_prior_effects'])) {
            throw new DomainException('Verify no payout or stock restoration has already occurred outside this pending record. Otherwise leave it pending for investigation.');
        }
        for ($attempt = 0; ; $attempt++) {
            try {
                $this->transition($id, $actor, $outcome, $reason, $evidence);
                return;
            } catch (PDOException $e) {
                if ($attempt >= 2 || !in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) throw $e;
                // The entire decision rolled back; re-read all evidence after locking.
                usleep(50000 * ($attempt + 1));
            }
        }
    }

    private function authorize(int $actor): array
    {
        if ((int)($_SESSION['user_id'] ?? 0) !== $actor || !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true)) {
            throw new DomainException('Only an authenticated Administrator workspace may decide a Legacy Reversal.');
        }
        $stmt = $this->pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON r.role_id=u.role_id
            WHERE u.user_id=? AND u.status='active' AND u.must_change_password=0 FOR UPDATE");
        $stmt->execute([$actor]);
        $primary = $stmt->fetchColumn();
        if (!in_array($primary, ['admin', 'super_admin'], true)) throw new DomainException('Administrator authority is required.');
        $authorization = ['actor_id' => $actor, 'workspace' => $_SESSION['role']];
        if ($primary === 'super_admin') {
            $stmt = $this->pdo->prepare("SELECT session_id,reason FROM emergency_access_sessions WHERE actor_user_id=?
                AND status='active' AND expires_at>UTC_TIMESTAMP() ORDER BY session_id DESC LIMIT 1 FOR UPDATE");
            $stmt->execute([$actor]);
            $access = $stmt->fetch();
            if (!$access || trim($access['reason']) === '') throw new DomainException('Active, reason-bound Emergency Access is required.');
            $authorization['emergency_access'] = $access;
        }
        return $authorization;
    }

    private function transition(int $id, int $actor, string $outcome, string $reason, array $evidence): void
    {
        StoreWriteGate::begin($this->pdo);
        try {
            $authorization = $this->authorize($actor);
            // Discovery reads never authorize effects. Lock order matches Cash Refunds:
            // paying shift, sale, request, lines/batches/inventory.
            $stmt = $this->pdo->prepare('SELECT sr.sale_id,s.shift_id FROM sale_reversals sr LEFT JOIN sales s ON s.sale_id=sr.sale_id WHERE sr.reversal_id=?');
            $stmt->execute([$id]);
            $link = $stmt->fetch();
            if (!$link) throw new DomainException('Legacy Reversal not found.');
            $shift = null;
            if ($outcome === 'approved' && $link['shift_id']) {
                $stmt = $this->pdo->prepare('SELECT * FROM cashier_shifts WHERE shift_id=? FOR UPDATE');
                $stmt->execute([$link['shift_id']]);
                $shift = $stmt->fetch();
            }
            $stmt = $this->pdo->prepare('SELECT * FROM sales WHERE sale_id=? FOR UPDATE');
            $stmt->execute([$link['sale_id']]);
            $sale = $stmt->fetch();
            $stmt = $this->pdo->prepare('SELECT * FROM sale_reversals WHERE reversal_id=? FOR UPDATE');
            $stmt->execute([$id]);
            $record = $stmt->fetch();
            if (!$sale || !$record || (int)$record['sale_id'] !== (int)$sale['sale_id'] || ($outcome === 'approved' && $sale['shift_id'] != $link['shift_id'])) {
                throw new DomainException('Broken or changed historical link: leave pending for investigation.');
            }
            if ($record['status'] === $outcome) { $this->pdo->commit(); return; }
            if ($record['status'] !== 'pending') throw new DomainException('Terminal Legacy Reversals are immutable; investigate unknown states.');
            $checks = $outcome === 'approved' ? $this->approve($record, $sale, $shift, $actor, $evidence) : ['cash_change' => 0, 'stock_change' => 0];
            if ($outcome === 'rejected') {
                $stmt = $this->pdo->prepare('SELECT * FROM sale_reversal_items WHERE reversal_id=? ORDER BY sale_item_id FOR UPDATE');
                $stmt->execute([$id]);
                $checks += ['retained_items'=>$stmt->fetchAll(),'requested_amount'=>$record['refund_amount'],'original_shift_id'=>$sale['shift_id'],'sale_id'=>$sale['sale_id'],'consumed_quantity_change'=>0,'consumed_value_change'=>0];
            }
            $this->pdo->prepare('UPDATE sale_reversals SET status=?,approved_by=?,approved_at=CURRENT_TIMESTAMP,rejection_reason=? WHERE reversal_id=?')
                ->execute([$outcome,$actor,$outcome === 'rejected' ? $reason : $record['rejection_reason'],$id]);
            if ($outcome === 'approved' && $record['settlement_method'] === 'cash') {
                $actual = (new CashierShiftService($this->pdo))->calculateShift((int)$sale['shift_id'])['calculated_expected_cash'];
                if ((int)round($actual*100) !== (int)round($checks['cash_after']*100)) {
                    throw new DomainException('Drawer accounting does not reconcile; approval rolled back for investigation.');
                }
            }
            $this->pdo->prepare('INSERT INTO activity_log (user_id,action,category,module,record_id,previous_value,new_value,ip_address) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$actor,'Legacy Reversal '.$outcome,AuditRecordCategory::STORE_OPERATION,'Legacy Reversals',$id,
                    json_encode($record,JSON_THROW_ON_ERROR),json_encode(['status'=>$outcome,'decision_reason'=>$reason,'evidence'=>$evidence,'authorization'=>$authorization,'checks'=>$checks],JSON_THROW_ON_ERROR),'system']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function approve(array $record, array $sale, ?array $shift, int $actor, array $evidence): array
    {
        if (empty($evidence['restockable']) || empty($evidence['refund_ineligibility_acknowledged'])) throw new DomainException('Verify all returns are Restockable and acknowledge whole-sale Cash Refund ineligibility.');
        $stmt = $this->pdo->prepare('SELECT refund_id FROM cash_refunds WHERE sale_id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$sale['sale_id']]);
        if ($stmt->fetchColumn()) throw new DomainException('This sale already has a Cash Refund. Legacy approval is refused.');
        $guard = new \FiscalPeriodGuardService($this->pdo);
        $guard->assertOpenForDate($sale['sale_date'],'sales','legacy approval');
        $guard->assertOpenForDate($sale['sale_date'],'sale_reversals','legacy approval');
        $guard->assertOpenNow('sale_reversals','legacy approval');
        $guard->assertOpenNow('stock_movements','legacy approval');
        $method = $record['settlement_method'];
        $amount = (int)round((float)$record['refund_amount'] * 100);
        if ($record['reversal_type'] === 'exchange' && ($method === 'none' || empty($evidence['separate_replacement_sale_acknowledged']))) {
            throw new DomainException('A Legacy exchange requires a verified ordinary cash/card/e-wallet refund and a separately paid replacement sale. No store credit or replacement inventory is created.');
        }
        if (!in_array($method,['none','cash','card','ewallet'],true) || ($method !== 'none' && $method !== $sale['payment_method']) || ($method === 'none' ? $amount !== 0 : $amount <= 0)) {
            throw new DomainException('Unsupported settlement or amount; investigate without editing the request. Exchanges require a refund and a separate new sale.');
        }
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(refund_amount),0) FROM sale_reversals WHERE sale_id=? AND status='approved'");
        $stmt->execute([$sale['sale_id']]);
        $remainingPaid = (int)round(((float)$sale['total_amount']-(float)$stmt->fetchColumn())*100);
        if ($amount > $remainingPaid || $remainingPaid < 0) throw new DomainException('Legacy amount exceeds remaining paid value.');
        $stmt = $this->pdo->prepare('SELECT * FROM sale_items WHERE sale_id=? ORDER BY sale_item_id FOR UPDATE');
        $stmt->execute([$sale['sale_id']]);
        $sold = $stmt->fetchAll();
        $gross = array_sum(array_column($sold,'subtotal'));
        $grossSoFar = 0; $netSoFar = 0; $net = [];
        foreach ($sold as $line) {
            $grossSoFar += (float)$line['subtotal'];
            $next = $gross > 0 ? (int)round((float)$sale['total_amount']*100*$grossSoFar/$gross) : 0;
            $net[$line['sale_item_id']] = [$line,$next-$netSoFar]; $netSoFar=$next;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM sale_reversal_items WHERE reversal_id=? ORDER BY sale_item_id FOR UPDATE');
        $stmt->execute([$record['reversal_id']]);
        $items = $stmt->fetchAll();
        if (!$items) throw new DomainException('Missing historical return items.');
        $expectedAmount = 0; $stockChange = 0; $seen = []; $lineChecks = [];
        foreach ($items as $item) {
            $id = $item['sale_item_id']; $q = (int)$item['quantity'];
            if (!isset($net[$id]) || isset($seen[$id]) || $q <= 0) throw new DomainException('Invalid or duplicate historical return line.');
            $seen[$id]=true; [$line,$lineNet] = $net[$id];
            if ($item['product_id'] != $line['product_id'] || (float)$item['unit_price'] != (float)$line['unit_price'] || (int)round((float)$item['subtotal']*100) !== (int)round((float)$line['unit_price']*$q*100)) throw new DomainException('Historical return does not match the original sale.');
            $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(i.quantity),0) FROM sale_reversal_items i JOIN sale_reversals r ON r.reversal_id=i.reversal_id WHERE i.sale_item_id=? AND r.status='approved'");
            $stmt->execute([$id]); $previous=(int)$stmt->fetchColumn();
            if ((int)$line['quantity'] <= 0 || $previous < 0 || $q+$previous>(int)$line['quantity']) throw new DomainException('Return exceeds or cannot reconcile to remaining sold quantity.');
            $expectedAmount += (int)round($lineNet*($previous+$q)/(int)$line['quantity'])-(int)round($lineNet*$previous/(int)$line['quantity']);
            $this->restoreBatches($line,$q,$previous);
            $stmt = $this->pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE product_id=? FOR UPDATE');
            $stmt->execute([$line['product_id']]); $stockBefore=$stmt->fetchColumn();
            if ($stockBefore === false || (int)$stockBefore < 0) throw new DomainException('Missing or inconsistent inventory evidence.');
            $stmt = $this->pdo->prepare('UPDATE inventory SET quantity_on_hand=quantity_on_hand+? WHERE product_id=?');
            $stmt->execute([$q,$line['product_id']]);
            if ($stmt->rowCount() !== 1) throw new DomainException('Missing inventory evidence.');
            $this->pdo->prepare('UPDATE products SET quantity_sold=GREATEST(quantity_sold-?,0) WHERE product_id=?')->execute([$q,$line['product_id']]);
            $this->pdo->prepare("INSERT INTO stock_movements(product_id,change_qty,reason,moved_by) VALUES (?,?,'return',?)")->execute([$line['product_id'],$q,$actor]);
            $stmt = $this->pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE product_id=?');
            $stmt->execute([$line['product_id']]); $stockAfter=(int)$stmt->fetchColumn();
            if ($stockAfter !== (int)$stockBefore+$q) throw new DomainException('Inventory accounting does not reconcile; investigate.');
            $lineChecks[]=['sale_item_id'=>$id,'quantity'=>$q,'stock_before'=>(int)$stockBefore,'stock_after'=>$stockAfter,'legacy_quantity_before'=>(int)$line['quantity']-$previous,'legacy_quantity_after'=>(int)$line['quantity']-$previous-$q];
            $stockChange += $q;
        }
        if ($method !== 'none' && $amount !== $expectedAmount) throw new DomainException('Amount does not reconcile to original paid line allocation; leave pending for investigation.');
        $cashBefore = null;
        if ($method === 'cash') {
            if (!$shift || $shift['status'] !== 'open' || $shift['locked_at'] !== null || (int)($evidence['paying_shift_id'] ?? 0) !== (int)$sale['shift_id'] || empty($evidence['single_payout_confirmed'])) throw new DomainException('Cash approval requires the original open, unlocked paying drawer and confirmation of one payout.');
            $shifts = new CashierShiftService($this->pdo);
            $shifts->assertCashPayoutAvailable((int)$sale['shift_id'],$amount/100);
            $cashBefore = $shifts->calculateShift((int)$sale['shift_id'])['calculated_expected_cash'];
        } elseif ($method !== 'none' && (empty($evidence['external_completed']) || trim($evidence['payment_reference'] ?? '') === '' || mb_strlen($evidence['payment_reference']) > 100)) {
            throw new DomainException('Verify the external settlement and its reference (up to 100 characters).');
        }
        return ['lines'=>$lineChecks,'stock_change'=>$stockChange,'paid_before'=>$remainingPaid/100,'paid_after'=>($remainingPaid-$amount)/100,'cash_before'=>$cashBefore,'cash_after'=>$cashBefore === null ? null : $cashBefore-$amount/100,'cash_change'=>$method === 'cash' ? -$amount/100 : 0,'paying_shift_id'=>$sale['shift_id'],'cash_refund_eligible'=>false];
    }

    private function restoreBatches(array $line, int $quantity, int $previous): void
    {
        $stmt=$this->pdo->prepare('SELECT a.quantity AS allocated_quantity,b.* FROM sale_item_batches a LEFT JOIN product_batches b ON b.batch_id=a.batch_id WHERE a.sale_item_id=? ORDER BY a.sale_item_batch_id FOR UPDATE');
        $stmt->execute([$line['sale_item_id']]); $rows=$stmt->fetchAll();
        if (array_sum(array_column($rows,'allocated_quantity'))!=(int)$line['quantity']) throw new DomainException('Missing or inconsistent batch allocation evidence.');
        $seenBatches = [];
        foreach ($rows as $batch) {
            if (isset($seenBatches[$batch['batch_id']])) throw new DomainException('Duplicate historical batch allocation: leave pending for investigation.');
            $seenBatches[$batch['batch_id']] = true;
            $allocated=(int)$batch['allocated_quantity'];
            if ($allocated <= 0) throw new DomainException('Invalid historical batch allocation.');
            $skip=min($previous,$allocated); $previous-=$skip;
            $restore=min($quantity,$allocated-$skip);
            if (!$batch['batch_id'] || $batch['product_id'] != $line['product_id'] || (int)$batch['remaining_quantity'] < 0 || (int)$batch['remaining_quantity']+$restore>(int)$batch['quantity']) throw new DomainException('Batch restoration cannot be reconciled.');
            if ($restore>0) $this->pdo->prepare('UPDATE product_batches SET remaining_quantity=remaining_quantity+? WHERE batch_id=?')->execute([$restore,$batch['batch_id']]);
            $quantity-=$restore;
        }
        if ($quantity !== 0) throw new DomainException('Incomplete batch restoration evidence.');
    }
}
