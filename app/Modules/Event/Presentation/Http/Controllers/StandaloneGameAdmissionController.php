<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\GameAdmissionService;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Enums\TournamentAssessmentSourceEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class StandaloneGameAdmissionController extends Controller
{
    public function panel(
        Request $request,
        string $event,
        int $game,
        CurrentActorResolver $actors,
        EventManagementAccess $eventAccess,
        TeamManagementAccess $teamAccess,
    ): Response {
        $item = $this->game($event, $game)->load([
            'event',
            'admissions.team',
            'admissions.user.profile.activeAvatar',
            'sides.team.logo',
            'rosterEntries.user.profile.activeAvatar',
        ]);
        $actor = $actors->resolveForRequest($request);
        $management = $request->boolean('management');
        $canManage = $actor !== null
            && $eventAccess->allows($item->event, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
        if ($management && ! $canManage) {
            abort(403);
        }
        $publiclyVisible = $item->event->status === EventStatusEnum::PUBLISHED
            && $item->event->visibility === EventVisibilityEnum::PUBLIC;
        if (! $publiclyVisible && ! $canManage) {
            abort(404);
        }
        if ($item->recruitment_mode === null) {
            return response('', 204);
        }

        $manageableTeams = collect();
        $relevantAdmissions = collect();
        if ($actor !== null) {
            if ($item->recruitment_mode === GameRecruitmentModeEnum::PREFORMED_TEAMS) {
                $manageableTeams = Team::query()
                    ->whereNull('temporary_for_event_id')
                    ->where('status', TeamStatusEnum::ACTIVE->value)
                    ->orderBy('name')
                    ->get()
                    ->filter(fn (Team $team): bool => $teamAccess->allows(
                        $team,
                        $actor,
                        TeamPermissionEnum::MANAGE_GAME_PARTICIPATION,
                    ))
                    ->values();
            }
            $relevantAdmissions = $item->admissions->filter(
                fn (GameAdmission $admission): bool => $this->candidateMayAct($admission, $actor, $teamAccess),
            )->values();
        }

        return response()->view('theme::pages.events.partials.standalone-recruitment', [
            'event' => $item->event,
            'game' => $item,
            'managementMode' => $management,
            'canManageRecruitment' => $canManage,
            'manageableTeams' => $manageableTeams,
            'relevantAdmissions' => $relevantAdmissions,
            'assessmentSources' => TournamentAssessmentSourceEnum::cases(),
        ]);
    }

    public function apply(
        Request $request,
        string $event,
        int $game,
        CurrentActorResolver $actors,
        GameAdmissionService $admissions,
    ): RedirectResponse|JsonResponse {
        $item = $this->game($event, $game);
        $actor = $actors->resolveForRequest($request) ?? abort(403);

        try {
            if ($item->recruitment_mode === GameRecruitmentModeEnum::PREFORMED_TEAMS) {
                $data = $request->validate(['team_id' => ['required', 'integer', 'exists:teams,id']]);
                $candidate = Team::query()->findOrFail((int) $data['team_id']);
            } else {
                $candidate = $actor->user?->canonical()
                    ?? throw new InvalidArgumentException('Для заявки нужен аккаунт пользователя.');
            }
            $admissions->apply($item, $actor, $candidate);
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, $exception);
        }

        return $this->success($request, 'Заявка отправлена.');
    }

    public function invite(
        Request $request,
        string $event,
        int $game,
        CurrentActorResolver $actors,
        GameAdmissionService $admissions,
    ): RedirectResponse|JsonResponse {
        $item = $this->game($event, $game);
        $actor = $actors->resolveForRequest($request) ?? abort(403);
        try {
            if ($item->recruitment_mode === GameRecruitmentModeEnum::PREFORMED_TEAMS) {
                $data = $request->validate(['team_id' => ['required', 'integer', 'exists:teams,id']]);
                $candidate = Team::query()->findOrFail((int) $data['team_id']);
                if (! $candidate->acceptsCompetitionInvitations()) {
                    throw new InvalidArgumentException('Команда запретила приглашения в игры и турниры.');
                }
            } else {
                $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
                $candidate = User::query()->findOrFail((int) $data['user_id'])->canonical();
            }
            $admissions->invite($item, $actor, $candidate);
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, $exception);
        }

        return $this->success($request, 'Приглашение отправлено.');
    }

    public function respond(
        Request $request,
        string $event,
        int $game,
        int $admission,
        CurrentActorResolver $actors,
        GameAdmissionService $admissions,
    ): RedirectResponse|JsonResponse {
        $item = $this->game($event, $game);
        $actor = $actors->resolveForRequest($request) ?? abort(403);
        $data = $request->validate([
            'decision' => ['required', Rule::in([
                GameAdmissionStatusEnum::ACCEPTED->value,
                GameAdmissionStatusEnum::DECLINED->value,
            ])],
            'response_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $admissions->respond(
                $item,
                GameAdmission::query()->findOrFail($admission),
                $actor,
                GameAdmissionStatusEnum::from($data['decision']),
                $data['response_comment'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, $exception);
        }

        return $this->success($request, 'Ответ сохранён.');
    }

    public function revoke(
        Request $request,
        string $event,
        int $game,
        int $admission,
        CurrentActorResolver $actors,
        GameAdmissionService $admissions,
    ): RedirectResponse|JsonResponse {
        $item = $this->game($event, $game);
        $actor = $actors->resolveForRequest($request) ?? abort(403);
        try {
            $admissions->revoke(
                $item,
                GameAdmission::query()->findOrFail($admission),
                $actor,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, $exception);
        }

        return $this->success($request, 'Заявка отозвана.');
    }

    private function game(string $event, int $game): Game
    {
        $eventModel = Event::query()->whereRouteIdentifier($event)->firstOrFail();
        $gameModel = Game::query()->whereKey($game)->whereBelongsTo($eventModel)->firstOrFail();
        abort_if($eventModel->type !== EventTypeEnum::GAME || (int) $eventModel->primary_game_id !== $gameModel->id, 404);

        return $gameModel;
    }

    private function candidateMayAct(
        GameAdmission $admission,
        Actor $actor,
        TeamManagementAccess $teamAccess,
    ): bool {
        if ($admission->candidate_type === GameAdmissionCandidateTypeEnum::TEAM && $admission->team !== null) {
            return $teamAccess->allows(
                $admission->team,
                $actor,
                TeamPermissionEnum::MANAGE_GAME_PARTICIPATION,
            );
        }
        if ($admission->candidate_type === GameAdmissionCandidateTypeEnum::USER && $admission->user !== null) {
            return $actor->user?->canonical()?->id === $admission->user->canonical()->id;
        }

        return false;
    }

    private function success(Request $request, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : back()->with('status', $message);
    }

    private function error(Request $request, InvalidArgumentException $exception): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $exception->getMessage()], 422)
            : back()->with('error', $exception->getMessage());
    }
}
