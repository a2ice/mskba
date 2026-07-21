<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use App\Modules\Moderation\Domain\Events\ModerationRequestApproved;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Venue\Application\Services\VenueProximityService;
use App\Modules\Venue\Application\Services\VenueRevisionManager;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueRevision;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReviewModerationRequestHandler
{
    public function __construct(
        private readonly VenueProximityService $proximity,
        private readonly VenueRevisionManager $revisions,
    ) {}

    public function approve(ModerationRequest $request, User $reviewedBy, ?string $message = null, ?Actor $reviewedByActor = null): void
    {
        $reviewedRequest = $this->review($request, ModerationRequestStatusEnum::APPROVED, $reviewedBy, $message, $reviewedByActor);

        event(new ModerationRequestApproved($reviewedRequest));
    }

    public function reject(ModerationRequest $request, User $reviewedBy, string $message, ?Actor $reviewedByActor = null): void
    {
        $this->review($request, ModerationRequestStatusEnum::REJECTED, $reviewedBy, $message, $reviewedByActor);
    }

    private function review(
        ModerationRequest $request,
        ModerationRequestStatusEnum $status,
        User $reviewedBy,
        ?string $message = null,
        ?Actor $reviewedByActor = null,
    ): ModerationRequest {
        return DB::transaction(function () use ($request, $status, $reviewedBy, $message, $reviewedByActor): ModerationRequest {
            $venueSnapshot = Venue::query()
                ->with('location.address')
                ->whereKey($request->subject_id)
                ->lockForUpdate()
                ->first();

            if ($venueSnapshot === null) {
                throw new InvalidArgumentException('Площадка для этой заявки не найдена.');
            }

            $lockedRequest = ModerationRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== ModerationRequestStatusEnum::PENDING) {
                throw new InvalidArgumentException('Эта заявка модерации уже рассмотрена.');
            }

            if ($lockedRequest->type !== ModerationTypeEnum::VENUE) {
                throw new InvalidArgumentException('Этот сценарий модерации доступен только для площадок.');
            }

            if ($status === ModerationRequestStatusEnum::APPROVED && $lockedRequest->venue_revision_id !== null) {
                $revision = VenueRevision::query()
                    ->whereKey($lockedRequest->venue_revision_id)
                    ->lockForUpdate()
                    ->first();

                if ($revision === null || $revision->venue_id !== $lockedRequest->subject_id) {
                    throw new InvalidArgumentException('Ревизия площадки для этой заявки не найдена.');
                }

                $this->revisions->apply($revision);
                $venueSnapshot->refresh()->load('location.address');
            }

            $venuesOfType = Venue::query()
                ->with('location.address')
                ->where('type', $venueSnapshot->type)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $venue = $venuesOfType->firstWhere('id', $venueSnapshot->id);

            if ($venue === null) {
                throw new InvalidArgumentException('Площадка для этой заявки не найдена.');
            }

            if ($status !== ModerationRequestStatusEnum::APPROVED && trim((string) $message) === '') {
                throw new InvalidArgumentException('Укажите сообщение для пользователя.');
            }

            if ($status === ModerationRequestStatusEnum::APPROVED) {
                $address = $venue->location?->address;

                if ($address?->latitude === null || $address->longitude === null) {
                    throw new InvalidArgumentException('Нельзя подтвердить площадку без координат.');
                }

                $hasStrongConflict = $venuesOfType
                    ->filter(fn (Venue $candidate): bool => $candidate->id !== $venue->id
                        && $candidate->status === VenueStatusEnum::CONFIRMED)
                    ->contains(fn (Venue $candidate): bool => ($this->proximity->distanceBetween($venue, $candidate) ?? INF)
                        <= $this->proximity->strongRadiusMeters());

                if ($hasStrongConflict) {
                    throw new InvalidArgumentException('Рядом уже подтверждена площадка такого типа. Сначала разрешите кандидат дубля.');
                }
            }

            $lockedRequest->forceFill([
                'status' => $status,
                'reviewed_by_user_id' => $reviewedBy->id,
                'reviewed_at' => now(),
            ])->save();

            if (trim((string) $message) !== '') {
                $lockedRequest->messages()->create([
                    'sender_id' => $reviewedByActor?->id,
                    'receiver_id' => $lockedRequest->submitted_by_actor_id,
                    'message' => trim((string) $message),
                ]);
            }

            if ($status === ModerationRequestStatusEnum::APPROVED && $venue !== null) {
                $venue->forceFill([
                    'status' => VenueStatusEnum::CONFIRMED,
                    'status_info' => null,
                ])->save();
            }

            return $lockedRequest;
        });
    }
}
