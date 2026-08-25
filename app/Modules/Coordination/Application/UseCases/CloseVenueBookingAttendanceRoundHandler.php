<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceRoundStatus;
use App\Modules\Coordination\Domain\Events\VenueBookingAttendanceRoundClosed;
use App\Modules\Coordination\Domain\Exceptions\VenueBookingAttendanceException;
use App\Modules\Coordination\Domain\Models\VenueBookingAttendanceRound;
use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;

final readonly class CloseVenueBookingAttendanceRoundHandler
{
    public function __construct(private FeatureFlags $features) {}

    public function handle(int $roundId, Actor $actor, string $reason = 'manual'): VenueBookingAttendanceRound
    {
        $this->features->ensureEnabled(VenueRentalFeature::ATTENDANCE_V2);
        $ids = VenueBookingAttendanceRound::query()
            ->join('venue_bookings', 'venue_bookings.id', '=', 'venue_booking_attendance_rounds.venue_booking_id')
            ->where('venue_booking_attendance_rounds.id', $roundId)
            ->first(['venue_bookings.venue_id', 'venue_bookings.id as booking_id']);
        if ($ids === null) {
            throw new VenueBookingAttendanceException('Сбор явки не найден.', 'ROUND_NOT_FOUND');
        }

        return DB::transaction(function () use ($roundId, $ids, $actor, $reason): VenueBookingAttendanceRound {
            Venue::query()->lockForUpdate()->findOrFail($ids->venue_id);
            $booking = VenueBooking::query()->lockForUpdate()->findOrFail($ids->booking_id);
            $round = VenueBookingAttendanceRound::query()->lockForUpdate()->findOrFail($roundId);
            if ($actor->type !== ActorTypeEnum::SYSTEM
                && $actor->user?->canonical()->id !== $booking->requester?->canonical()->id) {
                throw new VenueBookingAttendanceException('Закрыть сбор явки может только заявитель.', 'ATTENDANCE_FORBIDDEN');
            }
            if ($round->status === VenueBookingAttendanceRoundStatus::CLOSED) {
                return $round->load('responses.user');
            }

            $round->update([
                'status' => VenueBookingAttendanceRoundStatus::CLOSED,
                'active_marker' => null,
                'closed_at' => now(),
                'close_reason' => $reason,
            ]);
            DB::afterCommit(static fn () => event(new VenueBookingAttendanceRoundClosed($round->id, $reason)));

            return $round->load('responses.user');
        });
    }
}
