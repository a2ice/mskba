<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventGamePlanningTeamSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'exclude_id' => ['nullable', 'integer', 'min:1'],
        ]);

        // The quick-create wizard is available before authentication.
        // Return only information already exposed by the public team catalog.
        // Planning a team does not imply consent to participate or receive invitations.
        $teams = Team::query()
            ->competitionEligible()
            ->with('logo')
            ->when(isset($data['exclude_id']), fn ($query) => $query->where('id', '!=', $data['exclude_id']))
            ->whereLike('name', '%'.trim($data['q']).'%')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(15)
            ->get()
            ->map(fn (Team $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'logo_url' => $team->logo?->publicUrl() ?: asset('images/team-placeholder.webp'),
            ]);

        return response()->json(['teams' => $teams->values()]);
    }
}
