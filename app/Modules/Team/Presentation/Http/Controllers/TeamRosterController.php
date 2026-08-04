<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Application\Services\TeamRosterService;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class TeamRosterController extends Controller
{
    public function update(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access, TeamRosterService $rosters): JsonResponse
    {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::MANAGE_ROSTER), 403);
        $data = $request->validate([
            'sport_type' => ['required', Rule::enum(TeamSportTypeEnum::class)],
            'starter_ids' => ['present', 'array'],
            'starter_ids.*' => ['integer', 'distinct'],
            'reserve_ids' => ['present', 'array'],
            'reserve_ids.*' => ['integer', 'distinct'],
        ]);

        try {
            $rosters->save($item, TeamSportTypeEnum::from($data['sport_type']), $data['starter_ids'], $data['reserve_ids']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Состав сохранён.']);
    }
}
