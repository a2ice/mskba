<?php

namespace App\Modules\Dashboard\Application\Data;

final readonly class DashboardWidgetData
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public int $total,
        public array $items = [],
    ) {}

    /** @return array{total: int, items: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['total' => $this->total, 'items' => $this->items];
    }
}
