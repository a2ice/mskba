<?php

namespace App\Modules\Tournament\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Tournament\Application\Services\TournamentMatchSchedulingService;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class TournamentMatchSchedulingController extends Controller
{
    public function __invoke(Request $request, string $tournament, TournamentMatch $match, TournamentMatchSchedulingService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $data = $request->validate([
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'booking_scope' => ['nullable', Rule::enum(VenueBookingScopeEnum::class)],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'game_format' => ['required', Rule::enum(GameFormatEnum::class), Rule::notIn([GameFormatEnum::CUSTOM->value])],
            'timing_mode' => ['required', Rule::enum(GameTimingModeEnum::class)],
            'periods_count' => ['nullable', 'integer', Rule::in([2, 4])],
        ]);
        try {
            $event = $service->schedule(
                Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail(),
                $match,
                $actors->resolveForRequest($request) ?? abort(403),
                $data,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('events.show', $event->routeIdentifier())->with('status', 'Матч назначен. Игра и бронирование созданы.');
    }

    public function update(Request $request, string $tournament, TournamentMatch $match, TournamentMatchSchedulingService $service, CurrentActorResolver $actors): RedirectResponse
    {
        $data = $request->validate([
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'booking_scope' => ['nullable', Rule::enum(VenueBookingScopeEnum::class)],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);
        try {
            $event = $service->reschedule(
                Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail(),
                $match->loadMissing('game.event'),
                $actors->resolveForRequest($request) ?? abort(403),
                $data,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('events.show', $event->routeIdentifier())->with('status', 'Матч перенесён, бронирование обновлено.');
    }
}
