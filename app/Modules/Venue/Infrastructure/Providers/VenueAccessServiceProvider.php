<?php

namespace App\Modules\Venue\Infrastructure\Providers;

use App\Modules\Moderation\Domain\Events\ModerationRequestApproved;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Events\VenueMembershipGranted;
use App\Modules\Venue\Domain\Events\VenueMembershipRevoked;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimApproved;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimRejected;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimSubmitted;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Infrastructure\Listeners\ConfirmVenueAfterModerationRequestApproved;
use App\Modules\Venue\Infrastructure\Listeners\CreateVenueMembershipNotification;
use App\Modules\Venue\Infrastructure\Listeners\CreateVenueOwnershipClaimNotification;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use App\Support\Features\VenueRentalRollout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class VenueAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $canCreateVenue = fn ($user): bool => ! $user->isBlocked();

        Gate::define('add_venue', $canCreateVenue);
        Gate::define('venue-create-venue', $canCreateVenue);

        Event::listen(ModerationRequestApproved::class, ConfirmVenueAfterModerationRequestApproved::class);
        Event::listen(VenueOwnershipClaimSubmitted::class, CreateVenueOwnershipClaimNotification::class);
        Event::listen(VenueOwnershipClaimApproved::class, CreateVenueOwnershipClaimNotification::class);
        Event::listen(VenueOwnershipClaimRejected::class, CreateVenueOwnershipClaimNotification::class);
        Event::listen(VenueMembershipGranted::class, CreateVenueMembershipNotification::class);
        Event::listen(VenueMembershipRevoked::class, CreateVenueMembershipNotification::class);

        View::composer('theme::pages.venues.show', function ($view): void {
            $venue = $view->getData()['venue'] ?? null;

            if ($venue !== null && ($venue->canEdit || $venue->canEditSchedule)) {
                $view->with('contextManagementUrl', route('account.venues.show', $venue->routeIdentifier()));
                $view->with('contextManagementLabel', 'Управление площадкой');
            }

            $user = request()->user();
            if ($venue !== null) {
                $venueModel = Venue::query()->find($venue->id);
                if ($venueModel !== null && $this->rentalFeatureAllows(VenueRentalFeature::RENTAL_FLOW, $venueModel)) {
                    if (VenueBookingPolicy::query()->where('venue_id', $venueModel->id)->where('active_marker', true)->where('is_enabled', true)->exists()) {
                        $view->with('rentalUrl', route('venues.rental.show', $venueModel));
                    }

                    if ($user !== null && app(VenueCommercialAccess::class)->allows($user, $venueModel, VenuePermissionEnum::MANAGE_MEMBERSHIPS)) {
                        $view->with('commercialMembershipsUrl', route('account.venues.commercial-memberships.index', $venueModel));
                    }

                    if ($user !== null && app(VenueCommercialAccess::class)->allows($user, $venueModel, VenuePermissionEnum::MANAGE_BOOKING_POLICY)) {
                        $view->with('bookingPolicyUrl', route('account.venues.booking-policy.edit', $venueModel));
                    }
                }
            }
        });

        View::composer('theme::partials.venues.internal-sidebar', function ($view): void {
            $venue = $view->getData()['venue'] ?? null;
            $user = request()->user();

            if (! $venue instanceof Venue
                || $user === null
                || ! $this->rentalFeatureAllows(VenueRentalFeature::RENTAL_FLOW, $venue)) {
                return;
            }

            $access = app(VenueCommercialAccess::class);

            if ($access->allows($user, $venue, VenuePermissionEnum::MANAGE_BOOKING_POLICY)) {
                $view->with('bookingPolicyUrl', route('account.venues.booking-policy.edit', $venue));
            }

            if ($access->allows($user, $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS)) {
                $view->with('commercialMembershipsUrl', route('account.venues.commercial-memberships.index', $venue));
            }

            if ($access->allows($user, $venue, VenuePermissionEnum::VIEW_BOOKING_REQUESTS)
                && $this->rentalFeatureAllows(VenueRentalFeature::PORTAL, $venue)) {
                $view->with('venueBookingInboxUrl', route('account.venue-bookings.inbox', ['venue_id' => $venue->id]));
            }

            if (VenueBookingPolicy::query()
                ->where('venue_id', $venue->id)
                ->where('active_marker', true)
                ->where('is_enabled', true)
                ->exists()) {
                $view->with('rentalUrl', route('venues.rental.show', $venue));
            }
        });
    }

    private function rentalFeatureAllows(VenueRentalFeature $feature, ?Venue $venue): bool
    {
        if (! app(FeatureFlags::class)->enabled($feature)) {
            return false;
        }

        $user = request()->user();
        $stableKey = (string) ($user?->canonical()->id ?? $venue?->id ?? request()->ip());

        return app(VenueRentalRollout::class)->allows(
            $feature,
            $user,
            $venue?->id,
            null,
            $stableKey,
            false,
        );
    }
}
