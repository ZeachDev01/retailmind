<?php
// Reuse the running refund handler and its disposable database.
$shifts->addDrawerMovement(1, 'cashier', 'cash_in', 250, 'additional_float');
$pdo->exec("INSERT INTO products(product_id,sku,barcode,product_name,unit_price,cost_price,branch_id)
    VALUES(30,'RETURNED','RETURNED','Returned item',25,10,1),(31,'KEPT','KEPT','Kept item',10,5,1)");
$pdo->exec('INSERT INTO inventory(product_id,quantity_on_hand) VALUES(30,5),(31,5)');
foreach ([80 => 30, 58 => 40] as $width => $saleId) {
    $partialSaleId = $saleId + 1;
    $pdo->exec("UPDATE registers SET paper_width_mm='{$width}' WHERE register_id=1");
    $pdo->exec("INSERT INTO sales(sale_id,cashier_id,shift_id,total_amount,payment_method)
        VALUES({$saleId},1,1,25,'cash'),({$partialSaleId},1,1,85,'cash')");
    $pdo->exec("INSERT INTO sale_items(sale_item_id,sale_id,product_id,quantity,unit_price,subtotal)
        VALUES({$saleId},{$saleId},30,1,25,25),({$partialSaleId},{$partialSaleId},30,3,25,75),
        (".($saleId + 2).",{$partialSaleId},31,1,10,10)");
    $pdo->beginTransaction();
    $details = new \App\Receipts\ReceiptDetailsService($pdo);
    $details->preserveSale($saleId);
    $details->preserveSale($partialSaleId);
    $pdo->commit();
    $before = $snapshot();
    $process = proc_open(['node', __DIR__.'/../refund_completion_browser_test.js', '--browser',
        'http://'.$address, (string)$saleId, (string)$width],
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $browserPipes);
    $assert(is_resource($process), 'Refund completion browser starts');
    fclose($browserPipes[0]);
    $output = stream_get_contents($browserPipes[1]);
    $error = stream_get_contents($browserPipes[2]);
    fclose($browserPipes[1]);
    fclose($browserPipes[2]);
    $assert(proc_close($process) === 0, 'Refund completion browser: '.$output.$error);
    $after = $snapshot();
    foreach (['sales','sale_items','sale_receipt_details','stock_movements','inventory','product_batches'] as $table) {
        $assert($after[$table] === $before[$table], 'Completion and reprints preserve '.$table);
    }
    foreach (['cash_refunds','cash_refund_items','refund_receipt_details'] as $table) {
        $assert(count($after[$table]) === count($before[$table]) + 3,
            'Full and two partial refunds append exactly three '.$table.' rows');
        $assert(array_slice($after[$table], 0, count($before[$table])) === $before[$table],
            'Completion and reprints preserve existing '.$table);
    }
    $assert(count($after['activity_log']) === count($before['activity_log']) + 3,
        'Only the three completed refunds create audit records');
    $assert($service->refundableAmount($saleId) === 0.0 && $service->refundableAmount($partialSaleId) === 10.0,
        'Full refund completes the sale; partial refunds leave the kept item');
    echo $output;
}
