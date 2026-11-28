<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventGamePlanningService;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class EventGamePlanningController extends Controller
{
    public function store(Request $request, string $event, CurrentActorResolver $actors, EventGamePlanningService $planning): JsonResponse
    {
        $parent = $this->event($event);
        $actor = $actors->resolveForRequest($request) ?? abort(403);

        try {
            $game = $planning->add($parent, $actor, $request->all());
        } catch (ValidationException $exception) {
            throw $this->fieldErrors($exception);
        }

        return response()->json(['game_id' => $game->id], 201);
    }

    public function update(Request $request, string $event, int $game, CurrentActorResolver $actors, EventGamePlanningService $planning): JsonResponse
    {
        $parent = $this->event($event);
        $actor = $actors->resolveForRequest($request) ?? abort(403);
        $item = $this->game($parent, $game);

        try {
            $updated = $planning->edit($parent, $item, $actor, $request->all());
        } catch (ValidationException $exception) {
            throw $this->fieldErrors($exception);
        }

        return response()->json(['game_id' => $updated->id]);
    }

    public function destroy(Request $request, string $event, int $game, CurrentActorResolver $actors, EventGamePlanningService $planning): JsonResponse
    {
        $parent = $this->event($event);
        $actor = $actors->resolveForRequest($request) ?? abort(403);
        $data = $request->validate(['notify' => ['sometimes', 'required', 'boolean'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $planning->remove($parent, $this->game($parent, $game), $actor,
            array_key_exists('notify', $data) ? $request->boolean('notify') : null, $data['reason'] ?? null);

        return response()->json(['message' => 'Игра удалена.']);
    }

    private function event(string $identifier): Event
    {
        return Event::query()->whereRouteIdentifier($identifier)->firstOrFail();
    }

    private function game(Event $event, int $id): Game
    {
        return $event->games()->whereKey($id)->where('uses_event_planning', true)->firstOrFail();
    }

    private function fieldErrors(ValidationException $exception): ValidationException
    {
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $errors[str_starts_with($field, 'games.0.') ? substr($field, 8) : $field] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
