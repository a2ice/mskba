<?php

namespace App\Modules\Portal\Application\DTO;

final readonly class SiteSummaryDTO
{
    public function __construct(
        public int $todayEvents,
        public int $onlineUsers,
        public int $totalUsers,
    ) {}

    public function todayEventsText(): string
    {
        if ($this->todayEvents === 0) {
            return 'Новая игра';
        }

        $modulo100 = $this->todayEvents % 100;
        $modulo10 = $this->todayEvents % 10;

        $noun = match (true) {
            $modulo100 >= 11 && $modulo100 <= 14 => 'игр',
            $modulo10 === 1 => 'игра',
            $modulo10 >= 2 && $modulo10 <= 4 => 'игры',
            default => 'игр',
        };

        return "{$this->todayEvents} {$noun} сегодня";
    }

    /** @return array{today_events: int, today_events_text: string, online_users: int, total_users: int} */
    public function toArray(): array
    {
        return [
            'today_events' => $this->todayEvents,
            'today_events_text' => $this->todayEventsText(),
            'online_users' => $this->onlineUsers,
            'total_users' => $this->totalUsers,
        ];
    }
}
