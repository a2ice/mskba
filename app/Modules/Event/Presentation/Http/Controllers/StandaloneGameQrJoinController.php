<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\StandaloneGameQrJoinService;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class StandaloneGameQrJoinController extends Controller
{
    public function __invoke(
        Request $request,
        string $event,
        int $game,
        StandaloneGameQrJoinService $service,
    ): Response {
        [$eventModel, $gameModel] = $this->models($event, $game);
        abort_unless($eventModel->visibility === EventVisibilityEnum::PUBLIC, 404);

        $identityIds = $request->user()?->canonical()->identityIds() ?? [];
        $latestAdmission = $identityIds === []
            ? null
            : $gameModel->admissions()
                ->where('candidate_type', GameAdmissionCandidateTypeEnum::USER->value)
                ->whereIn('user_id', $identityIds)
                ->latest('id')
                ->first();
        $assignedSide = $identityIds === []
            ? null
            : $gameModel->rosterEntries()
                ->with('gameSide')
                ->whereIn('user_id', $identityIds)
                ->latest('id')
                ->first()?->gameSide;

        return ThemeResolver::page('events.game-qr-join', [
            'event' => $eventModel->loadMissing(['venue', 'organizerActor.user.profile']),
            'game' => $gameModel,
            'latestAdmission' => $latestAdmission,
            'assignedSide' => $assignedSide,
            'available' => $service->isAvailable($eventModel, $gameModel),
        ]);
    }

    public function apply(
        Request $request,
        string $event,
        int $game,
        CurrentActorResolver $actors,
        StandaloneGameQrJoinService $service,
    ): RedirectResponse|JsonResponse {
        [, $gameModel] = $this->models($event, $game);

        try {
            $service->apply($gameModel, $actors->resolveForRequest($request) ?? abort(403));
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, $exception);
        }

        return $this->success($request, 'Заявка отправлена организатору.');
    }

    public function revoke(
        Request $request,
        string $event,
        int $game,
        int $admission,
        CurrentActorResolver $actors,
        StandaloneGameQrJoinService $service,
    ): RedirectResponse|JsonResponse {
        [, $gameModel] = $this->models($event, $game);

        try {
            $service->revoke(
                $gameModel,
                GameAdmission::query()->findOrFail($admission),
                $actors->resolveForRequest($request) ?? abort(403),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, $exception);
        }

        return $this->success($request, 'Заявка отозвана.');
    }

    public function panel(
        Request $request,
        string $event,
        int $game,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        StandaloneGameQrJoinService $service,
    ): Response {
        [$eventModel, $gameModel] = $this->models($event, $game);
        $actor = $actors->resolveForRequest($request) ?? abort(403);
        $access->assertAllows($eventModel, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);

        $pending = $gameModel->admissions()
            ->with('user.profile.activeAvatar')
            ->where('candidate_type', GameAdmissionCandidateTypeEnum::USER->value)
            ->where('direction', GameAdmissionDirectionEnum::APPLICATION->value)
            ->where('status', GameAdmissionStatusEnum::PENDING->value)
            ->latest('id')
            ->get();

        return response()->view('theme::pages.events.partials.game-qr-late-join', [
            'event' => $eventModel,
            'game' => $gameModel->loadMissing('sides'),
            'pendingAdmissions' => $pending,
            'available' => $service->isAvailable($eventModel, $gameModel),
        ]);
    }

    public function accept(
        Request $request,
        string $event,
        int $game,
        int $admission,
        CurrentActorResolver $actors,
        StandaloneGameQrJoinService $service,
    ): RedirectResponse|JsonResponse {
        $data = $request->validate(['side' => ['required', Rule::in(['A', 'B'])]]);
        [, $gameModel] = $this->models($event, $game);

        try {
            $service->acceptToSide(
                $gameModel,
                GameAdmission::query()->findOrFail($admission),
                $actors->resolveForRequest($request) ?? abort(403),
                $data['side'],
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, $exception);
        }

        return $this->success($request, 'Игрок принят и добавлен в выбранную сторону.');
    }

    public function decline(
        Request $request,
        string $event,
        int $game,
        int $admission,
        CurrentActorResolver $actors,
        StandaloneGameQrJoinService $service,
    ): RedirectResponse|JsonResponse {
        $data = $request->validate(['response_comment' => ['nullable', 'string', 'max:2000']]);
        [, $gameModel] = $this->models($event, $game);

        try {
            $service->decline(
                $gameModel,
                GameAdmission::query()->findOrFail($admission),
                $actors->resolveForRequest($request) ?? abort(403),
                $data['response_comment'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, $exception);
        }

        return $this->success($request, 'Заявка отклонена.');
    }

    public function applications(
        Request $request,
        string $event,
        int $game,
        CurrentActorResolver $actors,
        StandaloneGameQrJoinService $service,
    ): RedirectResponse|JsonResponse {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        [, $gameModel] = $this->models($event, $game);

        try {
            $service->setApplicationsEnabled(
                $gameModel,
                $actors->resolveForRequest($request) ?? abort(403),
                (bool) $data['enabled'],
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, $exception);
        }

        return $this->success(
            $request,
            (bool) $data['enabled'] ? 'Приём заявок включён.' : 'Приём новых заявок выключен.',
        );
    }

    /** @return array{Event, Game} */
    private function models(string $event, int $game): array
    {
        $eventModel = Event::query()->whereRouteIdentifier($event)->firstOrFail();
        $gameModel = Game::query()->whereKey($game)->whereBelongsTo($eventModel)->firstOrFail();

        abort_if(
            $eventModel->type !== EventTypeEnum::GAME
            || (int) $eventModel->primary_game_id !== $gameModel->id
            || $gameModel->recruitment_mode !== GameRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            404,
        );

        return [$eventModel, $gameModel];
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
