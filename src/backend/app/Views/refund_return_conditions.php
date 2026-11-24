<?php
/** Operational details only: never part of the preserved printed receipt. */
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<section class="refund-condition-lines" aria-label="Returned items">
<h3>Returned items</h3>
<?php foreach($conditionLines as $line): $item=(int)$line['refund_item_id'];$condition=$line['effective_condition']; ?>
<article class="refund-condition-line">
<strong><?= $escape($line['product_name']) ?></strong>
<p><?= (int)$line['quantity'] ?> <?= ($line['selling_form']??'piece')==='pack'?((int)$line['quantity']===1?'pack':'packs'):((int)$line['quantity']===1?'piece':'pieces') ?><?= ($line['selling_form']??'piece')==='pack'?' ('.(int)$line['pack_size'].' pieces per pack)':'' ?> · Effective condition: <strong><?= $escape(ucfirst($condition)) ?></strong></p>
<?php if($line['eligible'] && $line['can_correct']): ?>

<details><summary>Correct condition</summary>
<form method="post" class="refund-condition-form refund-form" data-product="<?= $escape($line['product_name']) ?>" data-quantity="<?= (int)$line['quantity'] ?>" data-current="<?= $escape($condition) ?>" data-selling-form="<?= $escape($line['selling_form']??'piece') ?>">
<input type="hidden" name="csrf_token" value="<?= $escape(generate_csrf_token()) ?>">
<input type="hidden" name="action" value="correct_condition">
<input type="hidden" name="refund_item_id" value="<?= $item ?>">
<input type="hidden" name="expected_version" value="<?= (int)$line['version'] ?>">
<input type="hidden" name="request_token" value="<?= bin2hex(random_bytes(24)) ?>">
<label for="condition-<?= $item ?>">New condition</label>
<select id="condition-<?= $item ?>" name="new_condition" required><option value="">Choose condition</option><option value="damaged">Damaged</option><option value="restockable">Restockable</option></select>
<label for="condition-reason-<?= $item ?>">Reason</label>
<input id="condition-reason-<?= $item ?>" name="correction_reason" maxlength="255" required>
<label class="refund-condition-confirm"><input type="checkbox" name="fit_for_sale" value="1"><span><?= ($line['selling_form']??'piece')==='pack'?'I inspected every returned pack and confirm it is sealed, complete and fit for sale.':'I inspected the returned item and confirm it is fit for sale.' ?></span></label>
<p class="refund-condition-preview" aria-live="polite">Choose the correct condition to preview the stock change.</p>
<button type="submit" class="btn btn-primary">Save correction</button>
</form></details>
<?php elseif(!$line['eligible']): ?><p>Ask the Inventory Manager to reconcile this return.</p><?php endif; ?>
<?php if($line['corrections']): ?><h4>Condition history</h4><ul><?php foreach($line['corrections'] as $correction): ?><li><?= $escape(ucfirst($correction['previous_condition']).' → '.ucfirst($correction['new_condition'])) ?> — <?= $escape($correction['actor_name']) ?> · <?= $escape($correction['created_at']) ?><br><?= $escape($correction['reason']) ?><br><?= (int)$correction['quantity'] ?> <?= $escape($correction['selling_form'].((int)$correction['quantity']===1?'':'s')) ?><?= $correction['selling_form']==='pack'?' ('.(int)$correction['pack_size'].' pieces per pack)':'' ?> � Stock movement #<?= (int)$correction['stock_movement_id'] ?></li><?php endforeach; ?></ul><?php endif; ?>
</article>
<?php endforeach; ?>
</section>
