<?php

function receiving_product_handoff($id): ?array
{
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) return null;
    $handoff = $_SESSION['receiving_product_handoffs'][$id] ?? null;
    if (!$handoff || $handoff['user'] !== (int)$_SESSION['user_id'] || $handoff['expires'] < time()) return null;
    return $handoff;
}

function receiving_product_handoff_owns_tab(array $handoff, $secret): bool
{
    return is_string($secret) && hash_equals($handoff['tab'], hash('sha256', $secret));
}

function receiving_product_return_url(string $id, array $handoff): string
{
    return app_url('components/report/stock_receiving.php') . '?' . http_build_query([
        'handoff' => $id,
        'pullout_id' => $handoff['pullout_id'] ?: null,
    ]);
}
