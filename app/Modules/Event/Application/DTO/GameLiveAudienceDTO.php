<?php

namespace App\Modules\Event\Application\DTO;

final readonly class GameLiveAudienceDTO
{
    public function __construct(
        public int $authenticated,
        public int $total,
    ) {}

    /** @return array{authenticated: int, total: int} */
    public function toArray(): array
    {
        return [
            'authenticated' => $this->authenticated,
            'total' => $this->total,
        ];
    }
}
