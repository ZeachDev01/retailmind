<?php
// Friendly alerts 03 (ticket #55): Cashier operation alerts.
//
// Contract for the six Cashier surfaces in shop mode: checkout, stock report
// save, shift, receipt load, camera start, and offline. Each shows its
// aligned easy sentence (what happened, what to do next, who to tell if it
// repeats) with the correct red or yellow kind; no raw database text,
// String(error) dump, or getMessage output reaches the Cashier; full
// exception text still goes to server logs at the page boundary through
// OperatorAlert; and with debug on the alert shows the easy line plus the
// grey tech line through the shared wrapper.

require_once __DIR__ . '/../bootstrap/app.php';

use App\Support\OperatorAlert;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $root = dirname(__DIR__, 3);
    $read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);

    $pos = $read('src/frontend/components/cashier/pos.php');
    $stock = $read('src/frontend/components/cashier/stock_issues.php');
    $shifts = $read('src/frontend/components/cashier/shifts.php');
    $sales = $read('src/frontend/components/invoice/sales.php');
    $ui = $read('src/frontend/assets/js/ui.js');
    $held = $read('src/frontend/components/barcodeScanner/apiScanner/held_sales.php');

    $assert($pos !== '', 'pos.php must be readable');
    $assert($stock !== '', 'cashier/stock_issues.php must be readable');
    $assert($shifts !== '', 'cashier/shifts.php must be readable');
    $assert($sales !== '', 'sales.php must be readable');
    $assert($ui !== '', 'ui.js must be readable');
    $assert($held !== '', 'held_sales.php must be readable');

    // --- AC1: each failure shows its aligned easy sentence ---
    $easyLines = [
        'checkout' => ['The sale could not finish. Please try again. Tell your Administrator if this keeps happening.', $pos],
        'stock report save' => ['The stock report could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.', $stock],
        'shift' => ['The shift change could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.', $shifts],
        'receipt load' => ['The receipt could not be shown. Please try again. Tell your Administrator if this keeps happening.', $sales],
        'camera start' => ['The camera could not start. Check your camera permission, or enter the code manually to continue. Tell your Administrator if this keeps happening.', $pos],
        'offline' => ['You are offline. Check your connection and try again. Tell your Administrator if this keeps happening.', $ui],
    ];
    foreach ($easyLines as $name => [$line, $source]) {
        $assert(str_contains($source, $line), ucfirst($name) . ' failure must show its aligned easy sentence');
        $assert(
            str_contains($line, 'Tell your Administrator'),
            ucfirst($name) . ' easy sentence must say who to tell if it repeats'
        );
        $assert(
            !OperatorAlert::isTechnicalMessage($line),
            ucfirst($name) . ' easy sentence must stay free of technical words, got: ' . $line
        );
        foreach (['SQLSTATE', 'Stack trace', 'getMessage', '.php:'] as $needle) {
            $assert(
                !str_contains($line, $needle),
                ucfirst($name) . ' easy sentence must not contain ' . $needle
            );
        }
    }

    // --- AC1: correct red or yellow kind per surface ---
    $assert(
        (bool)preg_match('/<div class="pos-alert error".*\$checkout_error/', $pos),
        'Checkout failure must render in the red Unable to continue container'
    );
    $assert(
        (bool)preg_match('/<div class="message error">.*\$error/', $stock),
        'Stock report save failure must render in the red Unable to continue container'
    );
    $assert(
        (bool)preg_match('/<div class="message error">.*\$error/', $shifts),
        'Shift failure must render in the red Unable to continue container'
    );
    $assert(
        str_contains($sales, "RetailMindUI.toast(easy + detail, 'error')"),
        'Receipt load failure must raise a red Unable to continue Operator Alert'
    );
    $assert(
        str_contains($pos, "RetailMindUI.toast(easy + tech, 'error')"),
        'Camera start failure must raise a red Unable to continue Operator Alert'
    );
    $assert(
        (bool)preg_match(
            '/RM\.toast\(\s*"You are offline\. Check your connection and try again\. Tell your Administrator if this keeps happening\."\s*,\s*"warning"\s*,\s*"Attention"/',
            $ui
        ),
        'Offline must raise a yellow Attention Operator Alert'
    );
    $assert(
        !str_contains($ui, 'Internet connection was lost'),
        'The old offline toast wording must be replaced by the aligned easy sentence'
    );
    $assert((bool)preg_match('/error:\s*"Unable to continue"/', $ui), 'Wrapper error titles must stay Unable to continue');
    $assert((bool)preg_match('/warning:\s*"Attention"/', $ui), 'Wrapper warning titles must stay Attention');

    // --- AC2: no raw database text, String(error) dump, or getMessage output ---
    $assert(
        !str_contains($pos, 'escapeHtml(error)'),
        'Camera start failure must not dump the raw error into the scanner status'
    );
    $assert(
        (bool)preg_match('/isDebug\(\)[^;]{0,160}String\((?:error|detail)\)/', $pos),
        'POS camera tech detail must be gated behind the debug flag'
    );
    $assert(
        (bool)preg_match('/isDebug\(\)[^;]{0,160}String\(error\)/', $sales),
        'Receipt tech detail must be gated behind the debug flag'
    );
    foreach (['pos' => $pos, 'stock report page' => $stock, 'shifts page' => $shifts] as $name => $source) {
        $assert(
            !str_contains($source, 'getMessage('),
            ucfirst($name) . ' must not surface getMessage output to the Cashier'
        );
    }
    foreach (explode("\n", $sales) as $index => $line) {
        if (str_contains($line, 'getMessage(')) {
            $assert(
                str_contains($line, 'error_log'),
                'sales.php line ' . ($index + 1) . ' may only use getMessage inside server logging'
            );
        }
    }
    $assert(
        str_contains($pos, 'sanitizeAlert'),
        'POS cart messages must run through the shared alert safety net'
    );
    $heldLine = 'The held sale could not be completed. Check your connection and try again. Tell your Administrator if this keeps happening.';
    $assert(
        str_contains($held, 'OperatorAlert::message'),
        'Held sale failures must build the friendly message at the boundary so the full exception is logged'
    );
    $assert(
        !str_contains($held, 'getMessage'),
        'Held sale JSON must not echo raw getMessage output to the Cashier'
    );
    $assert(
        str_contains($held, $heldLine),
        'Held sale failures must show their aligned easy sentence'
    );
    $assert(
        str_contains($pos, $heldLine),
        'The POS held sale client fallback must show the same aligned easy sentence'
    );
    $assert(
        str_contains($pos, "console.error('Held sale"),
        'Held sale failures must keep the full detail on the developer console'
    );

    // --- AC3: full exception text still reaches server logs at the boundary ---
    foreach (['checkout' => $pos, 'stock report save' => $stock, 'shift' => $shifts] as $name => $source) {
        $assert(
            str_contains($source, 'OperatorAlert::message'),
            ucfirst($name) . ' must build the friendly message at the boundary so the full exception is logged'
        );
        $assert(
            str_contains($source, 'catch (Throwable'),
            ucfirst($name) . ' must catch every failure at the boundary'
        );
        $assert(
            str_contains($source, 'log_activity'),
            ucfirst($name) . ' must keep its existing Protected Audit Record trail'
        );
    }

    // --- AC4: debug on shows the easy line plus the grey tech line ---
    $assert(
        str_contains($pos, 'RetailMindUI.isDebug()'),
        'Camera failure must gate the grey tech line on the debug flag'
    );
    $assert(
        str_contains($sales, 'RetailMindUI.isDebug()'),
        'Receipt failure must gate the grey tech line on the debug flag'
    );
    $assert(
        str_contains($pos, "console.error('Camera start failed:'"),
        'Camera failure must keep the full detail on the developer console'
    );
    $assert(
        str_contains($sales, "console.error('Error fetching receipt:'"),
        'Receipt failure must keep the full detail on the developer console'
    );
    $assert(str_contains($ui, 'rm-toast-tech'), 'The debug tech line must render grey in toasts');
    $notifyStart = strpos($ui, 'function initServerNotifications');
    $notifyEnd = strpos($ui, 'function initCommandPalette');
    $assert($notifyStart !== false && $notifyEnd !== false && $notifyEnd > $notifyStart, 'initServerNotifications must exist');
    if ($notifyStart !== false && $notifyEnd !== false && $notifyEnd > $notifyStart) {
        $serverNotify = substr($ui, $notifyStart, $notifyEnd - $notifyStart);
        $assert(
            str_contains($serverNotify, 'split(/\r?\n/)'),
            'Server flash nodes must keep the debug tech line on its own line for the wrapper to split'
        );
        $assert(
            str_contains($serverNotify, 'join("\n")'),
            'Server flash nodes must be rejoined with real newlines'
        );
    }
} catch (Throwable $exception) {
    $failures[] = 'Cashier alert contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Cashier alert contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Cashier alert contract: passed\n";
