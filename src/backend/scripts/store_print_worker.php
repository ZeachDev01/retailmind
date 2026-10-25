<?php
// No HTTP listener: the worker reads only this installation's authenticated queue.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../bootstrap/app.php';
$deployment = (string)env('STORE_PRINT_DEPLOYMENT', '');
$printer = (string)env('STORE_PRINT_PRINTER', '');
if (PHP_OS_FAMILY !== 'Windows' || $deployment === '' || strlen($deployment) > 100 || $printer === '') {
    fwrite(STDERR, "Configure STORE_PRINT_DEPLOYMENT and STORE_PRINT_PRINTER on the Windows Store PC.\n"); exit(1);
}
$queue = new App\Receipts\StorePrintService(App\Core\Database::connection(), $deployment);
while ($job = $queue->claim()) {
    $file = tempnam(sys_get_temp_dir(), 'rm-print-');
    if ($file === false) { $queue->finish($job['job_id'], 'failed'); continue; }
    try {
        if (file_put_contents($file, $job['payload']) !== strlen($job['payload'])) throw new RuntimeException('Could not stage print job.');
        $process = proc_open(['powershell.exe', '-NoProfile', '-NonInteractive', '-WindowStyle', 'Hidden', '-File', __DIR__ . '/store_print_spool.ps1', '-PrinterName', $printer, '-PayloadPath', $file],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) { $queue->finish($job['job_id'], 'failed'); continue; }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($process);
        $queue->finish($job['job_id'], $code === 0 && trim($output) === 'SPOOLED' ? 'spooled' : 'uncertain');
        if ($code !== 0) error_log('Store receipt spool failed: ' . $error);
    } catch (Throwable $error) {
        error_log((string)$error);
        $queue->finish($job['job_id'], 'uncertain');
    } finally { unlink($file); }
}
