<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceRoundStatus;
use App\Modules\Coordination\Domain\Events\VenueBookingAttendanceRoundOpened;
use App\Modules\Coordination\Domain\Exceptions\VenueBookingAttendanceException;
use App\Modules\Coordination\Domain\Models\VenueBookingAttendanceRound;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class OpenVenueBookingAttendanceRoundHandler
{
    public function __construct(private FeatureFlags $features) {}

    /** @param list<int> $invitedUserIds */
    public function handle(
        int $bookingId,
        Actor $actor,
        CarbonImmutable $requestedDeadline,
        array $invitedUserIds,
        int $minimumYesResponses,
        string $responsesVisibility = 'participants',
    ): VenueBookingAttendanceRound {
        $this->features->ensureEnabled(VenueRentalFeature::ATTENDANCE_V2);
        if (! in_array($responsesVisibility, ['participants', 'organizer'], true)) {
            throw new VenueBookingAttendanceException('Недопустимая видимость ответов.', 'INVALID_VISIBILITY');
        }
        $venueId = VenueBooking::query()->whereKey($bookingId)->value('venue_id');
        if ($venueId === null) {
            throw new VenueBookingAttendanceException('Бронь не найдена.', 'BOOKING_NOT_FOUND');
        }

        return DB::transaction(function () use ($bookingId, $venueId, $actor, $requestedDeadline, $invitedUserIds, $minimumYesResponses, $responsesVisibility): VenueBookingAttendanceRound {
            Venue::query()->lockForUpdate()->findOrFail($venueId);
            $booking = VenueBooking::query()->lockForUpdate()->findOrFail($bookingId);
            $this->assertRequester($booking, $actor);
            if ($booking->status !== VenueBookingStatusEnum::HELD
                || $booking->effective_protection_until === null
                || ! now()->lessThan($booking->effective_protection_until)) {
                throw new VenueBookingAttendanceException('Сбор явки доступен только во время действующего удержания.', 'HOLD_NOT_ACTIVE');
            }
            if (VenueBookingAttendanceRound::query()->where('venue_booking_id', $booking->id)->where('active_marker', true)->exists()) {
                throw new VenueBookingAttendanceException('Для брони уже открыт сбор явки.', 'ROUND_ALREADY_OPEN');
            }

            $deadline = $requestedDeadline->min($booking->effective_protection_until);
            if (! now()->lessThan($deadline)) {
                throw new VenueBookingAttendanceException('Дедлайн ответов уже истёк.', 'DEADLINE_EXPIRED');
            }
            $users = collect($invitedUserIds)
                ->map(fn (int $userId): User => User::query()->findOrFail($userId)->canonical())
                ->unique('id')
                ->values();
            if ($users->isEmpty() || $users->contains(fn (User $user): bool => ! $user->isConfirmed())) {
                throw new VenueBookingAttendanceException('Приглашать можно только подтверждённых пользователей.', 'INVALID_INVITEE');
            }
            if ($minimumYesResponses < 1 || $minimumYesResponses > $users->count()) {
                throw new VenueBookingAttendanceException('Порог подтверждений должен соответствовать числу приглашённых.', 'INVALID_THRESHOLD');
            }

            $round = VenueBookingAttendanceRound::query()->create([
                'public_id' => (string) Str::uuid(),
                'venue_booking_id' => $booking->id,
                'created_by_actor_id' => $actor->id,
                'status' => VenueBookingAttendanceRoundStatus::OPEN,
                'active_marker' => true,
                'responses_visibility' => $responsesVisibility,
                'deadline_at' => $deadline,
                'minimum_yes_responses' => $minimumYesResponses,
                'pending_count' => $users->count(),
            ]);
            $round->responses()->createMany($users->map(fn (User $user): array => ['user_id' => $user->id])->all());
            DB::afterCommit(static fn () => event(new VenueBookingAttendanceRoundOpened($round->id)));

            return $round->load('responses.user');
        });
    }

    private function assertRequester(VenueBooking $booking, Actor $actor): void
    {
        $actorUserId = $actor->user?->canonical()->id;
        $requesterId = $booking->requester?->canonical()->id;
        if ($actorUserId === null || $actorUserId !== $requesterId) {
            throw new VenueBookingAttendanceException('Открыть сбор явки может только заявитель.', 'ATTENDANCE_FORBIDDEN');
        }
    }
}
