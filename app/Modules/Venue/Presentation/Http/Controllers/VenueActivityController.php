<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

final class VenueActivityController extends Controller
{
    public function __invoke(string $venue): JsonResponse
    {
        $venueModel = Venue::query()
            ->whereRouteIdentifier($venue)
            ->firstOrFail();
        $timezone = (string) config('app.timezone', 'Europe/Moscow');
        $now = CarbonImmutable::now($timezone);

        $events = Event::query()
            ->where('venue_id', $venueModel->id)
            ->where('status', EventStatusEnum::PUBLISHED->value)
            ->where('visibility', EventVisibilityEnum::PUBLIC->value)
            ->where(function ($query) use ($now): void {
                $query->where('ends_at', '>=', $now)
                    ->orWhereHas('primaryGame', fn ($game) => $game
                        ->whereNotNull('actual_started_at')
                        ->whereNull('actual_ended_at'));
            })
            ->with(['primaryGame.sides.team.logo'])
            ->orderBy('starts_at')
            ->limit(16)
            ->get()
            ->map(fn (Event $event): array => $this->eventPayload($event, $now))
            ->all();

        $tournaments = Tournament::query()
            ->where('default_venue_id', $venueModel->id)
            ->where('status', TournamentStatusEnum::CONFIRMED->value)
            ->whereDate('ends_on', '>=', $now->toDateString())
            ->with([
                'matches.game.event',
                'matches.game.sides.team.logo',
            ])
            ->orderBy('starts_on')
            ->limit(12)
            ->get()
            ->map(fn (Tournament $tournament): array => $this->tournamentPayload($tournament, $now))
            ->all();

        $activities = collect([...$events, ...$tournaments])
            ->sortBy(fn (array $activity): string => $activity['sort_at'])
            ->values();

        return response()->json([
            'venue_id' => (int) $venueModel->id,
            'operational_status' => $venueModel->operational_status->value,
            'current' => $activities->where('is_current', true)->values()->all(),
            'upcoming' => $activities->where('is_current', false)->take(8)->values()->all(),
            'generated_at' => $now->toISOString(),
        ]);
    }

    /** @return array<string, mixed> */
    private function eventPayload(Event $event, CarbonImmutable $now): array
    {
        $game = $event->primaryGame;
        $isLive = $game?->actual_started_at !== null && $game?->actual_ended_at === null;
        $isCurrent = $isLive || ($event->starts_at?->lessThanOrEqualTo($now) && $event->ends_at?->greaterThan($now));
        $typeLabel = match ($event->type) {
            EventTypeEnum::GAME => 'Игра',
            EventTypeEnum::GAME_TRAINING => 'Игровая тренировка',
            EventTypeEnum::TRAINING => 'Тренировка',
        };

        return [
            'kind' => 'event',
            'id' => (int) $event->id,
            'type' => $event->type->value,
            'type_label' => $typeLabel,
            'title' => $event->title,
            'is_current' => $isCurrent,
            'is_live' => $isLive,
            'status_label' => $isLive
                ? 'Идёт сейчас'
                : ($isCurrent ? 'Сейчас на площадке' : 'Запланировано'),
            'starts_at' => $event->starts_at?->setTimezone($now->timezone)->toISOString(),
            'ends_at' => $event->ends_at?->setTimezone($now->timezone)->toISOString(),
            'sort_at' => ($event->starts_at ?? $now)->toISOString(),
            'url' => route('events.show', $event->routeIdentifier()),
            ...$this->gamePayload($game, $event),
        ];
    }

    /** @return array<string, mixed> */
    private function tournamentPayload(Tournament $tournament, CarbonImmutable $now): array
    {
        $starts = $tournament->starts_on?->startOfDay();
        $ends = $tournament->ends_on?->endOfDay();
        $liveMatch = $tournament->matches
            ->first(fn ($match): bool => $match->game?->actual_started_at !== null && $match->game?->actual_ended_at === null);
        $liveGame = $liveMatch?->game;
        $isLive = $liveGame !== null;
        $isCurrent = $isLive || ($starts?->lessThanOrEqualTo($now) && $ends?->greaterThanOrEqualTo($now));

        return [
            'kind' => 'tournament',
            'id' => (int) $tournament->id,
            'type' => 'tournament',
            'type_label' => $isLive ? 'Турнир · матч' : 'Турнир',
            'title' => $tournament->title,
            'is_current' => $isCurrent,
            'is_live' => $isLive,
            'status_label' => $isLive
                ? 'Идёт матч турнира'
                : ($isCurrent ? 'Турнир идёт' : 'Предстоящий турнир'),
            'starts_at' => $isLive
                ? $liveGame->actual_started_at?->setTimezone($now->timezone)->toISOString()
                : $starts?->toISOString(),
            'ends_at' => $ends?->toISOString(),
            'sort_at' => ($isLive ? $liveGame->actual_started_at : $starts)?->toISOString() ?? $now->toISOString(),
            'url' => route('tournaments.show', $tournament->routeIdentifier()),
            ...$this->gamePayload($liveGame, $liveGame?->event),
        ];
    }

    /** @return array<string, mixed> */
    private function gamePayload(?Game $game, ?Event $event): array
    {
        if ($game === null) {
            return [
                'game_id' => null,
                'live_url' => null,
                'snapshot_url' => null,
                'teams' => null,
            ];
        }

        $sides = $game->sides->keyBy('slot');
        $routeEvent = $event?->routeIdentifier();

        return [
            'game_id' => (int) $game->id,
            'live_url' => $routeEvent ? route('events.games.live', [$routeEvent, $game->id]) : null,
            'snapshot_url' => $routeEvent ? route('events.games.live.snapshot', [$routeEvent, $game->id]) : null,
            'teams' => [
                'A' => [
                    'name' => $sides->get('A')?->display_name ?: 'Команда A',
                    'score' => (int) ($sides->get('A')?->score ?? 0),
                    'logo' => $sides->get('A')?->logoUrl(),
                ],
                'B' => [
                    'name' => $sides->get('B')?->display_name ?: 'Команда B',
                    'score' => (int) ($sides->get('B')?->score ?? 0),
                    'logo' => $sides->get('B')?->logoUrl(),
                ],
            ],
        ];
    }
}
