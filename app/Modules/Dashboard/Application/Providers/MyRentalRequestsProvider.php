<?php

namespace App\Modules\Dashboard\Application\Providers;

use App\Modules\Dashboard\Application\Contracts\DashboardDataProvider;
use App\Modules\Dashboard\Application\Data\DashboardWidgetData;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\VenueBooking\Application\Queries\ListRequesterBookings;
use App\Modules\VenueBooking\Domain\Enums\VenueRentalPreRequestStatus;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use App\Support\Features\VenueRentalRollout;
use Illuminate\Database\Eloquent\Builder;

final readonly class MyRentalRequestsProvider implements DashboardDataProvider
{
    public function __construct(
        private ListRequesterBookings $bookings,
        private FeatureFlags $features,
        private VenueRentalRollout $rollout,
    ) {}

    private function allows(User $user, VenueRentalFeature $feature): bool
    {
        return $this->features->enabled($feature)
            && $this->rollout->allows($feature, $user, null, null, (string) $user->id, false);
    }

    public function isAvailable(User $user, array $settings): bool
    {
        $user = $user->canonical();

        return $user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed()
            && $this->allows($user, VenueRentalFeature::RENTAL_FLOW)
            && $this->allows($user, VenueRentalFeature::PORTAL);
    }

    public function getData(User $user, array $settings): DashboardWidgetData
    {
        $user = $user->canonical();
        abort_unless($this->isAvailable($user, $settings), 404);
        $limit = max(1, min(10, is_numeric($settings['limit'] ?? null) ? (int) $settings['limit'] : 5));
        $now = now();
        $bookings = $this->bookings->forUser($user)
            ->where(function (Builder $q) use ($now): void {
                $q->whereIn('status', [VenueBookingStatusEnum::PENDING->value, VenueBookingStatusEnum::REQUESTED->value, VenueBookingStatusEnum::HELD->value])
                    ->orWhere(fn (Builder $confirmed) => $confirmed->where('status', VenueBookingStatusEnum::CONFIRMED->value)->where('ends_at', '>', $now));
            });
        // The pre-request detail ACL recognizes the canonical requester, not arbitrary
        // identity aliases or an admin/agent's delegated venue permissions.
        $preRequests = VenueRentalPreRequest::query()->where('requester_user_id', $user->id)
            ->where('status', VenueRentalPreRequestStatus::ACTIVE->value);

        $bookingTotal = (clone $bookings)->count();
        $preTotal = (clone $preRequests)->count();
        $bookingItems = $bookings->with('venue:id,name')->orderByDesc('updated_at')->orderByDesc('id')
            ->limit($limit)->get()->map(static fn (VenueBooking $booking): array => [
                'id' => $booking->public_id,
                'kind' => 'booking',
                'venue_name' => $booking->venue?->name,
                'status' => $booking->status->value,
                'status_label' => $booking->status->label(),
                'starts_at' => $booking->starts_at->utc()->toIso8601String(),
                'ends_at' => $booking->ends_at->utc()->toIso8601String(),
                'updated_at' => $booking->updated_at->utc()->toIso8601String(),
                'url' => route('account.venue-bookings.show', $booking, false),
            ]);
        $preItems = $preRequests->with('venue:id,name')->orderByDesc('updated_at')->orderByDesc('id')
            ->limit($limit)->get()->map(static fn (VenueRentalPreRequest $pre): array => [
                'id' => $pre->public_id,
                'kind' => 'pre_request',
                'venue_name' => $pre->venue?->name,
                'status' => $pre->status->value,
                'status_label' => $pre->status->label(),
                'starts_at' => $pre->starts_at->utc()->toIso8601String(),
                'ends_at' => $pre->ends_at->utc()->toIso8601String(),
                'updated_at' => $pre->updated_at->utc()->toIso8601String(),
                'url' => route('account.venue-pre-requests.show', $pre, false),
            ]);

        $items = $bookingItems->concat($preItems)->sortByDesc('updated_at')->take($limit)->values()->all();

        return new DashboardWidgetData($bookingTotal + $preTotal, $items, [
            'booking_total' => $bookingTotal,
            'pre_request_total' => $preTotal,
            'bookings_url' => route('account.venue-bookings.index', [], false),
            'pre_requests_url' => route('account.venue-pre-requests.index', [], false),
        ]);
    }
}
