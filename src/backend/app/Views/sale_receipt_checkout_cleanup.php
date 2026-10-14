<?php if ($checkoutCompleted): ?>
<script>
try {
    const key = <?= json_encode('retailmind.checkout.' . (int)$_SESSION['user_id']) ?>;
    const pending = JSON.parse(localStorage.getItem(key) || 'null');
    const matchesAttempt = pending && pending.id === <?= json_encode($completedAttempt) ?>;
    if (matchesAttempt || (!pending && <?= json_encode($announcePayment) ?>)) {
        sessionStorage.removeItem('pos_cart');
        sessionStorage.removeItem('retailmind.cart-workspace');
        if (matchesAttempt) localStorage.removeItem(key);
    }
} catch (error) { /* Returning to POS retries recovery if browser storage is unavailable. */ }
</script>
<?php endif; ?>
