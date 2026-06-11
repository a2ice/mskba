<?php

namespace App\Modules\Location\Application\DTO;

final readonly class MetroStationOptionDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $lineName,
        public ?string $lineColor,
    ) {}
}
