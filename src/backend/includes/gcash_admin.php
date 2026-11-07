<?php
// Presentation helpers shared by the GCash Admin Overview page and its fragments.

if (!function_exists('gcash_admin_e')) {
    function gcash_admin_e(mixed $val): string
    {
        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('gcash_admin_money')) {
    function gcash_admin_money(mixed $val): string
    {
        if ($val === null || $val === '') return '—';
        return ((float)$val < 0 ? '−' : '') . '₱' . number_format(abs((float)$val), 2);
    }
}
