<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Enums\VenueModerationMessageDirectionEnum;
use App\Modules\Venue\Domain\Enums\VenueModerationRequestStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueModerationRequest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SubmitVenueModerationRequestHandler
{
    public function __construct(
        private readonly VenueAccessResolver $access,
    ) {}

    public function handle(string $alias, ?User $user, ?Actor $actor, ?string $message = null): VenueModerationRequest
    {
        return DB::transaction(function () use ($alias, $user, $actor, $message): VenueModerationRequest {
            $venues = Venue::query()
                ->with('creatorActor')
                ->where('alias', $alias)
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

            if ($venue->moderationRequests()
                ->where('status', VenueModerationRequestStatusEnum::PENDING->value)
                ->exists()) {
                throw new InvalidArgumentException('Заявка уже находится на модерации.');
            }

            $request = $venue->moderationRequests()->create([
                'submitted_by_actor_id' => $actor?->id,
                'status' => VenueModerationRequestStatusEnum::PENDING,
                'submitted_at' => now(),
            ]);

            if ($message !== null && trim($message) !== '') {
                $request->messages()->create([
                    'direction' => VenueModerationMessageDirectionEnum::INCOMING,
                    'author_actor_id' => $actor?->id,
                    'author_user_id' => $user?->id,
                    'message' => trim($message),
                ]);
            }

            return $request;
        });
    }
}
