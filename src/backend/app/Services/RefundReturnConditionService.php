<?php
namespace App\Services;
use PDO;
use DomainException;
use InvalidArgumentException;
use Throwable;
use App\Store\StoreWriteGate;
use App\Audit\AuditRecordCategory;
require_once __DIR__.'/FiscalPeriodGuardService.php';
require_once __DIR__.'/SalesWorkflowService.php';
require_once __DIR__.'/BatchInventoryLedger.php';
use App\Dashboard\StoreOperationsDashboardWorkspace;

/** Append-only condition history; money and the original receipt stay immutable. */
class RefundReturnConditionService
{
    public function __construct(private PDO $pdo)
    {
        self::ensureSchema($pdo);
    }
    public static function ensureSchema(PDO $pdo): void
    {
        $id=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INT AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec("CREATE TABLE IF NOT EXISTS refund_return_condition_corrections (
            correction_id $id, refund_item_id INT NOT NULL, previous_condition VARCHAR(30) NOT NULL,
            new_condition VARCHAR(30) NOT NULL, quantity INT NOT NULL, selling_form VARCHAR(20) NOT NULL,
            pack_size INT NULL, reason VARCHAR(255) NOT NULL, actor_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, stock_movement_id INT NOT NULL,
            request_token VARCHAR(64) NOT NULL UNIQUE, version INT NOT NULL,
            UNIQUE(refund_item_id,version))");
    }
    private function lock(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''; }
    private function authorize(int $actor,string $role,int $refund,bool $writing=false): void
    {
        if (!in_array($role, ['cashier','inventory_manager','admin'], true) || ($writing && $role==='admin')) {
            throw new DomainException('Only the issuing Cashier or an authorized Inventory Manager can correct this refund.');
        }
        // The active Workspace controls authority even when an account holds both roles.
        $q=$this->pdo->prepare("SELECT cr.refund_id FROM users u JOIN roles r ON r.role_id=u.role_id LEFT JOIN user_roles ur ON ur.user_id=u.user_id LEFT JOIN roles ar ON ar.role_id=ur.role_id JOIN cash_refunds cr ON cr.refund_id=? WHERE u.user_id=? AND u.status='active' AND (ar.role_name=? OR (r.role_name=? AND NOT EXISTS (SELECT 1 FROM user_roles assigned WHERE assigned.user_id=u.user_id)))" . ($role==='cashier'?' AND cr.cashier_id=u.user_id':''));
        $q->execute([$refund,$actor,$role,$role]);
        $authorized=$q->fetchColumn();
        if($authorized===false) throw new DomainException('This Workspace is not authorized to access this refund.');
        if($role!=='cashier') {
            $q=$this->pdo->prepare('SELECT sale_id FROM cash_refunds WHERE refund_id=?');$q->execute([$refund]);$saleId=(int)$q->fetchColumn();
            if((new \App\Receipts\ReceiptEngine($this->pdo))->fetchSale((int)$saleId, function_exists('store_scope_id')?store_scope_id($this->pdo):1)===null) throw new DomainException('This refund is outside the authorized Store.');
        }
    }
    public function details(int $actor,string $role,int $refund): array
    {
        $this->authorize($actor,$role,$refund);
        $q=$this->pdo->prepare('SELECT cri.*,p.product_name FROM cash_refund_items cri JOIN products p ON p.product_id=cri.product_id WHERE cri.refund_id=? ORDER BY cri.refund_item_id');$q->execute([$refund]);
        $lines=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($lines as &$line){
            $line['can_correct']=$role!=='admin';
            $actorColumn=isset(\SalesWorkflowService::getTableColumns($this->pdo,'users')['display_name'])?'display_name':'full_name';
            $h=$this->pdo->prepare('SELECT c.*,u.'.$actorColumn.' AS actor_name FROM refund_return_condition_corrections c JOIN users u ON u.user_id=c.actor_id WHERE refund_item_id=? ORDER BY version DESC');$h->execute([$line['refund_item_id']]);$line['corrections']=$h->fetchAll(PDO::FETCH_ASSOC);
            $line['version']=(int)($line['corrections'][0]['version']??0);$line['effective_condition']=$line['corrections'][0]['new_condition']??$line['disposition'];
            try { $this->assertNoLegacyStockReview($refund); $legacyClear=true; } catch (DomainException $e) { $legacyClear=false; }
            $line['eligible']=$legacyClear && in_array($line['selling_form']??'piece',['piece','pack'],true) && (($line['selling_form']??'piece')!=='pack' || (int)($line['pack_size']??0)>1) && in_array($line['effective_condition'],['damaged','restockable'],true);
        }return $lines;
    }
    public function correct(int $actor,string $role,int $item,string $target,string $reason,int $expectedVersion,string $token,bool $fitForSale): int
    {
        $reason=trim($reason);
        if(!in_array($target,['damaged','restockable'],true)||$reason===''||mb_strlen($reason)>255||!preg_match('/^[a-f0-9]{32,64}$/D',$token)) throw new InvalidArgumentException('Choose a condition and enter a short reason (up to 255 characters).');
        if($target==='restockable'&&!$fitForSale) throw new DomainException('Confirm the returned item is physically fit for sale.');
        if(function_exists('is_remote_request')&&is_remote_request())throw new DomainException('Refund corrections are unavailable during remote monitoring.');
        StoreWriteGate::begin($this->pdo);
        try {
            $q=$this->pdo->prepare('SELECT * FROM cash_refund_items WHERE refund_item_id=?'.$this->lock());$q->execute([$item]);$line=$q->fetch(PDO::FETCH_ASSOC);
            if(!$line)throw new DomainException('Returned item not found.');
            $this->pdo->prepare('SELECT refund_id FROM cash_refunds WHERE refund_id=?'.$this->lock())->execute([$line['refund_id']]);
            $this->authorize($actor,$role,(int)$line['refund_id'],true);
            $retry=$this->pdo->prepare('SELECT * FROM refund_return_condition_corrections WHERE request_token=?');$retry->execute([$token]);$saved=$retry->fetch(PDO::FETCH_ASSOC);
            if($saved){if((int)$saved['actor_id']!==$actor||(int)$saved['refund_item_id']!==$item||$saved['new_condition']!==$target||$saved['reason']!==$reason||(int)$saved['version']!==$expectedVersion+1)throw new DomainException('This save token belongs to another correction.');$this->pdo->commit();return (int)$saved['correction_id'];}
            $lines=$this->details($actor,$role,(int)$line['refund_id']);foreach($lines as $candidate)if((int)$candidate['refund_item_id']===$item)$line=$candidate;
            if(!$line['eligible'])throw new DomainException('This return requires Inventory Manager reconciliation.');
            if($line['version']!==$expectedVersion)throw new DomainException('The condition changed since your preview. Reopen the refund details.');
            if($line['effective_condition']===$target)throw new DomainException('The condition is unchanged. No correction was saved.');
            $this->assertNoLegacyStockReview((int)$line['refund_id']);
            (new \FiscalPeriodGuardService($this->pdo))->assertOpenNow('stock_movements','return condition correction');
            $movement=(new BatchInventoryLedger($this->pdo))->correctReturnedCondition($line,$target,$actor);
            $this->pdo->prepare('INSERT INTO refund_return_condition_corrections (refund_item_id,previous_condition,new_condition,quantity,selling_form,pack_size,reason,actor_id,stock_movement_id,request_token,version) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$item,$line['effective_condition'],$target,$line['quantity'],$line['selling_form']??'piece',$line['pack_size']??null,$reason,$actor,$movement,$token,$expectedVersion+1]);
            $id=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO activity_log (user_id,action,category,module,record_id,previous_value,new_value,ip_address) VALUES (?,?,?,?,?,?,?,?)')->execute([$actor,'Return condition corrected',AuditRecordCategory::STORE_OPERATION,'Cash Refunds',$line['refund_id'],json_encode(['condition'=>$line['effective_condition']]),json_encode(['correction_id'=>$id,'refund_item_id'=>$item,'condition'=>$target,'quantity'=>$line['quantity'],'reason'=>$reason,'stock_movement_id'=>$movement]),$_SERVER['REMOTE_ADDR']??'system']);
            $this->pdo->commit();StoreOperationsDashboardWorkspace::invalidateCache();return $id;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function assertNoLegacyStockReview(int $refund): void
    {
        $cols=\SalesWorkflowService::getTableColumns($this->pdo,'refund_correction_reviews');if(!$cols)return;
        $q=$this->pdo->prepare("SELECT 1 FROM refund_correction_reviews WHERE refund_id=? AND (correction_type IN ('stock_only','combined') OR stock_status<>'not_required') LIMIT 1".$this->lock());$q->execute([$refund]);
        if($q->fetchColumn())throw new DomainException('A linked stock correction review requires Inventory Manager reconciliation before changing this condition.');
    }
}
