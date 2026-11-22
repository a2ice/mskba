<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;

final class EventTimeComment
{
    public const DEFAULT_COMMENT = 'Дата и время мероприятия предварительные и могут измениться. Организатор уточнит их дополнительно.';

    public static function rules(): array
    {
        return [
            'time_comment' => ['nullable', 'string', 'max:2000'],
            'time_confirmed' => ['sometimes', 'boolean'],
            'save_time_comment_default' => ['sometimes', 'boolean'],
        ];
    }

    public static function defaultComment(?User $user): string
    {
        return $user?->canonical()->default_event_time_comment ?? self::DEFAULT_COMMENT;
    }

    public function attributes(Actor $actor, array $data, ?Event $event = null): array
    {
        $attributes = [];
        if (array_key_exists('time_confirmed', $data)) {
            $attributes['time_confirmed'] = filter_var($data['time_confirmed'], FILTER_VALIDATE_BOOLEAN);
        }
        if (! array_key_exists('time_comment', $data) && $event !== null) {
            return $attributes;
        }

        $comment = array_key_exists('time_comment', $data)
            ? trim((string) $data['time_comment'])
            : self::defaultComment($actor->user);

        if (filter_var($data['save_time_comment_default'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $actor->user?->canonical()->forceFill(['default_event_time_comment' => $comment])->save();
        }

        return [...$attributes, 'time_comment' => $comment];
    }

    public static function isPreliminary(Event $event): bool
    {
        return $event->starts_at !== null
            && ! $event->hasEnded()
            && $event->status->value !== 'cancelled'
            && ! $event->time_confirmed;
    }
}
