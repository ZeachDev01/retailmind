<?php
use App\Services\CashDenominationCounter;
?>
<form method="POST" id="cash-drawer-counter">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
    <input type="hidden" name="action" value="count">
    <input type="hidden" name="shift_id" value="<?= (int)$openShift['shift_id'] ?>">
    <div class="money-counter-groups">
        <?php foreach (CashDenominationCounter::DENOMINATIONS as $group => $denominations): ?>
            <fieldset class="money-counter-group">
                <legend><?= $group === 'Bills' ? 'Bills' : 'Coins' ?></legend>
                <div class="money-counter-head" aria-hidden="true"><span>Money</span><span>How many?</span><span>Total</span></div>
                <?php foreach ($denominations as $key => $cents): ?>
                    <?php $label = '₱' . number_format($cents / 100, $cents % 100 === 0 ? 0 : 2); ?>
                    <div class="money-counter-row" data-cents="<?= $cents ?>" data-group="<?= strtolower($group) ?>">
                        <label for="count-<?= $key ?>"><?= $label ?></label>
                        <div class="money-counter-stepper">
                            <button type="button" data-step="-1" aria-label="Fewer <?= $label ?> <?= strtolower($group) ?>">−</button>
                            <input type="number" id="count-<?= $key ?>" name="denominations[<?= $key ?>]" min="0" max="<?= CashDenominationCounter::MAX_QUANTITY ?>" step="1" value="0" inputmode="numeric" required aria-label="Number of <?= $label ?> <?= strtolower($group) ?>">
                            <button type="button" data-step="1" aria-label="More <?= $label ?> <?= strtolower($group) ?>">+</button>
                        </div>
                        <span class="money-counter-subtotal">₱0.00</span>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>
    </div>
    <div class="money-counter-totals">
        <div><span>Total bills</span><strong id="count-bills">₱0.00</strong></div>
        <div><span>Total coins</span><strong id="count-coins">₱0.00</strong></div>
        <div class="money-counter-total"><label for="actual-cash">Cash counted (₱)</label><input id="actual-cash" type="number" name="actual_cash" min="0" step="0.01" value="0.00" readonly aria-describedby="count-help"></div>
    </div>
    <div class="money-counter-actions">
        <button type="button" class="btn btn-secondary" id="reset-cash-count">Start over</button>
        <button type="submit" class="btn" <?= $unresolvedHeldSales ? 'disabled' : '' ?>>Review cash count</button>
    </div>
</form>
