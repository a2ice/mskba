<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\Services\VenueRevisionManager;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SubmitModerationRequestHandler
{
    public function __construct(
        private readonly VenueAccessResolver $access,
        private readonly VenueRevisionManager $revisions,
    ) {}

    public function handle(string $alias, ?User $user, ?Actor $actor, ?string $message = null): ModerationRequest
    {
        return DB::transaction(function () use ($alias, $user, $actor, $message): ModerationRequest {
            $venues = Venue::query()
                ->with('creatorActor')
                ->whereRouteIdentifier($alias)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($venues->isEmpty()) {
                throw new VenueNotFoundException;
            }

            $venue = $venues->first(fn (Venue $venue): bool => $this->access->canEdit($user, $venue, $actor));

            if ($venue === null) {
                throw new VenueAccessDeniedException;
            }

            if ($venue->status === VenueStatusEnum::BLOCKED) {
                throw new InvalidArgumentException('Площадка заблокирована, повторная отправка недоступна.');
            }

            $venue->loadMissing('location.address');

            if ($venue->location?->address?->latitude === null || $venue->location->address->longitude === null) {
                throw new InvalidArgumentException('Перед отправкой на модерацию выберите адрес площадки с координатами.');
            }

            if ($venue->moderationRequests()
                ->where('status', ModerationRequestStatusEnum::PENDING->value)
                ->exists()) {
                throw new InvalidArgumentException('Заявка модерации уже находится на рассмотрении.');
            }

            $revision = $venue->status === VenueStatusEnum::CONFIRMED
                ? $this->revisions->draftFor($venue)
                : null;

            if ($venue->status === VenueStatusEnum::CONFIRMED && $revision === null) {
                throw new InvalidArgumentException('Сначала сохраните изменения площадки.');
            }

            if ($revision !== null) {
                $this->revisions->assertCurrent($revision);
            }

            $thread = $venue->moderationRequests()->create([
                'type' => ModerationTypeEnum::VENUE,
                'venue_revision_id' => $revision?->id,
                'submitted_by_actor_id' => $actor?->id,
                'status' => ModerationRequestStatusEnum::PENDING,
                'submitted_at' => now(),
            ]);

            if ($message !== null && trim($message) !== '') {
                $thread->messages()->create([
                    'sender_id' => $actor?->id,
                    'message' => trim($message),
                ]);
            }

            return $thread;
        });
    }
}
