<?php

namespace App\Attention;

final class AttentionRefreshResult
{
    /**
     * @param AttentionItem[] $items
     * @param AttentionItem[] $newItems
     */
    public function __construct(
        private array $items,
        private array $newItems
    ) {
    }

    /** @return AttentionItem[] */
    public function items(): array
    {
        return $this->items;
    }

    /** @return AttentionItem[] */
    public function newItems(): array
    {
        return $this->newItems;
    }

    public function dashboardItems(): array
    {
        return array_map(static fn(AttentionItem $item): array => $item->toArray(), $this->items);
    }
}
