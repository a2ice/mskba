<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use Illuminate\Support\Str;

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
        $description = trim((string) $event->description);

        $lines = [
            '🏀 <b>'.$this->escape($this->title($event)).'</b>',
            'Тип активности: '.$this->escape($event->type->label()),
            'Описание: '.$this->escape(
                $description === '' ? '—' : Str::limit($description, 1000),
            ),
            '',
            '📍 '.$this->escape($event->venue->name),
            '🗓 '.$startsAt->format('d.m.Y H:i').'–'.$endsAt->format('H:i').' (МСК)',
            '👥 Участники: '.$capacity,
        ];

        $responsibles = $event->participants
            ->filter(fn ($participant) => $participant->status === EventParticipantStatusEnum::CONFIRMED
                && $participant->confirmation_version === $event->participation_confirmation_version
                && $participant->responsibility_status === EventResponsibilityStatusEnum::ACCEPTED)
            ->map(fn ($participant): string => $this->userName($participant->user))
            ->values();
        if ($responsibles->isNotEmpty()) {
            $lines[] = '🛡 Ответственные: '.$this->escape($responsibles->join(', '));
        }

        if ($event->type !== EventTypeEnum::GAME && $event->games->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '🎮 <b>Мини-игры</b>';
            foreach ($event->games as $game) {
                $sides = $game->sides->keyBy('slot');
                $sideA = $sides->get('A');
                $sideB = $sides->get('B');
                $score = $game->status === GameStatusEnum::COMPLETED
                    && $sideA?->score !== null && $sideB?->score !== null
                    ? "{$sideA->score}:{$sideB->score}"
                    : '—:—';
                $lines[] = '• <b>'.$this->escape($game->title ?: 'Игра #'.$game->id).'</b>';
                $lines[] = $this->escape($sideA?->display_name ?: 'Команда A')
                    .' <b>'.$score.'</b> '
                    .$this->escape($sideB?->display_name ?: 'Команда B');
            }
        }

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
            'url' => $this->eventUrl($event),
        ]];

        return ['inline_keyboard' => $rows];
    }

    private function title(Event $event): string
    {
        return match ($event->type) {
            EventTypeEnum::GAME,
            EventTypeEnum::GAME_TRAINING => 'Играем на '.$event->venue->name,
            default => $event->title,
        };
    }

    private function eventUrl(Event $event): string
    {
        $botUsername = ltrim(trim((string) config('telegram.bot_username')), '@');

        if ($botUsername === '') {
            return route('events.show', $event->routeIdentifier());
        }

        return sprintf(
            'https://t.me/%s?startapp=%s',
            rawurlencode($botUsername),
            rawurlencode("event_{$event->id}"),
        );
    }

    private function userName($user): string
    {
        $profileName = trim(implode(' ', array_filter([
            $user?->profile?->first_name,
            $user?->profile?->last_name,
        ])));

        return $profileName !== ''
            ? $profileName
            : ($user?->username ?: 'Пользователь #'.$user?->id);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
