<?php

namespace App\Modules\Dashboard\Application\Data;

final readonly class DashboardWidgetData
{
    /** @param list<array<string, mixed>> $items
     *  @param array<string, mixed> $summary
     */
    public function __construct(
        public int $total,
        public array $items = [],
        public array $summary = [],
    ) {}

    /** @return array{total: int, items: list<array<string, mixed>>, summary?: array<string, mixed>} */
    public function toArray(): array
    {
        $data = ['total' => $this->total, 'items' => $this->items];
        if ($this->summary !== []) {
            $data['summary'] = $this->summary;
        }

        return $data;
    }
}
