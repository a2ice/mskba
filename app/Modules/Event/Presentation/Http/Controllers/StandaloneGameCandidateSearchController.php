<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StandaloneGameCandidateSearchController extends Controller
{
    public function __invoke(
        Request $request,
        string $event,
        int $game,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        SearchDiscoverableUsers $users,
    ): JsonResponse {
        $eventModel = Event::query()->whereRouteIdentifier($event)->firstOrFail();
        $item = Game::query()->whereKey($game)->whereBelongsTo($eventModel)->firstOrFail();
        abort_if($eventModel->type !== EventTypeEnum::GAME || (int) $eventModel->primary_game_id !== $item->id, 404);
        $actor = $actors->resolveForRequest($request);
        abort_if(
            $actor === null
            || ! $access->allows($eventModel, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS),
            403,
        );
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        if ($item->recruitment_mode === GameRecruitmentModeEnum::PREFORMED_TEAMS) {
            $excluded = $item->admissions()->whereIn('status', [
                GameAdmissionStatusEnum::PENDING->value,
                GameAdmissionStatusEnum::ACCEPTED->value,
            ])->whereNotNull('team_id')->pluck('team_id');
            $candidates = Team::query()
                ->competitionInvitable()
                ->where('accepts_competition_invitations', true)
                ->whereNotIn('id', $excluded)
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($data['q']).'%'])
                ->orderBy('name')
                ->limit(10)
                ->get()
                ->map(fn (Team $team): array => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'meta' => 'Команда #'.$team->id,
                ]);
        } else {
            $excluded = $item->admissions()->whereIn('status', [
                GameAdmissionStatusEnum::PENDING->value,
                GameAdmissionStatusEnum::ACCEPTED->value,
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
