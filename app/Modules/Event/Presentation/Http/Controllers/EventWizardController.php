<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
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
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

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
        $oldVenueId = filter_var($request->old('venue_id'), FILTER_VALIDATE_INT);
        $selectedVenue = $oldVenueId === false
            ? null
            : Venue::query()
                ->with(['location.address', 'characteristics'])
                ->whereKey($oldVenueId)
                ->where('status', VenueStatusEnum::CONFIRMED->value)
                ->where('operational_status', VenueOperationalStatusEnum::ACTIVE->value)
                ->first();

        return ThemeResolver::page('events.wizard', [
            'types' => EventTypeEnum::cases(),
            'visibilities' => EventVisibilityEnum::cases(),
            'selectedType' => $selectedType,
            'selectedVenue' => $selectedVenue,
            'defaultStartsAt' => $defaultStartsAt->format('Y-m-d\TH:i'),
            'minimumStartsAt' => $now->subMinute()->startOfMinute()->format('Y-m-d\TH:i'),
            'defaultTitle' => $selectedType->label().' - '.$now->format('Ymd'),
            'durationOptions' => range(30, 480, 30),
            'telegramChats' => $telegramChats->activeEventChats(),
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
            ->competitionInvitable()
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
    ): JsonResponse {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'venue_id' => ['nullable', 'integer', 'min:1'],
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
        $limit = (int) ($validated['limit'] ?? 20);
        $hasAvailabilityWindow = $startsAt !== null && $durationMinutes !== null;

        // General streetball discovery is flexible: keep a venue if at least one
        // bookable zone is available. Exact revalidation (venue_id is present)
        // always checks only the scope the user actually selected.
        $scopes = $hasAvailabilityWindow && $venueId === null
            ? VenueBookingScopeEnum::cases()
            : [$requestedScope];

        $venuesById = collect();
        $availableScopes = [];
        foreach ($scopes as $scope) {
            $results = $searchVenues->handle(
                user: $request->user(),
                actor: $actor,
                query: $validated['query'] ?? null,
                venueId: $venueId,
                confirmedOnly: $request->boolean('confirmed_only'),
                operationalStatus: isset($validated['operational_status'])
                    ? VenueOperationalStatusEnum::from($validated['operational_status'])
                    : null,
                startsAt: $startsAt,
                durationMinutes: $durationMinutes,
                bookingScope: $scope,
                limit: $limit,
            );

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
        $hoopsByVenue = Venue::query()
            ->with('characteristics')
            ->whereKey($venues->pluck('id'))
            ->get()
            ->mapWithKeys(fn (Venue $venue): array => [
                $venue->id => (int) ($venue->characteristics?->hoops_count ?? 1),
            ]);

        return response()->json([
            'venues' => $venues->map(fn ($venue): array => [
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
                'url' => route('venues.show', $venue->routeIdentifier()),
                'preview_url' => route('venues.preview', $venue->routeIdentifier()),
                'hoops_count' => $hoopsByVenue->get($venue->id, 1),
                'available_scopes' => array_values(array_unique($availableScopes[$venue->id] ?? [])),
            ])->all(),
        ]);
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
