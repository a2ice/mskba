<?php

namespace App\Modules\Tournament\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Application\Services\TournamentAccess;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TournamentCandidateSearchController extends Controller
{
    public function __invoke(Request $request, string $tournament, CurrentActorResolver $actors, TournamentAccess $access, SearchDiscoverableUsers $users): JsonResponse
    {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TournamentPermissionEnum::MANAGE_GAMES), 403);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        if ($item->recruitment_mode === TournamentRecruitmentModeEnum::PREFORMED_TEAMS) {
            $excluded = $item->admissions()->whereIn('status', [
                TournamentAdmissionStatusEnum::PENDING->value,
                TournamentAdmissionStatusEnum::ACCEPTED->value,
            ])->whereNotNull('team_id')->pluck('team_id');
            $candidates = Team::query()
                ->competitionEligible()
                ->where('accepts_competition_invitations', true)
                ->whereNotIn('id', $excluded)
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($data['q']).'%'])
                ->orderBy('name')->limit(10)->get()
                ->map(fn (Team $team): array => ['id' => $team->id, 'name' => $team->name, 'meta' => 'Команда #'.$team->id]);
        } else {
            $excluded = $item->admissions()->whereIn('status', [
                TournamentAdmissionStatusEnum::PENDING->value,
                TournamentAdmissionStatusEnum::ACCEPTED->value,
            ])->whereNotNull('user_id')->pluck('user_id')->all();
            $candidates = $users->handle(
                $actor->user,
                $data['q'],
                $excluded,
                requiredAccess: UserPrivacySettingTypeEnum::GROUP_INVITATIONS,
            )->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')) ?: $user->username,
                'meta' => '@'.$user->username,
            ]);
        }

        return response()->json(['candidates' => $candidates->values()]);
    }
}
