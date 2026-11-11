<?php
// Keep contract fixtures off the developer's real forecasting diagnostics log.
// `forecasting_diagnostics.json` preserves database outages; a stale entry left in
// the working tree otherwise keeps a forecasting failure signal alive and makes
// cold-start and recovery assertions fail for the wrong reason.
//
// shortcut: a killed process strands the renamed .contract-bak log. Move it back
// by hand if that happens; add startup recovery only if it bites.
function isolate_forecasting_diagnostics(): void
{
    $real = dirname(__DIR__, 2) . '/storage/logs/forecasting_diagnostics.json';
    if (!is_file($real)) {
        foreach (glob($real . '.*.contract-bak') ?: [] as $stranded) {
            fwrite(STDERR, "Stranded forecasting diagnostics backup from an earlier run: {$stranded}\n");
        }
        return;
    }
    $backup = $real . '.' . getmypid() . '.contract-bak';
    if (!@rename($real, $backup)) {
        throw new RuntimeException('Cannot isolate forecasting diagnostics; close the process holding ' . $real);
    }
    register_shutdown_function(static function () use ($real, $backup): void {
        // A real worker may have logged an outage while the test ran. Keep both files.
        if (is_file($real)) {
            fwrite(STDERR, "Forecasting diagnostics log was recreated during the test; original kept at {$backup}\n");
            return;
        }
        @rename($backup, $real);
    });
}
