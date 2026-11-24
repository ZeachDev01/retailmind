<?php
// Render multiple items to verify the shared history order without a database.
function format_display_datetime(string $value): string { return $value; }
$event = static fn(int $id, string $at, string $reason): array => [
    'correction_id'=>$id, 'created_at'=>$at, 'reason'=>$reason,
    'previous_condition'=>'damaged', 'new_condition'=>'restockable',
    'actor_name'=>'Cashier', 'quantity'=>1, 'selling_form'=>'piece', 'stock_movement_id'=>$id,
];
$line = static fn(string $name, array $events): array => [
    'refund_item_id'=>1, 'product_name'=>$name, 'quantity'=>1,
    'effective_condition'=>'restockable', 'eligible'=>true, 'can_correct'=>false, 'corrections'=>$events,
];
$conditionLines = [
    $line('Milk <pack>', [$event(1,'2026-11-24 10:00:00','Oldest')]),
    $line('Bread', [$event(3,'2026-11-24 11:00:00','Newest'), $event(2,'2026-11-24 11:00:00','Middle')]),
];
ob_start();
require __DIR__.'/../app/Views/refund_return_conditions.php';
$html=ob_get_clean();
$check=static function(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); };
$history=substr($html,strpos($html,'<section class="refund-condition-history"'));
$check(strrpos($html,'</article>') < strpos($html,'<section class="refund-condition-history"'),'History follows every returned item.');
$check(substr_count($history,'<li>')===3,'Every correction appears in one list.');
$check(strpos($history,'Newest') < strpos($history,'Middle') && strpos($history,'Middle') < strpos($history,'Oldest'),'Newest corrections appear first, including equal timestamps.');
$check(str_contains($history,'Milk &lt;pack&gt;') && str_contains($history,'Bread'),'History identifies and escapes both products.');
$check(!str_contains($html,'<form'),'Read-only viewers receive no correction controls.');
echo "Refund condition combined history: passed.\n";
