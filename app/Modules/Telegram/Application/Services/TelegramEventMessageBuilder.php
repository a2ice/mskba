<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Models\Event;

final class TelegramEventMessageBuilder
{
    public function text(Event $event): string
    {
        $timezone = $event->venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
        $startsAt = $event->starts_at->setTimezone($timezone);
        $endsAt = $event->ends_at->setTimezone($timezone);
        $participants = $event->participants()
            ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
            ->count();
        $capacity = $event->max_participants === null
            ? (string) $participants
            : "{$participants}/{$event->max_participants}";

        $lines = [
            '🏀 <b>'.$this->escape($event->title).'</b>',
            $this->escape($event->type->label()),
            '',
            '📍 '.$this->escape($event->venue->name),
            '🗓 '.$startsAt->format('d.m.Y H:i').'–'.$endsAt->format('H:i').' (МСК)',
            '👥 Участники: '.$capacity,
        ];

        if ($event->status === EventStatusEnum::CANCELLED) {
            $lines[] = '';
            $lines[] = '🚫 <b>Мероприятие отменено</b>';
        } elseif ($event->status === EventStatusEnum::COMPLETED) {
            $lines[] = '';
            $lines[] = '✅ <b>Мероприятие состоялось</b>';
        } elseif ($event->starts_at->lessThanOrEqualTo(now())) {
            $lines[] = '';
            $lines[] = '⏱ <b>Запись завершена</b>';
        }

        return implode("\n", $lines);
    }

    /** @return array<string, array<int, array<int, array<string, string>>>> */
    public function replyMarkup(Event $event): array
    {
        $rows = [];

        if ($event->status === EventStatusEnum::PUBLISHED && $event->starts_at->isFuture()) {
            $rows[] = [
                [
                    'text' => '✅ Пойду',
                    'callback_data' => "event:{$event->id}:join",
                ],
                [
                    'text' => '❌ Не пойду',
                    'callback_data' => "event:{$event->id}:leave",
                ],
            ];
        }

        $rows[] = [[
            'text' => '🏀 Открыть мероприятие',
            'url' => route('events.show', $event->routeIdentifier()),
        ]];

        return ['inline_keyboard' => $rows];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
