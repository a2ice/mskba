<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use App\Support\Features\VenueRentalRollout;
use Illuminate\Database\Eloquent\Builder;
use App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stable base interest-group URL: one group identity per confirmed venue ID.
 * Subgroups may receive their own IDs later without changing these public URLs.
 */
final class VenueInterestGroupController extends Controller
{
    public function __invoke(int $venue, Request $request, VenueSubscriptionAudience $audience, FeatureFlags $features, VenueRentalRollout $rollout): Response|RedirectResponse
    {
        $item = Venue::query()
            ->whereKey($venue)
            ->where('status', VenueStatusEnum::CONFIRMED->value)
            ->firstOrFail();

        if (app(ThemeResolver::class)->active() !== 'mskba_app') {
            return redirect()->route('venues.show', $item->routeIdentifier());
        }

        $members = $audience->forVenue($item);
        $viewer = $request->user()?->canonical();
        $isSubscriber = $viewer !== null && PlayIntent::query()
            ->whereIn('user_id', $viewer->identityIds())
            ->where('venue_id', $item->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        // Rental gatherings are an existing, distinct lifecycle, not PlayAssembly.
        // Private gatherings are visible only to their organizer or active participants.
        // Do not disclose even counts of private gatherings to other users or venue staff.
        $rentalKey = (string) ($viewer?->id ?? $request->ip());
        $rentalEnabled = $features->enabled(VenueRentalFeature::RENTAL_FLOW)
            && $features->enabled(VenueRentalFeature::COORDINATION)
            && $rollout->allows(VenueRentalFeature::COORDINATION, $request->user(), null, null, $rentalKey, false)
            && $rollout->allows(VenueRentalFeature::RENTAL_FLOW, $request->user(), null, null, $rentalKey, false);
        $rentalWritable = $rentalEnabled
            && $rollout->allows(VenueRentalFeature::COORDINATION, $request->user(), null, null, $rentalKey, true)
            && $rollout->allows(VenueRentalFeature::RENTAL_FLOW, $request->user(), null, null, $rentalKey, true);
        $identityIds = $viewer?->identityIds() ?? [];
        $gatherings = null;
        if ($rentalEnabled) {
            $gatherings = VenueRentalCoordination::query()
                ->where('venue_id', $item->id)
                ->where(function (Builder $q) use ($identityIds): void {
                    $q->where('visibility', 'public');
                    if ($identityIds !== []) {
                        $q->orWhereIn('organizer_user_id', $identityIds)
                            ->orWhereHas('participants', fn (Builder $p) => $p
                                ->whereIn('user_id', $identityIds)->whereNull('left_at'));
                    }
                })
                ->withCount(['participants as active_participants_count' => fn (Builder $q) => $q->whereNull('left_at')])
                ->with('booking:id,public_id,status,event_id')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(8, ['*'], 'gatherings_page')
                ->withQueryString();
        }

        return ThemeResolver::page('venues.interest-group', [
            'venue' => $item,
            'venueAudience' => $members,
            'viewerHasVenueInterest' => $isSubscriber,
            'gatherings' => $gatherings,
            'rentalEnabled' => $rentalEnabled,
            'canStartRentalGathering' => $rentalWritable && $isSubscriber && $viewer?->isConfirmed(),
            'isAuthenticated' => $request->user() !== null,
            'viewerIdentityIds' => $identityIds,
        ])->header('Cache-Control', 'private, no-store');
    }
}
