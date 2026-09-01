<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceResponseValue;
use App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceRoundStatus;
use App\Modules\Coordination\Domain\Events\VenueBookingAttendanceResponded;
use App\Modules\Coordination\Domain\Events\VenueBookingAttendanceThresholdReached;
use App\Modules\Coordination\Domain\Exceptions\VenueBookingAttendanceException;
use App\Modules\Coordination\Domain\Models\VenueBookingAttendanceResponse;
use App\Modules\Coordination\Domain\Models\VenueBookingAttendanceRound;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;

final readonly class RespondVenueBookingAttendanceRoundHandler
{
    public function __construct(private FeatureFlags $features) {}

    public function handle(int $roundId, User $user, VenueBookingAttendanceResponseValue $value): VenueBookingAttendanceRound
    {
        $this->features->ensureEnabled(VenueRentalFeature::ATTENDANCE_V2);
        $ids = VenueBookingAttendanceRound::query()
            ->join('venue_bookings', 'venue_bookings.id', '=', 'venue_booking_attendance_rounds.venue_booking_id')
            ->where('venue_booking_attendance_rounds.id', $roundId)
            ->first(['venue_bookings.venue_id', 'venue_bookings.id as booking_id']);
        if ($ids === null) {
            throw new VenueBookingAttendanceException('Сбор явки не найден.', 'ROUND_NOT_FOUND');
        }
        $user = $user->canonical();
        if (! $user->isConfirmed()) {
            throw new VenueBookingAttendanceException('Для ответа нужен подтверждённый аккаунт.', 'ATTENDANCE_FORBIDDEN');
        }

        return DB::transaction(function () use ($roundId, $ids, $user, $value): VenueBookingAttendanceRound {
            Venue::query()->lockForUpdate()->findOrFail($ids->venue_id);
            $booking = VenueBooking::query()->lockForUpdate()->findOrFail($ids->booking_id);
            $round = VenueBookingAttendanceRound::query()->lockForUpdate()->findOrFail($roundId);
            if ($booking->status !== VenueBookingStatusEnum::HELD
                || $booking->effective_protection_until === null
                || ! now()->lessThan($booking->effective_protection_until)) {
                throw new VenueBookingAttendanceException('Удержание уже не действует.', 'HOLD_NOT_ACTIVE');
            }
            if ($round->status !== VenueBookingAttendanceRoundStatus::OPEN || ! now()->lessThan($round->deadline_at)) {
                throw new VenueBookingAttendanceException('Сбор ответов уже закрыт.', 'ROUND_CLOSED');
            }
            $response = VenueBookingAttendanceResponse::query()
                ->where('round_id', $round->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if ($response === null) {
                throw new VenueBookingAttendanceException('Пользователь не приглашён в этот сбор.', 'ATTENDANCE_FORBIDDEN');
            }
            if ($response->response === $value) {
                return $round->load('responses.user');
            }

            $response->update(['response' => $value, 'responded_at' => now()]);
            $counts = $round->responses()->get()->countBy(fn (VenueBookingAttendanceResponse $item): string => $item->response?->value ?? 'pending');
            $previousYes = $round->yes_count;
            $round->forceFill([
                'yes_count' => $counts->get('yes', 0),
                'no_count' => $counts->get('no', 0),
                'maybe_count' => $counts->get('maybe', 0),
                'pending_count' => $counts->get('pending', 0),
            ]);
            $thresholdReached = $round->threshold_reached_at === null
                && $previousYes < $round->minimum_yes_responses
                && $round->yes_count >= $round->minimum_yes_responses;
            if ($thresholdReached) {
                $round->threshold_reached_at = now();
            }
            $round->save();
            DB::afterCommit(static fn () => event(new VenueBookingAttendanceResponded($round->id, $user->id, $value->value)));
            if ($thresholdReached) {
                DB::afterCommit(static fn () => event(new VenueBookingAttendanceThresholdReached($round->id, $round->yes_count)));
            }

            return $round->load('responses.user');
        });
    }
}
