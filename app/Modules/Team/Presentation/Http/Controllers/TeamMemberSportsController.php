<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamMemberSportsService;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class TeamMemberSportsController extends Controller
{
    public function __invoke(
        Request $request,
        string $team,
        int $membership,
        CurrentActorResolver $actors,
        TeamMemberSportsService $members,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        $data = $request->validate([
            'sport_roles' => ['nullable', 'array'],
            'sport_roles.*' => ['required', 'distinct', Rule::enum(TeamMemberTypeEnum::class)],
            'is_captain' => ['nullable', 'boolean'],
            'is_default_starter' => ['nullable', 'boolean'],
        ]);

        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $teamMembership = ContractMembership::query()->findOrFail($membership);

        try {
            $members->update(
                $item,
                $teamMembership,
                $actor,
                $data['sport_roles'] ?? [],
                (bool) ($data['is_captain'] ?? false),
                (bool) ($data['is_default_starter'] ?? false),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Спортивные роли участника обновлены.');
    }
}
