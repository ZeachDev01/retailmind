<?php
require __DIR__ . '/theme_fixture.php';
if (($argv[1] ?? '') !== 'create') exit;
$pdo->exec("INSERT INTO notifications (user_id,type,title,message,reference_type,reference_id,workspace)
    VALUES (1,'adjustment','Stock Issue needs review','Inspect this Stock Issue without changing stock.','stock_issue',1,'admin')");
