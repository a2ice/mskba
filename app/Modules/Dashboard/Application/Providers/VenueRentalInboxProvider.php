<?php

namespace App\Modules\Dashboard\Application\Providers;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Dashboard\Application\Contracts\DashboardDataProvider;
use App\Modules\Dashboard\Application\Data\DashboardWidgetData;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\Services\VenueRentalPreRequests;
use App\Modules\VenueBooking\Domain\Enums\VenueRentalPreRequestStatus;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest;
use Illuminate\Database\Eloquent\Builder;

final readonly class VenueRentalInboxProvider implements DashboardDataProvider
{
    public function __construct(
        private MyRentalRequestsProvider $rental,
        private VenueMembershipAccess $memberships,
        private VenueRentalPreRequests $preRequests,
    ) {}

    /** @return array{bookings: array{venues: list<int>, courts: list<int>}, pre_requests: array{venues: list<int>, courts: list<int>}} */
    private function scopes(User $user): array
    {
        $bookings = [
            'venues' => $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::VIEW_BOOKING_REQUESTS),
            'courts' => $this->memberships->allowedCourtIdsFor($user, VenuePermissionEnum::VIEW_BOOKING_REQUESTS),
        ];
        $pre = $bookings;
        foreach ([VenuePermissionEnum::VIEW_EVENT_INTERESTS, VenuePermissionEnum::PROPOSE_EVENT_BOOKING] as $permission) {
            $pre['venues'] = [...$pre['venues'], ...$this->memberships->allowedVenueIdsFor($user, $permission)];
            $pre['courts'] = [...$pre['courts'], ...$this->memberships->allowedCourtIdsFor($user, $permission)];
        }
        foreach ($this->memberships->activeVenueMembershipsFor($user) as $membership) {
            if ($membership->scope_type === ContractMembershipScopeTypeEnum::VENUE
                && in_array($membership->access_level, [VenueMembershipAccessLevelEnum::OWNER->value, VenueMembershipAccessLevelEnum::ADMIN->value], true)) {
                $pre['venues'][] = (int) $membership->scope_id;
            }
        }

        $scopes = ['bookings' => $bookings, 'pre_requests' => $pre];
        foreach ($scopes as &$scope) {
            $scope['venues'] = Venue::query()->whereKey(array_values(array_unique($scope['venues'])))->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $scope['courts'] = VenueCourt::query()->whereKey(array_values(array_unique($scope['courts'])))
                ->whereHas('venue')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }
        unset($scope);

        return $scopes;
    }

    private function hasScope(array $scope): bool
    {
        return $scope['venues'] !== [] || $scope['courts'] !== [];
    }

    public function isAvailable(User $user, array $settings): bool
    {
        $user = $user->canonical();
        if (! $this->rental->isAvailable($user, [])) {
            return false;
        }
        $scopes = $this->scopes($user);

        return $this->hasScope($scopes['bookings']) || $this->hasScope($scopes['pre_requests']);
    }

    private function constrain(Builder $query, array $scope, string $table): Builder
    {
        return $query->whereIn('venue_id', Venue::query()->select('id'))
            ->where(function (Builder $q) use ($scope, $table): void {
                $q->whereIn('venue_id', $scope['venues'])
                    ->orWhereIn('venue_court_id', VenueCourt::query()->whereKey($scope['courts'])
                        ->whereColumn('venue_courts.venue_id', $table.'.venue_id')->select('id'));
            });
    }

    public function getData(User $user, array $settings): DashboardWidgetData
    {
        $user = $user->canonical();
        abort_unless($this->rental->isAvailable($user, []), 404);
        $scopes = $this->scopes($user);
        abort_unless($this->hasScope($scopes['bookings']) || $this->hasScope($scopes['pre_requests']), 404);
        $limit = max(1, min(10, is_numeric($settings['limit'] ?? null) ? (int) $settings['limit'] : 5));
        $ids = $user->identityIds();
        $now = now();
        $bookings = $this->constrain(VenueBooking::query(), $scopes['bookings'], 'venue_bookings')
            ->where('flow', 'rental')->whereNotIn('requester_user_id', $ids)
            ->whereIn('status', [VenueBookingStatusEnum::REQUESTED->value, VenueBookingStatusEnum::HELD->value])
            ->where('ends_at', '>', $now)
            // An unanswered offer remains represented by the active pre-request.
            ->where(fn (Builder $q) => $q->whereNull('pre_request_id')->orWhereNotNull('proposal_accepted_at'))
            ->with(['venue:id,name', 'preRequest.venue', 'preRequest.court'])
            ->orderByDesc('updated_at')->orderByDesc('id');
        $preRequests = $this->constrain(VenueRentalPreRequest::query(), $scopes['pre_requests'], 'venue_rental_pre_requests')
            ->whereNotIn('requester_user_id', $ids)->where('status', VenueRentalPreRequestStatus::ACTIVE->value)
            ->where('ends_at', '>', $now)->with(['venue', 'court'])
            ->orderByDesc('updated_at')->orderByDesc('id');

        $bookingTotal = $preTotal = 0;
        $items = [];
        foreach ($bookings->lazy(100) as $booking) {
            if ($booking->pre_request_id !== null && ($booking->preRequest === null || ! $this->preRequests->can($user, $booking->preRequest))) {
                continue;
            }
            $bookingTotal++;
            if ($bookingTotal <= $limit) {
                $items[] = $this->item($booking, 'booking', route('account.venue-bookings.show', $booking, false));
            }
        }
        foreach ($preRequests->lazy(100) as $pre) {
            if (! $this->preRequests->can($user, $pre)) {
                continue;
            }
            $preTotal++;
            if ($preTotal <= $limit) {
                $items[] = $this->item($pre, 'pre_request', route('account.venue-pre-requests.show', $pre, false));
            }
        }

        return new DashboardWidgetData($bookingTotal + $preTotal, collect($items)->sortByDesc('updated_at')->take($limit)->values()->all(), [
            'booking_total' => $bookingTotal,
            'pre_request_total' => $preTotal,
            'bookings_url' => $this->hasScope($scopes['bookings']) ? route('account.venue-bookings.inbox', [], false) : null,
            'pre_requests_url' => $this->hasScope($scopes['pre_requests']) ? route('account.venue-pre-requests.index', [], false) : null,
        ]);
    }

    private function item(VenueBooking|VenueRentalPreRequest $record, string $kind, string $url): array
    {
        return [
            'id' => $record->public_id, 'kind' => $kind,
            'venue_name' => $record->venue?->name,
            'status' => $record->status->value, 'status_label' => $record->status->label(),
            'starts_at' => $record->starts_at->utc()->toIso8601String(),
            'ends_at' => $record->ends_at->utc()->toIso8601String(),
            'updated_at' => $record->updated_at->utc()->toIso8601String(),
            'url' => $url,
        ];
    }
}
