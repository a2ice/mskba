<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\Services\VenueRevisionManager;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Exceptions\VenuePendingModerationException;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final class UpdateVenueConditionsHandler
{
    public function __construct(
        private readonly VenueAccessResolver $access,
        private readonly VenueRevisionManager $revisions,
    ) {}

    /** @param array{access_type: string, requires_booking_approval: bool|int|string} $data */
    public function handle(string $alias, ?User $user, ?Actor $actor, array $data): Venue
    {
        return DB::transaction(function () use ($alias, $user, $actor, $data): Venue {
            $venues = Venue::query()->with('creatorActor')->whereRouteIdentifier($alias)->orderBy('id')->lockForUpdate()->get();
            if ($venues->isEmpty()) {
                throw new VenueNotFoundException;
            }
            $venue = $venues->first(fn (Venue $venue): bool => $this->access->canEdit($user, $venue, $actor));
            if ($venue === null) {
                throw new VenueAccessDeniedException;
            }
            if ($venue->hasPendingModerationRequest()) {
                throw new VenuePendingModerationException;
            }

            if ($venue->status === VenueStatusEnum::CONFIRMED) {
                $revision = $this->revisions->getOrCreateDraft($venue, $actor);
                $this->revisions->assertCurrent($revision);
                $payload = $revision->payload;
                $payload['details'] = array_replace($payload['details'], [
                    'access_type' => $data['access_type'],
                    'requires_booking_approval' => (bool) $data['requires_booking_approval'],
                ]);
                $revision->forceFill(['payload' => $payload])->save();
            } else {
                $venue->forceFill([
                    'requires_payment' => match ($data['access_type']) {
                        'free' => false,
                        'paid' => true,
                        default => null,
                    },
                    'requires_booking_approval' => (bool) $data['requires_booking_approval'],
                    'content_version' => $venue->content_version + 1,
                ])->save();
            }

            return $venue->refresh();
        });
    }
}
