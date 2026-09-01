<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamHiringStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamHiringPosition;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class TeamHiringController extends Controller
{
    private const int ACTIVE_LIMIT = 20;

    public function index(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): Response {
        [$item, $actor] = $this->authorizedTeam($team, $request, $actors, $access);

        return ThemeResolver::page('teams.hiring', [
            'team' => $item,
            'hiringPositions' => $item->hiringPositions()->orderByRaw("case status when 'active' then 0 else 1 end")->latest()->get(),
            'playerPositions' => PlayerPositionEnum::cases(),
            'genders' => UserGenderEnum::cases(),
            'canEditSettings' => $access->allows($item, $actor, TeamPermissionEnum::EDIT_SETTINGS),
            'canManageMembersAndRoster' => $access->canManageMembersAndRoster($item, $actor),
            'canManageJoinRequests' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_JOIN_REQUESTS),
            'canManageVenues' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_VENUES),
        ]);
    }

    public function store(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): RedirectResponse {
        [$item, $actor] = $this->authorizedTeam($team, $request, $actors, $access);
        abort_if($item->isTemporary(), 422, 'Временная команда не может открывать вакансии.');
        abort_if($item->status !== TeamStatusEnum::ACTIVE, 422, 'Открывать вакансии может только активная команда.');
        $data = $this->validatedData($request, 'createHiring');

        DB::transaction(function () use ($item, $actor, $data): void {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($item->id);
            abort_if(
                $lockedTeam->hiringPositions()->available()->count() >= self::ACTIVE_LIMIT,
                422,
                'Можно одновременно открыть не более 20 вакансий.',
            );
            $lockedTeam->hiringPositions()->create([
                ...$data,
                'status' => TeamHiringStatusEnum::ACTIVE,
                'spots_filled' => 0,
                'created_by_user_id' => $actor->user->canonical()->id,
            ]);
        });

        return back()->with('status', 'Вакансия открыта.');
    }

    public function update(
        string $team,
        int $hiringPosition,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): RedirectResponse {
        [$item] = $this->authorizedTeam($team, $request, $actors, $access);
        $data = $this->validatedData($request, 'hiring'.$hiringPosition);

        DB::transaction(function () use ($item, $hiringPosition, $data): void {
            Team::query()->lockForUpdate()->findOrFail($item->id);
            $position = TeamHiringPosition::query()
                ->where('team_id', $item->id)
                ->whereKey($hiringPosition)
                ->lockForUpdate()
                ->firstOrFail();
            abort_if(
                $data['spots_total'] < $position->spots_filled,
                422,
                'Количество мест не может быть меньше числа уже принятых игроков.',
            );

            $position->update($data);
            if ($position->status === TeamHiringStatusEnum::ACTIVE && $position->remainingSpots() === 0) {
                $position->update(['status' => TeamHiringStatusEnum::CLOSED, 'closed_at' => now()]);
            }
        });

        return back()->with('status', 'Вакансия обновлена.');
    }

    public function status(
        string $team,
        int $hiringPosition,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): RedirectResponse {
        [$item] = $this->authorizedTeam($team, $request, $actors, $access);
        $data = $request->validate(['action' => ['required', Rule::in(['close', 'reopen'])]]);

        DB::transaction(function () use ($item, $hiringPosition, $data): void {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($item->id);
            $position = TeamHiringPosition::query()
                ->where('team_id', $item->id)
                ->whereKey($hiringPosition)
                ->lockForUpdate()
                ->firstOrFail();

            if ($data['action'] === 'reopen') {
                abort_if($lockedTeam->status !== TeamStatusEnum::ACTIVE, 422, 'Повторно открыть вакансию может только активная команда.');
                abort_if($position->remainingSpots() === 0, 422, 'Увеличьте количество мест перед повторным открытием.');
                $position->update(['status' => TeamHiringStatusEnum::ACTIVE, 'closed_at' => null]);

                return;
            }

            $position->update(['status' => TeamHiringStatusEnum::CLOSED, 'closed_at' => now()]);
        });

        return back()->with('status', $data['action'] === 'reopen' ? 'Вакансия снова открыта.' : 'Вакансия закрыта.');
    }

    /** @return array{0: Team, 1: Actor} */
    private function authorizedTeam(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): array {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::MANAGE_HIRING), 403);

        return [$item, $actor];
    }

    /** @return array{spots_total: int, positions: array<int, string>|null, minimum_experience_years: int|null, gender: string|null, description: string|null} */
    private function validatedData(Request $request, string $errorBag): array
    {
        $data = $request->validateWithBag($errorBag, [
            'spots_total' => ['required', 'integer', 'min:1', 'max:100'],
            'positions' => ['nullable', 'array'],
            'positions.*' => ['required', 'distinct', Rule::enum(PlayerPositionEnum::class)],
            'minimum_experience_years' => ['nullable', 'integer', 'min:0', 'max:60'],
            'gender' => ['nullable', Rule::enum(UserGenderEnum::class)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        return [
            'spots_total' => (int) $data['spots_total'],
            'positions' => ($data['positions'] ?? []) === [] ? null : array_values($data['positions']),
            'minimum_experience_years' => isset($data['minimum_experience_years']) ? (int) $data['minimum_experience_years'] : null,
            'gender' => filled($data['gender'] ?? null) ? $data['gender'] : null,
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
        ];
    }
}
