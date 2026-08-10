<?php

namespace App\Modules\Tournament\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Tournament\Application\Services\TournamentFormationService;
use App\Modules\Tournament\Domain\Enums\TournamentAssessmentSourceEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class TournamentFormationController extends Controller
{
    public function preview(Request $request, string $tournament, TournamentFormationService $service, CurrentActorResolver $actors): JsonResponse
    {
        $data = $request->validate([
            'assessment_source' => ['required', Rule::enum(TournamentAssessmentSourceEnum::class)],
            'team_count' => ['required', 'integer', 'min:2', 'max:64'],
            'seed' => ['nullable', 'integer', 'min:0'],
        ]);
        try {
            $preview = $service->preview(
                Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail(),
                $actors->resolveForRequest($request) ?? abort(403),
                TournamentAssessmentSourceEnum::from($data['assessment_source']),
                (int) $data['team_count'],
                (int) ($data['seed'] ?? random_int(1, PHP_INT_MAX)),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($preview);
    }

    public function apply(Request $request, string $tournament, TournamentFormationService $service, CurrentActorResolver $actors): JsonResponse
    {
        $data = $request->validate([
            'pool_fingerprint' => ['required', 'string', 'size:64'],
            'teams' => ['required', 'array', 'min:2'],
            'teams.*.number' => ['required', 'integer', 'min:1'],
            'teams.*.user_ids' => ['required', 'array'],
            'teams.*.user_ids.*' => ['required', 'integer'],
        ]);
        try {
            $service->apply(
                Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail(),
                $actors->resolveForRequest($request) ?? abort(403),
                $data['pool_fingerprint'],
                $data['teams'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Составы утверждены.']);
    }
}
