<?php
// app/Services/StockConflictException.php

class StockConflictException extends DomainException
{
    private array $conflicts;

    public function __construct(array $conflicts, string $message = '')
    {
        $this->conflicts = $conflicts;
        if ($message === '') {
            $first = reset($conflicts);
            $name = !empty($first['product_name']) ? $first['product_name'] : ('product #' . ($first['product_id'] ?? ''));
            $avail = $first['available_stock'] ?? 0;
            $message = "Not enough stock for {$name} (only {$avail} left).";
        }
        parent::__construct($message, 409);
    }

    public function getConflicts(): array
    {
        return $this->conflicts;
    }
}
