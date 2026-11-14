<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventVenueInterestService;
use App\Modules\Event\Domain\Models\EventVenueInterest;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class VenueEventInterestController extends Controller
{
    public function index(Request $request, Venue $venue, EventVenueInterestService $access): Response
    {
        abort_unless($request->user() !== null
            && $access->canView($request->user(), $venue), 403);

        $interests = EventVenueInterest::query()->with(['event', 'court'])
            ->where('venue_id', $venue->id)
            ->latest('starts_at')
            ->paginate(20);

        $totals = EventVenueInterest::query()->where('venue_id', $venue->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status');

        return ThemeResolver::page('venues.event-interests', [
            'venue' => $venue,
            'interests' => $interests,
            'totals' => $totals,
        ]);
    }
}
