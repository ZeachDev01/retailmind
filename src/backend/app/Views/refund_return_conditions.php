<?php
/** Operational details only: never part of the preserved printed receipt. */
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$history=[];
foreach($conditionLines as $line) {
    foreach($line['corrections'] as $correction) {
        $history[]=$correction+['product_name'=>$line['product_name']];
    }
}
usort($history,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at']) ?: ((int)$b['correction_id'] <=> (int)$a['correction_id']));
?>
<section class="refund-condition-lines" aria-label="Returned items">
<h3>Returned items</h3>
<?php foreach($conditionLines as $line): $item=(int)$line['refund_item_id'];$condition=$line['effective_condition']; ?>
<article class="refund-condition-line">
<h4 class="refund-condition-product<?= $line['eligible'] && $line['can_correct']?' refund-condition-product--editable':'' ?>"><?= $escape($line['product_name']) ?></h4>
<div class="refund-condition-meta">
<p class="refund-condition-quantity"><?= (int)$line['quantity'] ?> <?= ($line['selling_form']??'piece')==='pack'?((int)$line['quantity']===1?'pack':'packs'):((int)$line['quantity']===1?'piece':'pieces') ?><?= ($line['selling_form']??'piece')==='pack'?' ('.(int)$line['pack_size'].' pieces per pack)':'' ?></p>
<p class="refund-condition-current">Effective condition: <strong class="refund-condition-status refund-condition-status--<?= $escape($condition) ?>"><?= $escape(ucfirst($condition)) ?></strong></p>
</div>
<?php if($line['eligible'] && $line['can_correct']): ?>

<details class="refund-condition-edit"><summary aria-label="Correct condition for <?= $escape($line['product_name']) ?>">Correct condition</summary>
<form method="post" class="refund-condition-form refund-form" data-product="<?= $escape($line['product_name']) ?>" data-quantity="<?= (int)$line['quantity'] ?>" data-current="<?= $escape($condition) ?>" data-selling-form="<?= $escape($line['selling_form']??'piece') ?>">
<input type="hidden" name="csrf_token" value="<?= $escape(generate_csrf_token()) ?>">
<input type="hidden" name="action" value="correct_condition">
<input type="hidden" name="refund_item_id" value="<?= $item ?>">
<input type="hidden" name="expected_version" value="<?= (int)$line['version'] ?>">
<input type="hidden" name="request_token" value="<?= bin2hex(random_bytes(24)) ?>">
<div class="refund-condition-field">
<label for="condition-<?= $item ?>">New condition</label>
<select id="condition-<?= $item ?>" name="new_condition" required><option value="">Choose condition</option><option value="damaged">Damaged</option><option value="restockable">Restockable</option></select>
</div>
<div class="refund-condition-field">
<label for="condition-reason-<?= $item ?>">Reason</label>
<input id="condition-reason-<?= $item ?>" name="correction_reason" maxlength="255" required>
</div>
<label class="refund-condition-confirm"><input type="checkbox" name="fit_for_sale" value="1"><span><?= ($line['selling_form']??'piece')==='pack'?'I inspected every returned pack and confirm it is sealed, complete and fit for sale.':'I inspected the returned item and confirm it is fit for sale.' ?></span></label>
<p class="refund-condition-preview" aria-live="polite">Choose the correct condition to preview the stock change.</p>
<button type="submit" class="btn btn-primary">Save correction</button>
</form></details>
<?php elseif(!$line['eligible']): ?><p>Ask the Inventory Manager to reconcile this return.</p><?php endif; ?>
 </article>
<?php endforeach; ?>
<section class="refund-condition-history" aria-label="Condition history">
<h3>Condition history <span class="refund-condition-history-count"><?= count($history) ?></span></h3>
<?php if($history): ?>
<ul class="refund-condition-events">
<?php foreach($history as $correction): ?>
<li>
<h4 class="refund-condition-event-product"><?= $escape($correction['product_name']) ?></h4>
<p class="refund-condition-change"><?= $escape(ucfirst($correction['previous_condition']).' → '.ucfirst($correction['new_condition'])) ?></p>
<p class="refund-condition-attribution"><?= $escape($correction['actor_name']) ?> · <time><?= $escape(format_display_datetime((string)$correction['created_at'])) ?></time></p>
<p><?= $escape($correction['reason']) ?></p>
<p class="refund-condition-movement"><?= (int)$correction['quantity'] ?> <?= $escape($correction['selling_form'].((int)$correction['quantity']===1?'':'s')) ?><?= $correction['selling_form']==='pack'?' ('.(int)$correction['pack_size'].' pieces per pack)':'' ?> · Stock movement #<?= (int)$correction['stock_movement_id'] ?></p>
</li>
<?php endforeach; ?>
</ul>
<?php else: ?><p class="refund-condition-attribution">No condition corrections yet.</p>
<?php endif; ?>
</section>
</section>
