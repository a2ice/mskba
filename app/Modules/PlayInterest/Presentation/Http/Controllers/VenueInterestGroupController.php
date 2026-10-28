<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
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
    public function __invoke(int $venue, Request $request, VenueSubscriptionAudience $audience): Response|RedirectResponse
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
            ->where('user_id', $viewer->id)
            ->where('venue_id', $item->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        return ThemeResolver::page('venues.interest-group', [
            'venue' => $item,
            'venueAudience' => $members,
            'viewerHasVenueInterest' => $isSubscriber,
        ])->header('Cache-Control', 'private, no-store');
    }
}
