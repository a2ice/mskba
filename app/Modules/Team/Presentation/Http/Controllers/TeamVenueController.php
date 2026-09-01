<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamVenueRelationTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamVenueRelation;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class TeamVenueController extends Controller
{
    public function index(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): Response {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::MANAGE_VENUES), 403);

        $relations = $item->venueRelations()
            ->with('venue.location.address')
            ->orderBy('created_at')
            ->get();

        return ThemeResolver::page('teams.venues', [
            'team' => $item,
            'venueRelations' => $relations,
            'canEditSettings' => $access->allows($item, $actor, TeamPermissionEnum::EDIT_SETTINGS),
            'canManageMembersAndRoster' => $access->canManageMembersAndRoster($item, $actor),
            'canManageJoinRequests' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_JOIN_REQUESTS),
            'canManageHiring' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_HIRING),
        ]);
    }

    public function store(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): RedirectResponse {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::MANAGE_VENUES), 403);
        abort_if($item->isTemporary(), 422, 'Временной команде нельзя назначать желаемые площадки.');

        $data = $request->validate(['venue_id' => ['required', 'integer']]);
        $venue = Venue::query()
            ->whereKey($data['venue_id'])
            ->where('status', VenueStatusEnum::CONFIRMED)
            ->first();
        abort_if($venue === null, 422, 'Можно выбрать только подтверждённую площадку.');

        $created = DB::transaction(function () use ($item, $venue, $actor): bool {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($item->id);
            $existing = $lockedTeam->venueRelations()->where('venue_id', $venue->id)->first();
            if ($existing !== null) {
                abort_if(
                    $existing->relation_type === TeamVenueRelationTypeEnum::CONFIRMED,
                    422,
                    'Связь с этой площадкой уже подтверждена.',
                );

                return false;
            }

            $lockedTeam->venueRelations()->create([
                'venue_id' => $venue->id,
                'relation_type' => TeamVenueRelationTypeEnum::DESIRED,
                'created_by_user_id' => $actor->user->canonical()->id,
            ]);

            return true;
        });

        return back()->with('status', $created ? 'Площадка добавлена.' : 'Площадка уже добавлена.');
    }

    public function destroy(
        string $team,
        int $relation,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): RedirectResponse {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::MANAGE_VENUES), 403);

        DB::transaction(function () use ($item, $relation): void {
            Team::query()->lockForUpdate()->findOrFail($item->id);
            $venueRelation = TeamVenueRelation::query()
                ->where('team_id', $item->id)
                ->whereKey($relation)
                ->lockForUpdate()
                ->firstOrFail();
            abort_if(
                $venueRelation->relation_type !== TeamVenueRelationTypeEnum::DESIRED,
                422,
                'Подтверждённую связь нельзя удалить из этого раздела.',
            );
            $venueRelation->delete();
        });

        return back()->with('status', 'Площадка удалена из желаемых.');
    }
}
