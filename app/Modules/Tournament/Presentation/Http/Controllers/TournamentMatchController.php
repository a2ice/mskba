<?php

namespace App\Modules\Tournament\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Tournament\Application\Services\TournamentMatchService;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentEntry;
use App\Modules\Tournament\Domain\Models\TournamentMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class TournamentMatchController extends Controller
{
    public function store(Request $request, string $tournament, TournamentMatchService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $data = $request->validate(['entry_a_id' => ['required', 'integer'], 'entry_b_id' => ['required', 'integer'], 'round' => ['nullable', 'integer', 'min:1', 'max:65535']]);
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();

        return $this->run(fn () => $service->create($item, TournamentEntry::query()->findOrFail($data['entry_a_id']), TournamentEntry::query()->findOrFail($data['entry_b_id']), $data['round'] ?? null, $actors->resolveForRequest($request) ?? abort(403)), 'Матч добавлен.');
    }

    public function reorder(Request $request, string $tournament, TournamentMatchService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $data = $request->validate(['positions' => ['required', 'array'], 'positions.*' => ['required', 'integer', 'min:1']]);
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        asort($data['positions'], SORT_NUMERIC);

        return $this->run(fn () => $service->reorder($item, array_map('intval', array_keys($data['positions'])), $actors->resolveForRequest($request) ?? abort(403)), 'Порядок матчей обновлён.');
    }

    public function destroy(Request $request, string $tournament, TournamentMatch $match, TournamentMatchService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();

        return $this->run(fn () => $service->delete($item, $match, $actors->resolveForRequest($request) ?? abort(403)), 'Матч удалён.');
    }

    private function run(callable $operation, string $message): RedirectResponse
    {
        try {
            $operation();
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', $message);
    }
}
