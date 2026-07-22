<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class UpdateEventHandler
{
    public function __construct(
        private readonly EventManagementAccess $access,
        private readonly CyrillicTransliterator $transliterator,
    ) {}

    /** @param array{title: string, type: string, visibility: string, description?: string|null, max_participants?: int|null} $data */
    public function handle(string $identifier, Actor $actor, array $data): Event
    {
        return DB::transaction(function () use ($identifier, $actor, $data): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $this->access->assertCanManage($event, $actor);

            if (in_array($event->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)
                || $event->ends_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('Завершённое или отменённое мероприятие нельзя редактировать.');
            }

            $maxParticipants = isset($data['max_participants']) ? (int) $data['max_participants'] : null;
            $confirmedParticipants = $event->participants()
                ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                ->count();

            if ($maxParticipants !== null && $maxParticipants < $confirmedParticipants) {
                throw new InvalidArgumentException('Лимит участников не может быть меньше числа уже записавшихся.');
            }

            $event->forceFill([
                'title' => $data['title'],
                'alias' => Str::slug($this->transliterator->transliterate($data['title'])),
                'type' => EventTypeEnum::from($data['type']),
                'visibility' => EventVisibilityEnum::from($data['visibility']),
                'description' => $data['description'] ?? null,
                'max_participants' => $maxParticipants,
            ])->save();

            return $event->refresh();
        });
    }
}
