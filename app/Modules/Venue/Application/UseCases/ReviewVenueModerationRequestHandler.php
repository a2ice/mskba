<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueModerationMessageDirectionEnum;
use App\Modules\Venue\Domain\Enums\VenueModerationRequestStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\VenueModerationRequest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReviewVenueModerationRequestHandler
{
    public function approve(VenueModerationRequest $request, User $reviewedBy): void
    {
        $this->review($request, VenueModerationRequestStatusEnum::APPROVED, $reviewedBy);
    }

    public function reject(VenueModerationRequest $request, User $reviewedBy, string $message): void
    {
        $this->review($request, VenueModerationRequestStatusEnum::REJECTED, $reviewedBy, $message);
    }

    public function block(VenueModerationRequest $request, User $reviewedBy, string $message): void
    {
        $this->review($request, VenueModerationRequestStatusEnum::BLOCKED, $reviewedBy, $message);
    }

    private function review(
        VenueModerationRequest $request,
        VenueModerationRequestStatusEnum $status,
        User $reviewedBy,
        ?string $message = null,
    ): void {
        DB::transaction(function () use ($request, $status, $reviewedBy, $message): void {
            $lockedRequest = VenueModerationRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== VenueModerationRequestStatusEnum::PENDING) {
                throw new InvalidArgumentException('Эта заявка уже рассмотрена.');
            }

            $venue = $lockedRequest->venue()->lockForUpdate()->firstOrFail();

            if ($status !== VenueModerationRequestStatusEnum::APPROVED && trim((string) $message) === '') {
                throw new InvalidArgumentException('Укажите сообщение для пользователя.');
            }

            $lockedRequest->forceFill([
                'status' => $status,
                'reviewed_by_user_id' => $reviewedBy->id,
                'reviewed_at' => now(),
            ])->save();

            if ($status === VenueModerationRequestStatusEnum::APPROVED) {
                $venue->forceFill([
                    'status' => VenueStatusEnum::CONFIRMED,
                    'status_info' => null,
                ])->save();

                return;
            }

            if ($status === VenueModerationRequestStatusEnum::BLOCKED) {
                $venue->forceFill([
                    'status' => VenueStatusEnum::BLOCKED,
                    'status_info' => trim((string) $message),
                ])->save();
            }

            $lockedRequest->messages()->create([
                'direction' => VenueModerationMessageDirectionEnum::OUTGOING,
                'author_user_id' => $reviewedBy->id,
                'message' => trim((string) $message),
            ]);
        });
    }
}
