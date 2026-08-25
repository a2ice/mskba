<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;

final class TelegramVenueRentalMessageBuilder
{
    public function text(VenueRentalCoordination $coordination): string
    {
        $timezone = $coordination->venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
        $startsAt = $coordination->starts_at->setTimezone($timezone);
        $endsAt = $coordination->ends_at->setTimezone($timezone);
        $participants = $coordination->participants->whereNull('left_at')->count();
        $slotStatus = $coordination->booking?->status->occupiesVenue()
            ? '✅ '.$coordination->booking->status->label()
            : '⚠️ Время ещё не забронировано';

        return implode("\n", [
            '🏀 <b>'.$this->escape($coordination->title).'</b>',
            '📍 '.$this->escape($coordination->venue->name),
            '🗓 '.$startsAt->format('d.m.Y H:i').'–'.$endsAt->format($startsAt->isSameDay($endsAt) ? 'H:i' : 'd.m.Y H:i'),
            '👥 Заинтересованы: '.$participants,
            $slotStatus,
            $coordination->status === VenueRentalCoordinationStatus::CLOSED ? 'Сбор участников закрыт.' : $coordination->status->label().'.',
        ]);
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    public function replyMarkup(VenueRentalCoordination $coordination): array
    {
        $rows = [];
        if ($coordination->status === VenueRentalCoordinationStatus::OPEN) {
            $rows[] = [
                ['text' => '✅ Присоединиться', 'callback_data' => "rentalcoord:{$coordination->id}:join"],
                ['text' => '❌ Покинуть', 'callback_data' => "rentalcoord:{$coordination->id}:leave"],
            ];
        }
        $rows[] = [[
            'text' => '🏀 Открыть сбор',
            'url' => $this->coordinationUrl($coordination),
        ]];

        return ['inline_keyboard' => $rows];
    }

    private function coordinationUrl(VenueRentalCoordination $coordination): string
    {
        $botUsername = ltrim(trim((string) config('telegram.bot_username')), '@');
        if ($botUsername === '') {
            return route('venue-rental-coordinations.show', $coordination);
        }

        return sprintf(
            'https://t.me/%s?startapp=%s',
            rawurlencode($botUsername),
            rawurlencode('rental_coordination_'.$coordination->public_id),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
