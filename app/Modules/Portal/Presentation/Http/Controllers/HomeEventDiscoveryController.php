<?php

namespace App\Modules\Portal\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Portal\Application\UseCases\DiscoverHomeEventsHandler;
use App\Modules\Tournament\Domain\Enums\TournamentEnrollmentPolicyEnum;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class HomeEventDiscoveryController extends Controller
{
    public function __invoke(Request $request, DiscoverHomeEventsHandler $discovery): JsonResponse
    {
        $eventTypes = array_map(
            static fn (EventTypeEnum $type): string => $type->value,
            EventTypeEnum::cases(),
        );
        $formats = array_map(
            static fn (GameFormatEnum $format): string => $format->value,
            array_filter(
                GameFormatEnum::cases(),
                static fn (GameFormatEnum $format): bool => $format !== GameFormatEnum::CUSTOM,
            ),
        );
        $gameModes = array_map(
            static fn (GameRecruitmentModeEnum $mode): string => $mode->value,
            GameRecruitmentModeEnum::cases(),
        );
        $enrollmentPolicies = array_map(
            static fn (TournamentEnrollmentPolicyEnum $policy): string => $policy->value,
            TournamentEnrollmentPolicyEnum::cases(),
        );

        $validated = $request->validate([
            'type' => ['nullable', Rule::in(['any', ...$eventTypes, 'tournament'])],
            'format' => ['nullable', Rule::in(['any', ...$formats])],
            'game_mode' => ['nullable', Rule::in(['any', ...$gameModes])],
            'enrollment' => ['nullable', Rule::in(['any', ...$enrollmentPolicies])],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'metro_station_ids' => ['nullable', 'array', 'max:10'],
            'metro_station_ids.*' => ['integer', 'distinct', 'exists:metro_stations,id'],
            'street' => ['nullable', 'string', 'max:180'],
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => [
                'nullable',
                'date_format:Y-m-d',
                Rule::when($request->filled('date_from'), ['after_or_equal:date_from']),
            ],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $timezone = (string) config('app.timezone', 'Europe/Moscow');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $type = (string) ($validated['type'] ?? 'any');

        $filters = [
            ...$validated,
            'type' => $type,
            'format' => $validated['format'] ?? 'any',
            'game_mode' => $validated['game_mode'] ?? 'any',
            'enrollment' => $validated['enrollment'] ?? 'any',
            'date_from' => $validated['date_from'] ?? $today->toDateString(),
            'date_to' => $validated['date_to'] ?? $today->addDays(7)->toDateString(),
            'limit' => (int) ($validated['limit'] ?? 30),
        ];

        if ($type !== EventTypeEnum::GAME->value && $type !== 'tournament') {
            $filters['format'] = 'any';
        }
        if ($type !== EventTypeEnum::GAME->value) {
            $filters['game_mode'] = 'any';
        }
        if ($type !== 'tournament') {
            $filters['enrollment'] = 'any';
        }

        $results = $discovery->handle($filters);

        return response()->json([
            'results' => $results,
            'count' => count($results),
            'timezone' => $timezone,
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
        ]);
    }
}
