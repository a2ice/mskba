<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PlayInterest\Domain\Models\PlayOpportunity;
use App\Modules\Social\Domain\Models\SocialWallEntry;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PlayOpportunityController extends Controller
{
    public function show(Request $request, PlayOpportunity $opportunity): Response
    {
        $viewer = $request->user()?->canonical();
        abort_unless($viewer !== null && $opportunity->hasActiveMember($viewer), 404);

        $opportunity->load(['administrator.profile.activeAvatar',
            'members.user.profile.activeAvatar']);
        $election = $opportunity->elections()->where('status', 'open')->latest('id')
            ->with(['candidates.user', 'votes'])->first();
        $count = count($opportunity->electorate());
        $userVote = $election?->votes->firstWhere('user_id', $viewer->id);
        $venues = Venue::query()
            ->whereIn('id', array_slice((array) ($opportunity->common_venue_ids ?? []), 0, 12))
            ->with(['location.address'])
            ->get()->keyBy('id');

        $preferences = $opportunity->venuePreferences()
            ->whereIn('venue_id', $venues->keys()->all())->get();
        $ranked = $venues->map(function (Venue $venue) use ($preferences, $viewer): array {
            $votes = $preferences->where('venue_id', $venue->id);
            $ratings = $votes->pluck('rating')->filter(fn ($score) => $score !== null);
            return [
                'venue' => $venue,
                'my' => $votes->firstWhere('user_id', $viewer->id),
                'rating_count' => $ratings->count(),
                'average' => $ratings->isEmpty() ? null : round($ratings->avg(), 1),
                'supporters' => $ratings->filter(fn ($score) => $score >= 7)->count(),
                'not_suitable' => $votes->where('unsuitable', true)->count(),
            ];
        })->sort(function (array $a, array $b):int {
            return $b['supporters'] <=> $a['supporters']
                ?: ($b['average'] ?? -1) <=> ($a['average'] ?? -1)
                ?: $a['venue']->id <=> $b['venue']->id;
        })->values();

        $wall = SocialWallEntry::query()->where('scope_type', 'play_opportunity')
            ->where('scope_id', $opportunity->id)
            ->with(['actor', 'target'])
            ->orderByDesc('id')->limit(60)->get()->reverse()->values();
        $assemblies = $opportunity->assemblies()->with('event')
            ->orderByDesc('id')->limit(10)->get();

        return ThemeResolver::page('play.opportunity', [
            'opportunity' => $opportunity,
            'viewer' => $viewer,
            'isGroupAdmin' => $opportunity->isAdministrator($viewer),
            'election' => $election,
            'memberCount' => $count,
            'userVote' => $userVote,
            'rankedVenues' => $ranked,
            'wall' => $wall,
            'assemblies' => $assemblies,
        ])->header('Cache-Control', 'private, no-store');
    }
}
