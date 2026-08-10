<?php

namespace App\Modules\Tournament\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Tournament\Application\Services\TournamentScheduleService;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class TournamentScheduleController extends Controller
{
    public function preview(Request $request, string $tournament, TournamentScheduleService $service, CurrentActorResolver $actors): JsonResponse
    {
        $data = $request->validate(['legs' => ['required', 'integer', Rule::in([1, 2])]]);

        try {
            $result = $service->preview(
                Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail(),
                $actors->resolveForRequest($request) ?? abort(403),
                (int) $data['legs'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function apply(Request $request, string $tournament, TournamentScheduleService $service, CurrentActorResolver $actors): JsonResponse
    {
        $data = $request->validate([
            'legs' => ['required', 'integer', Rule::in([1, 2])],
            'entries_fingerprint' => ['required', 'string', 'size:64'],
        ]);

        try {
            $service->apply(
                Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail(),
                $actors->resolveForRequest($request) ?? abort(403),
                (int) $data['legs'],
                $data['entries_fingerprint'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Круговая схема сохранена.']);
    }
}
