<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Telegram\Application\Services\TelegramChatRegistry;
use App\Modules\Venue\Application\UseCases\SearchVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EventWizardController extends Controller
{
    public function show(Request $request, TelegramChatRegistry $telegramChats): Response
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(EventTypeEnum::class)],
        ]);
        $selectedType = isset($validated['type']) ? EventTypeEnum::from($validated['type']) : EventTypeEnum::GAME;
        $now = CarbonImmutable::now((string) config('app.timezone', 'Europe/Moscow'));
        $defaultStartsAt = $now->ceilMinute();

        return ThemeResolver::page('events.wizard', [
            'types' => EventTypeEnum::cases(),
            'visibilities' => EventVisibilityEnum::cases(),
            'selectedType' => $selectedType,
            'defaultStartsAt' => $defaultStartsAt->format('Y-m-d\TH:i'),
            'minimumStartsAt' => $now->subMinute()->startOfMinute()->format('Y-m-d\TH:i'),
            'defaultTitle' => $selectedType->label().' - '.$now->format('Ymd'),
            'durationOptions' => range(30, 480, 30),
            'telegramChats' => $telegramChats->activeEventChats(),
            'eventRequestId' => (string) Str::uuid(),
        ]);
    }

    public function teams(
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $teamAccess,
    ): JsonResponse {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:40'],
            'ids' => ['nullable', 'array', 'max:2'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 401);

        $query = trim((string) ($validated['q'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 32);
        $requestedIds = collect($validated['ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values();
        $managedIds = $this->manageableTeamIds($actor);

        $publicTeams = Team::query()
            ->with('logo')
            ->competitionEligible()
            ->where('accepts_competition_invitations', true)
            ->when($query !== '', fn ($builder) => $builder->whereRaw(
                'LOWER(name) LIKE ?',
                ['%'.mb_strtolower($query).'%'],
            ))
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $managedTeams = $managedIds->isEmpty()
            ? collect()
            : Team::query()
                ->with('logo')
                ->whereIn('id', $managedIds)
                ->whereNull('temporary_for_event_id')
                ->where('status', TeamStatusEnum::ACTIVE->value)
                ->when($query !== '', fn ($builder) => $builder->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.mb_strtolower($query).'%'],
                ))
                ->orderBy('name')
                ->get();

        $selectedTeams = $requestedIds->isEmpty()
            ? collect()
            : Team::query()
                ->with('logo')
                ->whereIn('id', $requestedIds)
                ->whereNull('temporary_for_event_id')
                ->where('status', TeamStatusEnum::ACTIVE->value)
                ->where(function ($builder) use ($managedIds): void {
                    $builder->where('accepts_competition_invitations', true);
                    if ($managedIds->isNotEmpty()) {
                        $builder->orWhereIn('id', $managedIds);
                    }
                })
                ->get();

        $teams = $selectedTeams
            ->concat($managedTeams)
            ->concat($publicTeams)
            ->unique('id')
            ->map(function (Team $team) use ($actor, $teamAccess): array {
                $manageable = $teamAccess->allows(
                    $team,
                    $actor,
                    TeamPermissionEnum::MANAGE_GAME_PARTICIPATION,
                );

                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'logo_url' => $team->logo?->publicUrl() ?? asset('images/team-placeholder.webp'),
                    'manageable' => $manageable,
                    'accepts_invitations' => $team->acceptsCompetitionInvitations(),
                    'selection_hint' => $manageable
                        ? 'Ваша команда — согласие не требуется'
                        : 'После создания будет отправлено приглашение',
                ];
            })
            ->sortBy([
                ['manageable', 'desc'],
                ['name', 'asc'],
            ])
            ->take($limit)
            ->values();

        return response()->json(['teams' => $teams]);
    }

    public function venues(
        Request $request,
        SearchVenuesHandler $searchVenues,
        CurrentActorResolver $actors,
        MinorAmountParser $amounts,
        VenueEventAvailability $availability,
    ): JsonResponse {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'venue_id' => ['nullable', 'integer', 'min:1'],
            'venue_court_id' => ['nullable', 'integer', 'min:1'],
            'discover_scopes' => ['nullable', 'boolean'],
            'confirmed_only' => ['nullable', 'boolean'],
            'operational_status' => ['nullable', Rule::enum(VenueOperationalStatusEnum::class)],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:30', 'max:480', 'required_with:starts_at'],
            'booking_scope' => ['nullable', Rule::enum(VenueBookingScopeEnum::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 401);

        $startsAt = isset($validated['starts_at'])
            ? CarbonImmutable::createFromFormat(
                'Y-m-d\TH:i',
                $validated['starts_at'],
                (string) config('app.timezone', 'Europe/Moscow'),
            )
            : null;
        $durationMinutes = isset($validated['duration_minutes']) ? (int) $validated['duration_minutes'] : null;
        $requestedScope = VenueBookingScopeEnum::from(
            $validated['booking_scope'] ?? VenueBookingScopeEnum::WHOLE->value,
        );
        $venueId = isset($validated['venue_id']) ? (int) $validated['venue_id'] : null;
        $court = $this->requestedCourt($venueId, isset($validated['venue_court_id']) ? (int) $validated['venue_court_id'] : null);
        $discoverScopes = $request->boolean('discover_scopes');
        $limit = (int) ($validated['limit'] ?? 20);
        $hasAvailabilityWindow = $startsAt !== null && $durationMinutes !== null;
        $courtVenue = $court === null
            ? null
            : Venue::query()->with(['schedule.intervals', 'schedule.exceptions.intervals'])->find($court->venue_id);

        // Discovery keeps a multi-zone venue when at least one physical scope is
        // free. Exact revalidation still checks only the user's selected scope.
        $scopes = $hasAvailabilityWindow && ($venueId === null || $discoverScopes)
            ? VenueBookingScopeEnum::cases()
            : [$requestedScope];

        $venuesById = collect();
        $availableScopes = [];
        foreach ($scopes as $scope) {
            // A concrete hall needs court-aware availability. Generic discovery keeps
            // the existing venue-level search behavior and is resolved by SearchVenuesHandler.
            $results = $searchVenues->handle(
                user: $request->user(),
                actor: $actor,
                query: $validated['query'] ?? null,
                venueId: $venueId,
                confirmedOnly: $request->boolean('confirmed_only'),
                operationalStatus: isset($validated['operational_status'])
                    ? VenueOperationalStatusEnum::from($validated['operational_status'])
                    : null,
                startsAt: $court === null ? $startsAt : null,
                durationMinutes: $court === null ? $durationMinutes : null,
                bookingScope: $scope,
                limit: $limit,
            );

            if ($court !== null && $hasAvailabilityWindow) {
                $results = collect($results)
                    ->filter(function ($venue) use ($availability, $court, $courtVenue, $startsAt, $durationMinutes, $scope): bool {
                        if ($courtVenue === null || (int) $venue->id !== (int) $court->venue_id) {
                            return false;
                        }

                        try {
                            $availability->assertAvailable(
                                $courtVenue,
                                $startsAt,
                                $startsAt->addMinutes($durationMinutes),
                                scope: $scope,
                                court: $court,
                            );

                            return true;
                        } catch (\InvalidArgumentException) {
                            return false;
                        }
                    })
                    ->all();
            }

            foreach ($results as $venue) {
                $venuesById->put($venue->id, $venue);
                if ($hasAvailabilityWindow) {
                    $availableScopes[$venue->id] ??= [];
                    $availableScopes[$venue->id][] = $scope->value;
                }
            }
        }

        $venues = $venuesById
            ->sortBy(fn ($venue) => mb_strtolower($venue->name), SORT_NATURAL)
            ->take($limit)
            ->values();
        $hoopsByVenue = $court !== null
            ? collect([$court->venue_id => (int) ($court->hoops_count ?? ($court->supports_halves ? 2 : 1))])
            : Venue::query()
                ->with('characteristics')
                ->whereKey($venues->pluck('id'))
                ->get()
                ->mapWithKeys(fn (Venue $venue): array => [
                    $venue->id => (int) ($venue->characteristics?->hoops_count ?? 1),
                ]);
        $rentalPolicies = VenueBookingPolicy::query()
            ->whereIn('venue_id', $venues->pluck('id'))
            ->where('active_marker', true)
            ->where('is_enabled', true)
            ->get()
            ->keyBy('venue_id');
        $primaryCourts = $court !== null
            ? collect([$court->venue_id => $court])
            : VenueCourt::query()
                ->whereIn('venue_id', $venues->pluck('id'))
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy('venue_id')
                ->map(fn (Collection $courts): ?VenueCourt => $courts->first());

        return response()->json([
            'venues' => $venues->map(function ($venue) use ($amounts, $availableScopes, $court, $durationMinutes, $hoopsByVenue, $rentalPolicies, $primaryCourts): array {
                $policy = $rentalPolicies->get($venue->id);
                $scopes = array_values(array_unique($availableScopes[$venue->id] ?? []));
                $effectiveCourt = $primaryCourts->get($venue->id);
                if ($effectiveCourt !== null) {
                    $courtScopes = [];
                    if ($effectiveCourt->allows_whole) {
                        $courtScopes[] = VenueBookingScopeEnum::WHOLE->value;
                    }
                    if ($effectiveCourt->supports_halves && $effectiveCourt->allows_halves) {
                        $courtScopes[] = VenueBookingScopeEnum::HALF_A->value;
                        $courtScopes[] = VenueBookingScopeEnum::HALF_B->value;
                    }
                    $scopes = array_values(array_intersect($scopes, $courtScopes));
                }
                $steps = $policy !== null && $durationMinutes !== null && $policy->acceptsDuration($durationMinutes)
                    ? intdiv($durationMinutes, $policy->time_step_minutes)
                    : null;

                return [
                    'id' => $venue->id,
                    'name' => $venue->name,
                    'type' => $venue->type,
                    'status' => $venue->status,
                    'is_confirmed' => $venue->status === VenueStatusEnum::CONFIRMED->label(),
                    'description' => $venue->shortDescription,
                    'address' => $venue->displayAddress,
                    'raw_address' => $venue->rawAddress,
                    'requires_payment' => $venue->requiresPayment,
                    'requires_booking_approval' => $venue->requiresBookingApproval,
                    'has_free_access' => $venue->hasFreeAccess(),
                    'operational_status' => $venue->operationalStatus,
                    'metro_stations' => $venue->metroStations,
                    'tags' => $venue->tags,
                    'latitude' => $venue->latitude,
                    'longitude' => $venue->longitude,
                    'url' => $court !== null && ! $court->is_primary
                        ? route('venues.courts.show', [$venue->routeIdentifier(), $court->routeIdentifier()])
                        : route('venues.show', $venue->routeIdentifier()),
                    'preview_url' => route('venues.preview', $venue->routeIdentifier()),
                    'hoops_count' => $hoopsByVenue->get($venue->id, 1),
                    'venue_court_id' => $court !== null && (int) $court->venue_id === (int) $venue->id ? (int) $court->id : null,
                    'venue_court_name' => $court !== null && (int) $court->venue_id === (int) $venue->id ? $court->name : null,
                    'available_scopes' => $scopes,
                    'rental_policy' => $policy === null ? null : [
                        'currency' => $policy->currency,
                        'currency_exponent' => $amounts->exponent($policy->currency),
                        'requires_payment' => $policy->requires_payment,
                        'time_step_minutes' => $policy->time_step_minutes,
                        'whole_amount_minor' => $steps === null ? null : $steps * $policy->whole_price_per_step_minor,
                        'half_amount_minor' => $steps === null || $policy->half_price_per_step_minor === null
                            ? null
                            : $steps * $policy->half_price_per_step_minor,
                    ],
                ];
            })->all(),
        ]);
    }

    private function requestedCourt(?int $venueId, ?int $courtId): ?VenueCourt
    {
        if ($courtId === null) {
            return null;
        }

        $court = VenueCourt::query()->find($courtId);
        if ($venueId === null || $court === null || (int) $court->venue_id !== $venueId) {
            throw ValidationException::withMessages([
                'venue_court_id' => 'Выбранный зал не относится к выбранной площадке.',
            ]);
        }

        return $court;
    }

    /** @return Collection<int, int> */
    private function manageableTeamIds(Actor $actor): Collection
    {
        $user = $actor->user?->canonical();
        if ($user === null || $user->isBlocked() || $user->trashed()) {
            return collect();
        }

        $identityIds = $user->identityIds();
        $createdIds = Team::query()
            ->whereHas('createdByActor', fn ($builder) => $builder->whereIn('user_id', $identityIds))
            ->pluck('id');

        $delegatedIds = Team::query()
            ->whereHas('memberships', fn ($builder) => $builder
                ->whereIn('user_id', $identityIds)
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereHas('contract', fn ($contract) => $contract
                    ->where('status', ContractStatusEnum::ACTIVE->value)
                    ->whereHas('permissions', fn ($permissions) => $permissions
                        ->where('permission', TeamPermissionEnum::MANAGE_GAME_PARTICIPATION->value))))
            ->pluck('id');

        return $createdIds->concat($delegatedIds)->map(fn ($id): int => (int) $id)->unique()->values();
    }
}
