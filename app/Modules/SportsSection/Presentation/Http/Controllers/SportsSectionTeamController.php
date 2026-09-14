<?php

namespace App\Modules\SportsSection\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Application\UseCases\ManageSportsSectionTeamHandler;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SportsSectionTeamController extends Controller
{
    public function index(
        Request $request,
        SportsSection $sportsSection,
        CurrentActorResolver $actors,
        SportsSectionAccess $sectionAccess,
        TeamManagementAccess $teamAccess,
    ): Response {
        $this->guardFeature();
        $user = $request->user()->canonical();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $sectionAccess->allows($user, $sportsSection, SportsSectionPermissionEnum::MANAGE), 403);

        $sportsSection->load(['teams.logo']);
        $linkedIds = $sportsSection->teams->modelKeys();
        $availableTeams = Team::query()
            ->whereNull('temporary_for_event_id')
            ->when($linkedIds !== [], fn ($query) => $query->whereNotIn('id', $linkedIds))
            ->with('logo')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->filter(fn (Team $team): bool => $teamAccess->allows($team, $actor, TeamPermissionEnum::EDIT_SETTINGS))
            ->values();

        return ThemeResolver::page('account.sports-sections.teams', [
            'section' => $sportsSection,
            'availableTeams' => $availableTeams,
        ]);
    }

    public function store(
        Request $request,
        SportsSection $sportsSection,
        CurrentActorResolver $actors,
        ManageSportsSectionTeamHandler $handler,
    ): RedirectResponse {
        $this->guardFeature();
        $data = $request->validate(['team_id' => ['required', 'integer', 'exists:teams,id']]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $handler->link($sportsSection, Team::query()->findOrFail($data['team_id']), $request->user(), $actor);
        } catch (SportsSectionException $exception) {
            return back()->withErrors(['section' => $exception->getMessage()]);
        }

        return back()->with('status', 'Команда связана с секцией.');
    }

    public function destroy(
        Request $request,
        SportsSection $sportsSection,
        Team $team,
        ManageSportsSectionTeamHandler $handler,
    ): RedirectResponse {
        $this->guardFeature();

        try {
            $handler->unlink($sportsSection, $team, $request->user());
        } catch (SportsSectionException $exception) {
            return back()->withErrors(['section' => $exception->getMessage()]);
        }

        return back()->with('status', 'Связь с командой удалена.');
    }

    private function guardFeature(): void
    {
        abort_unless(config('features.sports_sections.enabled'), 404);
    }
}
