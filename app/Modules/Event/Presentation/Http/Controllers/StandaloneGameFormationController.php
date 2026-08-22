<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\StandaloneGameFormationService;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Tournament\Domain\Enums\TournamentAssessmentSourceEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class StandaloneGameFormationController extends Controller
{
    public function preview(
        Request $request,
        string $event,
        int $game,
        StandaloneGameFormationService $service,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $data = $request->validate([
            'assessment_source' => ['required', Rule::enum(TournamentAssessmentSourceEnum::class)],
            'seed' => ['nullable', 'integer', 'min:0'],
        ]);
        try {
            $preview = $service->previewBalanced(
                $this->game($event, $game),
                $actors->resolveForRequest($request) ?? abort(403),
                TournamentAssessmentSourceEnum::from($data['assessment_source'])->value,
                (int) ($data['seed'] ?? random_int(1, PHP_INT_MAX)),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($preview);
    }

    public function apply(
        Request $request,
        string $event,
        int $game,
        StandaloneGameFormationService $service,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $presets = array_map(fn (int $number): string => sprintf('crest-%02d', $number), range(0, 14));
        $data = $request->validate([
            'pool_fingerprint' => ['required', 'string', 'size:64'],
            'teams' => ['required', 'array', 'size:2'],
            'teams.*.number' => ['required', 'integer', Rule::in([1, 2])],
            'teams.*.name' => ['required', 'string', 'max:150'],
            'teams.*.logo_preset' => ['required', 'string', Rule::in($presets)],
            'teams.*.user_ids' => ['required', 'array', 'min:1'],
            'teams.*.user_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        try {
            $service->applyBalanced(
                $this->game($event, $game),
                $actors->resolveForRequest($request) ?? abort(403),
                $data['pool_fingerprint'],
                $data['teams'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Стороны утверждены.']);
    }

    public function confirmTeams(
        Request $request,
        string $event,
        int $game,
        StandaloneGameFormationService $service,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $data = $request->validate([
            'team_a_id' => ['required', 'integer', 'different:team_b_id', 'exists:teams,id'],
            'team_b_id' => ['required', 'integer', 'different:team_a_id', 'exists:teams,id'],
        ]);
        try {
            $service->confirmTeams(
                $this->game($event, $game),
                $actors->resolveForRequest($request) ?? abort(403),
                (int) $data['team_a_id'],
                (int) $data['team_b_id'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Стороны утверждены.']);
    }

    public function unconfirm(
        Request $request,
        string $event,
        int $game,
        StandaloneGameFormationService $service,
        CurrentActorResolver $actors,
    ): JsonResponse {
        try {
            $service->unconfirm(
                $this->game($event, $game),
                $actors->resolveForRequest($request) ?? abort(403),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Утверждение сторон снято. Набор и формирование снова доступны.']);
    }

    public function applications(
        Request $request,
        string $event,
        int $game,
        StandaloneGameFormationService $service,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        try {
            $service->setApplicationsEnabled(
                $this->game($event, $game),
                $actors->resolveForRequest($request) ?? abort(403),
                (bool) $data['enabled'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => (bool) $data['enabled'] ? 'Приём заявок включён.' : 'Приём новых заявок выключен.',
        ]);
    }

    public function configuration(
        Request $request,
        string $event,
        int $game,
        StandaloneGameFormationService $service,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $data = $request->validate([
            'game_format' => ['required', Rule::enum(GameFormatEnum::class)],
            'side_a_size' => ['required', 'integer', 'min:1', 'max:7'],
            'side_b_size' => ['required', 'integer', 'min:1', 'max:7'],
            'scoring_type' => ['required', Rule::enum(GameScoringTypeEnum::class)],
            'timing_mode' => ['required', Rule::enum(GameTimingModeEnum::class)],
            'periods_count' => ['nullable', 'integer', Rule::in([2, 4])],
        ]);
        try {
            $service->updateConfiguration(
                $this->game($event, $game),
                $actors->resolveForRequest($request) ?? abort(403),
                (int) $data['side_a_size'],
                (int) $data['side_b_size'],
                GameScoringTypeEnum::from($data['scoring_type']),
                GameFormatEnum::from($data['game_format']),
                GameTimingModeEnum::from($data['timing_mode']),
                isset($data['periods_count']) ? (int) $data['periods_count'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Основные параметры игры сохранены.']);
    }

    private function game(string $event, int $game): Game
    {
        $eventModel = Event::query()->whereRouteIdentifier($event)->firstOrFail();
        $gameModel = Game::query()->whereKey($game)->whereBelongsTo($eventModel)->firstOrFail();
        abort_if($eventModel->type !== EventTypeEnum::GAME || (int) $eventModel->primary_game_id !== $gameModel->id, 404);

        return $gameModel;
    }
}
