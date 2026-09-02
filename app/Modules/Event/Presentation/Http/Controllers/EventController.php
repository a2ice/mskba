<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\Services\PageSeoResolver;
use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\EventPlayerStatisticsSummaryBuilder;
use App\Modules\Event\Application\Services\EventResultGalleryManager;
use App\Modules\Event\Application\Services\GameStatisticsFields;
use App\Modules\Event\Application\UseCases\AddEventParticipantHandler;
use App\Modules\Event\Application\UseCases\CancelEventHandler;
use App\Modules\Event\Application\UseCases\CompleteEventHandler;
use App\Modules\Event\Application\UseCases\JoinEventHandler;
use App\Modules\Event\Application\UseCases\LeaveEventHandler;
use App\Modules\Event\Application\UseCases\ListEventsHandler;
use App\Modules\Event\Application\UseCases\ListEventVenuesHandler;
use App\Modules\Event\Application\UseCases\RemoveEventResponsibilityHandler;
use App\Modules\Event\Application\UseCases\RequestEventResponsibilityHandler;
use App\Modules\Event\Application\UseCases\RespondEventResponsibilityHandler;
use App\Modules\Event\Application\UseCases\SearchEventParticipantCandidatesHandler;
use App\Modules\Event\Application\UseCases\SetEventParticipationHandler;
use App\Modules\Event\Application\UseCases\ShowEventHandler;
use App\Modules\Event\Application\UseCases\SubmitEventWizardHandler;
use App\Modules\Event\Application\UseCases\UpdateEventHandler;
use App\Modules\Event\Application\UseCases\UpdateEventResponsibilityPermissionsHandler;
use App\Modules\Event\Application\UseCases\UpdateManagedEventParticipantStatusHandler;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Event\Domain\Models\LegacyGameRoute;
use App\Modules\Event\Presentation\Http\Requests\CancelEventRequest;
use App\Modules\Event\Presentation\Http\Requests\CreateEventRequest;
use App\Modules\Event\Presentation\Http\Requests\StoreEventResultPhotoRequest;
use App\Modules\Event\Presentation\Http\Requests\UpdateEventRequest;
use App\Modules\Event\Presentation\Http\Requests\UpdateEventResultPhotoRequest;
use App\Modules\Event\Presentation\Http\Requests\UpdateEventResultRequest;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Telegram\Application\Services\TelegramChatRegistry;
use App\Modules\Tournament\Application\Services\TournamentEntryRosterResolver;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use App\Support\Features\FeatureDisabledException;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class EventController extends Controller
{
    public function index(Request $request, ListEventsHandler $events, CurrentActorResolver $actors): Response
    {
        $eventTypeValues = array_map(
            static fn (EventTypeEnum $type): string => $type->value,
            EventTypeEnum::cases(),
        );
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in([...$eventTypeValues, 'games'])],
            'period' => ['nullable', Rule::in(['upcoming', 'past'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => [
                'nullable',
                'date_format:Y-m-d',
                Rule::when($request->filled('date_from'), ['after_or_equal:date_from']),
            ],
            'outcome' => ['nullable', Rule::in(['completed', 'cancelled', 'unmarked'])],
            'status' => ['nullable', Rule::in(['all', 'not_cancelled', 'cancelled'])],
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'has_mini_games' => ['nullable', 'boolean'],
        ]);
        $typeFilter = $validated['type'] ?? null;
        $type = is_string($typeFilter) ? EventTypeEnum::tryFrom($typeFilter) : null;
        $eventTypes = match ($typeFilter) {
            'games' => [EventTypeEnum::GAME, EventTypeEnum::GAME_TRAINING],
            null => [],
            default => [$type],
        };
        $period = $validated['period'] ?? 'upcoming';
        $dateFrom = $validated['date_from'] ?? null;
        $dateTo = $validated['date_to'] ?? null;
        $outcome = $period === 'past' ? ($validated['outcome'] ?? null) : null;
        $statusFilter = $validated['status'] ?? 'not_cancelled';
        if ($outcome === 'cancelled') {
            $statusFilter = 'cancelled';
            $outcome = null;
        }
        $venueId = isset($validated['venue_id']) ? (int) $validated['venue_id'] : null;
        $hasMiniGames = (bool) ($validated['has_mini_games'] ?? false);
        $search = trim((string) ($validated['q'] ?? ''));

        return ThemeResolver::page('events.index', [
            'events' => $events->handle(
                $actors->resolveForRequest($request),
                $eventTypes,
                $period,
                $dateFrom,
                $dateTo,
                $outcome,
                $statusFilter,
                $venueId,
                $hasMiniGames,
                $search,
            ),
            'types' => EventTypeEnum::cases(),
            'selectedType' => $type,
            'typeFilter' => $typeFilter,
            'period' => $period,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'outcome' => $outcome,
            'statusFilter' => $statusFilter,
            'venueId' => $venueId,
            'hasMiniGames' => $hasMiniGames,
            'search' => $search,
            'filterVenues' => Venue::query()
                ->whereHas('events')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function create(
        Request $request,
        ListEventVenuesHandler $venues,
        TelegramChatRegistry $telegramChats,
    ): Response {
        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(EventTypeEnum::class)],
        ]);
        $selectedType = isset($validated['type']) ? EventTypeEnum::from($validated['type']) : null;
        $defaultType = $selectedType ?? EventTypeEnum::GAME;
        $now = CarbonImmutable::now((string) config('app.timezone', 'Europe/Moscow'));
        $minimumStartsAt = $now->subMinute()->startOfMinute();
        $defaultStartsAt = $now->ceilMinute();

        return ThemeResolver::page('events.create', [
            'venues' => $venues->handle(),
            'types' => EventTypeEnum::cases(),
            'visibilities' => EventVisibilityEnum::cases(),
            'selectedType' => $selectedType,
            'defaultType' => $defaultType,
            'currentDate' => $now->format('Ymd'),
            'defaultTitle' => $defaultType->label().' - '.$now->format('Ymd'),
            'defaultStartsAt' => $defaultStartsAt->format('Y-m-d\TH:i'),
            'minimumStartsAt' => $minimumStartsAt->format('Y-m-d\TH:i'),
            'durationOptions' => range(30, 480, 30),
            'defaultDuration' => 60,
            'telegramChats' => $telegramChats->activeEventChats(),
            'teams' => Team::query()
                ->whereNull('temporary_for_event_id')
                ->where('status', TeamStatusEnum::ACTIVE->value)
                ->orderBy('name')
                ->get(),
            'eventRequestId' => (string) Str::uuid(),
        ]);
    }

    public function store(
        CreateEventRequest $request,
        SubmitEventWizardHandler $events,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);

        if ($actor === null) {
            return redirect()->route('login');
        }

        try {
            $data = $request->validated();
            $result = $events->handle($actor, $data);
        } catch (InvalidArgumentException|DomainException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (FeatureDisabledException) {
            return back()->withInput()->with('error', 'Онлайн-аренда этой площадки временно недоступна.');
        }

        if ($result->booking !== null) {
            return redirect()
                ->route('account.venue-bookings.show', $result->booking)
                ->with('status', 'Заявка на аренду отправлена. Мероприятие будет опубликовано после подтверждения брони.');
        }

        return redirect()
            ->route('events.show', $result->event->routeIdentifier())
            ->with('status', 'Мероприятие создано, площадка забронирована.');
    }

    public function show(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        EventPlayerStatisticsSummaryBuilder $playerStatisticsSummaryBuilder,
        GameStatisticsFields $statisticsFields,
        PageSeoResolver $pageSeo,
        TournamentEntryRosterResolver $entryRosters,
    ): Response|RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        $eventExists = Event::query()->whereRouteIdentifier($event)->exists();
        if (! $eventExists) {
            $legacyRoute = LegacyGameRoute::query()
                ->where(function ($query) use ($event): void {
                    $query->where('legacy_identifier', $event);
                    if (preg_match('/^(\d+)-/', $event, $matches) === 1) {
                        $query->orWhere('legacy_event_id', (int) $matches[1]);
                    }
                })
                ->with('game.event')
                ->firstOrFail();
            $parent = $events->handle($legacyRoute->game->event->routeIdentifier(), $actor);

            return redirect()->route('events.games.show', [$parent->routeIdentifier(), $legacyRoute->game_id], 301);
        }
        $item = $events->handle($event, $actor);
        $identityIds = $request->user()?->canonical()->identityIds() ?? [];
        $currentParticipant = $item->participants->first(
            fn (EventParticipant $participant): bool => in_array((int) $participant->user_id, $identityIds, true),
        );
        $tournamentMatch = $item->primaryGame?->tournamentMatch;
        $tournamentCandidates = $tournamentMatch === null
            ? collect()
            : collect([
                'A' => $entryRosters->resolveUsers($tournamentMatch->entryA),
                'B' => $entryRosters->resolveUsers($tournamentMatch->entryB),
            ]);

        return ThemeResolver::page($item->type === EventTypeEnum::GAME ? 'events.game-show' : 'events.show', [
            'event' => $item,
            'game' => $item->primaryGame,
            'eventPlayerStatistics' => $playerStatisticsSummaryBuilder->build($item),
            'currentParticipant' => $currentParticipant,
            'isParticipating' => $currentParticipant?->status === EventParticipantStatusEnum::CONFIRMED
                && $currentParticipant->confirmation_version === $item->participation_confirmation_version,
            'canManage' => $actor !== null && $access->canManage($item, $actor),
            'effectivePermissions' => collect($actor === null ? [] : $access->effectivePermissions($item, $actor))
                ->map(fn (EventResponsibilityPermissionEnum $permission): string => $permission->value),
            'responsibilityPermissionGroups' => [
                'event' => EventResponsibilityPermissionEnum::eventPermissions(),
                'mini_game' => EventResponsibilityPermissionEnum::miniGamePermissions(),
            ],
            'statisticsFields' => $statisticsFields->all(),
            'tournamentCandidates' => $tournamentCandidates,
            ...$pageSeo->resolve(
                SeoEntityTypeEnum::EVENT,
                $item->id,
                $item->title,
                $item->description,
                route('events.show', $item->routeIdentifier()),
            ),
        ]);
    }

    public function edit(
        Request $request,
        string $event,
        ShowEventHandler $events,
        ListEventVenuesHandler $venues,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
    ): Response|RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $item = $events->handle($event, $actor);
        abort_unless($access->allows($item, $actor, EventResponsibilityPermissionEnum::UPDATE_EVENT), 403);

        if (in_array($item->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)
            || $item->ends_at->lessThanOrEqualTo(now())) {
            return redirect()->route('events.show', $item->routeIdentifier())
                ->with('error', 'Завершённое или отменённое мероприятие нельзя редактировать.');
        }

        $freeVenues = $venues->handle(freeOnly: true);

        if ($item->venue->hasFreeAccess() && ! $freeVenues->contains('id', $item->venue_id)) {
            $freeVenues->push($item->venue);
            $freeVenues = $freeVenues->sortBy('name')->values();
        }

        return ThemeResolver::page('events.edit', [
            'event' => $item,
            'venues' => $freeVenues,
            'types' => EventTypeEnum::cases(),
            'visibilities' => EventVisibilityEnum::cases(),
            'canReschedule' => $item->venue->hasFreeAccess(),
            'durationOptions' => range(30, 480, 30),
            'currentDuration' => (int) $item->starts_at->diffInMinutes($item->ends_at),
        ]);
    }

    public function update(
        UpdateEventRequest $request,
        string $event,
        UpdateEventHandler $events,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $item = $events->handle($event, $actor, $request->validated());
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('events.show', $item->routeIdentifier())
            ->with('status', 'Мероприятие обновлено.');
    }

    public function participantCandidates(
        Request $request,
        string $event,
        SearchEventParticipantCandidatesHandler $candidates,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $validated = $request->validate(['query' => ['required', 'string', 'min:2', 'max:100']]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $users = $candidates->handle($event, $actor, $validated['query']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'users' => $users->map(function ($user): array {
                $name = trim(implode(' ', array_filter([
                    $user->profile?->first_name,
                    $user->profile?->last_name,
                ])));

                return [
                    'id' => $user->getKey(),
                    'name' => $name !== '' ? $name : ($user->username ?: "Пользователь #{$user->getKey()}"),
                    'username' => $user->username,
                ];
            })->values(),
        ]);
    }

    public function addParticipant(
        Request $request,
        string $event,
        AddEventParticipantHandler $participants,
        CurrentActorResolver $actors,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $managedEvent = $participants->handle($event, $actor, (int) $validated['user_id']);
        } catch (InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            $identityIds = User::query()->findOrFail((int) $validated['user_id'])->canonical()->identityIds();
            $participant = $managedEvent->participants()
                ->with(['user.profile', 'statusChangedByActor.user.profile'])
                ->whereIn('user_id', $identityIds)
                ->firstOrFail();

            return response()->json([
                'message' => 'Пользователь добавлен в список «Думают».',
                'participant' => $this->managedParticipantPayload($managedEvent, $participant),
                'confirmed_count' => $this->confirmedParticipantCount($managedEvent),
            ]);
        }

        return back()->with('status', 'Пользователь добавлен в список «Думают».');
    }

    public function updateManagedParticipantStatus(
        Request $request,
        string $event,
        int $participant,
        UpdateManagedEventParticipantStatusHandler $participants,
        CurrentActorResolver $actors,
    ): RedirectResponse|JsonResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $validated = $request->validate([
            'status' => ['required', Rule::enum(EventParticipantStatusEnum::class)],
        ]);
        $status = EventParticipantStatusEnum::from($validated['status']);

        try {
            $managedEvent = $participants->handle($event, $participant, $actor, $status);
        } catch (InvalidArgumentException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage()], 422)
                : back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            $confirmedParticipant = $managedEvent->participants()
                ->with(['user.profile', 'statusChangedByActor.user.profile'])
                ->findOrFail($participant);

            return response()->json([
                'message' => $this->managedParticipantStatusMessage($status),
                'participant' => $this->managedParticipantPayload($managedEvent, $confirmedParticipant),
                'confirmed_count' => $this->confirmedParticipantCount($managedEvent),
            ]);
        }

        return back()->with('status', $this->managedParticipantStatusMessage($status));
    }

    public function requestResponsibility(
        Request $request,
        string $event,
        int $participant,
        RequestEventResponsibilityHandler $responsibilities,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        $validated = $request->validate([
            'permissions_present' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::enum(EventResponsibilityPermissionEnum::class)],
        ]);
        $permissions = array_key_exists('permissions_present', $validated)
            ? ($validated['permissions'] ?? [])
            : array_map(fn (EventResponsibilityPermissionEnum $permission): string => $permission->value, EventResponsibilityPermissionEnum::cases());

        try {
            $responsibilities->handle($event, $participant, $actor, $permissions);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Запрос на назначение отправлен участнику.');
    }

    public function updateResponsibilityPermissions(
        Request $request,
        string $event,
        int $participant,
        UpdateEventResponsibilityPermissionsHandler $responsibilities,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $validated = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::enum(EventResponsibilityPermissionEnum::class)],
        ]);

        try {
            $responsibilities->handle($event, $participant, $actor, $validated['permissions'] ?? []);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Права ответственного обновлены.');
    }

    public function respondResponsibility(
        Request $request,
        string $event,
        int $participant,
        RespondEventResponsibilityHandler $responsibilities,
    ): RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 403);

        $validated = $request->validate([
            'decision' => ['required', Rule::in([
                EventResponsibilityStatusEnum::ACCEPTED->value,
                EventResponsibilityStatusEnum::DECLINED->value,
            ])],
        ]);

        try {
            $responsibilities->handle(
                $event,
                $participant,
                $user,
                EventResponsibilityStatusEnum::from($validated['decision']),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $message = $validated['decision'] === EventResponsibilityStatusEnum::ACCEPTED->value
            ? 'Вы подтвердили назначение ответственным.'
            : 'Вы отклонили назначение ответственным.';

        return back()->with('status', $message);
    }

    public function removeResponsibility(
        Request $request,
        string $event,
        int $participant,
        RemoveEventResponsibilityHandler $responsibilities,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $responsibilities->handle($event, $participant, $actor);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Назначение ответственного снято.');
    }

    public function cancel(
        CancelEventRequest $request,
        string $event,
        CancelEventHandler $events,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $events->handle($event, $actor, $request->validated('reason'));
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Мероприятие отменено, время площадки освобождено.');
    }

    public function complete(
        UpdateEventResultRequest $request,
        string $event,
        CompleteEventHandler $events,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $events->handle($event, $actor, $request->validated('result_description'));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Итоги мероприятия сохранены.');
    }

    public function storeResultPhoto(
        StoreEventResultPhotoRequest $request,
        string $event,
        ShowEventHandler $events,
        EventResultGalleryManager $gallery,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $item = $events->handle($event, $actor);
        $path = $request->file('photo')?->getRealPath();
        $contents = is_string($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            return back()->with('photo_error', 'Не удалось прочитать изображение.');
        }

        try {
            $gallery->store($item, $actor, $contents);
        } catch (InvalidArgumentException|\RuntimeException $exception) {
            return back()->with('photo_error', $exception->getMessage());
        }

        return back()->with('photo_status', 'Фотография добавлена.');
    }

    public function destroyResultPhoto(
        Request $request,
        string $event,
        int $photo,
        ShowEventHandler $events,
        EventResultGalleryManager $gallery,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $item = $events->handle($event, $actor);
        try {
            $gallery->delete($item, $actor, $photo);
        } catch (InvalidArgumentException $exception) {
            return back()->with('photo_error', $exception->getMessage());
        }

        return back()->with('photo_status', 'Фотография удалена.');
    }

    public function updateResultPhoto(
        UpdateEventResultPhotoRequest $request,
        string $event,
        int $photo,
        ShowEventHandler $events,
        EventResultGalleryManager $gallery,
        CurrentActorResolver $actors,
    ): JsonResponse|RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $item = $events->handle($event, $actor);

        try {
            $updatedPhoto = $gallery->updateMetadata(
                $item,
                $actor,
                $photo,
                $request->validated('description'),
                $request->validated('tags'),
            );
        } catch (InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->with('photo_error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Описание и отметки сохранены.',
                'description' => $updatedPhoto->description,
                'tags' => $updatedPhoto->eventResultPhotoTags->map(fn ($tag): array => [
                    'user_id' => $tag->user_id,
                    'name' => trim(implode(' ', array_filter([
                        $tag->user->profile?->first_name,
                        $tag->user->profile?->last_name,
                    ]))) ?: 'Участник #'.$tag->user_id,
                    'x' => $tag->position_x,
                    'y' => $tag->position_y,
                ])->values(),
            ]);
        }

        return back()->with('photo_status', 'Описание и отметки сохранены.');
    }

    public function join(Request $request, string $event, JoinEventHandler $events): RedirectResponse
    {
        try {
            $events->handle($event, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Вы присоединились к мероприятию.');
    }

    public function leave(Request $request, string $event, LeaveEventHandler $events): RedirectResponse
    {
        try {
            $events->handle($event, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Вы вышли из состава участников.');
    }

    public function participation(
        Request $request,
        string $event,
        SetEventParticipationHandler $events,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    EventParticipantStatusEnum::CONFIRMED->value,
                    EventParticipantStatusEnum::TENTATIVE->value,
                    EventParticipantStatusEnum::LEFT->value,
                ]),
            ],
        ]);
        $status = EventParticipantStatusEnum::from($validated['status']);

        try {
            $events->handle($event, $request->user(), $status);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', match ($status) {
            EventParticipantStatusEnum::CONFIRMED => 'Вы присоединились к мероприятию.',
            EventParticipantStatusEnum::TENTATIVE => 'Ответ «Думаю» сохранён.',
            EventParticipantStatusEnum::LEFT => 'Ответ «Не пойду» сохранён.',
        });
    }

    private function managedParticipantPayload(Event $event, EventParticipant $participant): array
    {
        $participantUser = $participant->user->canonical();
        $profile = $participantUser->profile;
        $name = trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])))
            ?: $participantUser->username
            ?: 'Пользователь #'.$participantUser->id;
        $changer = $participant->statusChangedByActor?->user?->canonical();
        $changerProfile = $changer?->profile;
        $changerName = $changer === null ? null : (
            trim(implode(' ', array_filter([$changerProfile?->first_name, $changerProfile?->last_name])))
            ?: $changer->username
            ?: 'Пользователь #'.$changer->id
        );
        $isOrganizer = $changer !== null
            && $event->organizerActor()->whereIn('user_id', $changer->identityIds())->exists();

        return [
            'id' => $participant->id,
            'user_id' => $participantUser->id,
            'name' => $name,
            'username' => $participantUser->username,
            'avatar_url' => $profile?->avatarUrl(),
            'initials' => mb_strtoupper(mb_substr($name, 0, 2)),
            'status' => $participant->status->value,
            'status_url' => route('events.participants.manage.status', [$event->routeIdentifier(), $participant->id]),
            'changed_label' => $participant->status_changed_at?->format('H:i') === null
                ? null
                : 'изменено '.$participant->status_changed_at->setTimezone(config('app.timezone'))->format('H:i'),
            'changed_title' => $changerName === null
                ? null
                : 'Изменил: '.$changerName.($isOrganizer ? ' · организатор' : ' · ответственный'),
        ];
    }

    private function confirmedParticipantCount(Event $event): int
    {
        return $event->participants()
            ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
            ->where('confirmation_version', $event->participation_confirmation_version)
            ->count();
    }

    private function managedParticipantStatusMessage(EventParticipantStatusEnum $status): string
    {
        return match ($status) {
            EventParticipantStatusEnum::CONFIRMED => 'Пользователь отмечен как участник.',
            EventParticipantStatusEnum::TENTATIVE => 'Пользователь перемещён в список «Думают».',
            EventParticipantStatusEnum::LEFT => 'Пользователь отмечен как не участвующий.',
        };
    }
}
