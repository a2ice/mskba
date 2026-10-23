<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BackofficeVenueModerationHandler
{
    public function __construct(
        private readonly ReviewModerationRequestHandler $review,
        private readonly CurrentActorResolver $actors,
        private readonly BackofficePermissionManager $permissions,
    ) {}

    public function canReview(?User $user): bool
    {
        return $user !== null
            && $this->permissions->allows($user, Permission::BACKOFFICE_ACCESS)
            && $this->permissions->allows($user, Permission::BACKOFFICE_VENUE_MODERATE);
    }

    public function canApproveImmediately(?User $user): bool
    {
        return $this->canReview($user)
            && $user->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN;
    }

    public function approve(ModerationRequest $request, User $user, ?string $message = null): void
    {
        abort_unless($this->canReview($user), 403);
        $request = $this->venueRequest($request);
        $this->review->approve($request, $user->canonical(), $message, $this->actors->resolve($user, null));
    }

    public function reject(ModerationRequest $request, User $user, string $message): void
    {
        abort_unless($this->canReview($user), 403);
        if (trim($message) === '') {
            throw new InvalidArgumentException('Укажи причину отклонения заявки.');
        }
        $this->review->reject($this->venueRequest($request), $user->canonical(), $message,
            $this->actors->resolve($user, null));
    }

    /**
     * A superadmin's one-click approval is a real submitted-and-approved
     * ModerationRequest, not a direct update of venues.status.
     * Existing pending user requests preserve their real submitter.
     */
    public function approveImmediately(Venue $venue, User $user): ModerationRequest
    {
        abort_unless($this->canApproveImmediately($user), 403);
        $actor = $this->actors->resolve($user, null);
        if ($actor === null) {
            throw new InvalidArgumentException('Не удалось определить системного автора заявки.');
        }

        return DB::transaction(function () use ($venue, $user, $actor): ModerationRequest {
            $venue = Venue::query()->whereKey($venue->getKey())->lockForUpdate()->firstOrFail();

            if ($venue->status !== VenueStatusEnum::UNCONFIRMED || $venue->trashed()) {
                throw new InvalidArgumentException('Подтвердить можно только действующую неподтверждённую площадку.');
            }

            $existing = $venue->moderationRequests()
                ->where('status', ModerationRequestStatusEnum::PENDING->value)
                ->orderBy('id')->first();

            if ($existing !== null) {
                // Never fabricate a second request or overwrite the user's
                // identity; review the actual pending submission.
                $request = $existing;
            } else {
                $request = $venue->moderationRequests()->create([
                    'type' => ModerationTypeEnum::VENUE,
                    'submitted_by_actor_id' => $actor->id,
                    'status' => ModerationRequestStatusEnum::PENDING,
                    'submitted_at' => now(),
                ]);
                $request->messages()->create([
                    'sender_id' => $actor->id,
                    'message' => 'Административное подтверждение: заявка создана superadmin.',
                ]);
            }

            $this->review->approve($request, $user->canonical(),
                'Площадка подтверждена superadmin.', $actor);

            return $request->refresh();
        });
    }

    private function venueRequest(ModerationRequest $request): ModerationRequest
    {
        abort_unless($request->type === ModerationTypeEnum::VENUE, 404);

        return $request;
    }
}
