<?php

namespace App\Modules\Coordination\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coordination\Application\Services\CoordinationAccess;
use App\Modules\Coordination\Application\UseCases\ApplyEventCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\CancelCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\ClosePollHandler;
use App\Modules\Coordination\Application\UseCases\CreateCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\CreateEventFromCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\DecideCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\SuggestPollOptionHandler;
use App\Modules\Coordination\Application\UseCases\VoteInPollHandler;
use App\Modules\Coordination\Domain\Enums\CoordinationFlowTypeEnum;
use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Coordination\Domain\Models\Poll;
use App\Modules\Coordination\Presentation\Http\Requests\CreateCoordinationRequest;
use App\Modules\Coordination\Presentation\Http\Requests\DecideCoordinationRequest;
use App\Modules\Coordination\Presentation\Http\Requests\SuggestPollOptionRequest;
use App\Modules\Coordination\Presentation\Http\Requests\VoteInPollRequest;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Application\UseCases\ListEventVenuesHandler;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Presentation\Http\Requests\CreateEventRequest;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Telegram\Application\Services\TelegramChatRegistry;
use App\Modules\Telegram\Application\UseCases\PrepareTelegramCoordinationPublicationsHandler;
use App\Modules\Telegram\Application\UseCases\PrepareTelegramEventPublicationsHandler;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CoordinationController extends Controller
{
    public function index(): Response
    {
        return ThemeResolver::page('coordination.index', [
            'sessions' => CoordinationSession::query()
                ->with(['polls' => fn ($query) => $query->withCount('ballots')])
                ->with('organizerActor.user')
                ->latest()
                ->paginate(15),
        ]);
    }

    public function create(
        Request $request,
        TelegramChatRegistry $telegramChats,
        ListEventVenuesHandler $eventVenues,
        CurrentActorResolver $actors,
        EventManagementAccess $eventAccess,
    ): Response {
        $contextEvent = null;
        if ($request->filled('event')) {
            $actor = $actors->resolveForRequest($request);
            abort_if($actor === null, 403);
            $contextEvent = Event::query()
                ->with(['venue', 'booking'])
                ->whereRouteIdentifier((string) $request->query('event'))
                ->firstOrFail();
            abort_unless($eventAccess->canManage($contextEvent, $actor), 403);
        }
        $contextTimezone = $contextEvent?->venue?->schedule()->value('timezone')
            ?: config('app.timezone', 'Europe/Moscow');
        $contextStart = $contextEvent?->starts_at?->setTimezone($contextTimezone);
        $contextEnd = $contextEvent?->ends_at?->setTimezone($contextTimezone);

        return ThemeResolver::page('coordination.create', [
            'selectionModes' => PollSelectionModeEnum::cases(),
            'flowTypes' => [
                CoordinationFlowTypeEnum::EVENT_ATTENDANCE,
                CoordinationFlowTypeEnum::SINGLE,
                CoordinationFlowTypeEnum::EVENT_TIME_SELECTION,
                CoordinationFlowTypeEnum::EVENT_VENUE_SELECTION,
                CoordinationFlowTypeEnum::EVENT_SCHEDULING,
            ],
            'subjectTypes' => collect(PollSubjectTypeEnum::cases())
                ->reject(fn (PollSubjectTypeEnum $type): bool => $type === PollSubjectTypeEnum::PARTICIPATION)
                ->values(),
            'resultsVisibilities' => PollResultsVisibilityEnum::cases(),
            'defaultClosesAt' => now()->addHour()->format('Y-m-d\TH:i'),
            'defaultDates' => $contextStart
                ? [$contextStart->format('Y-m-d'), $contextStart->addDay()->format('Y-m-d')]
                : [now()->addDay()->format('Y-m-d'), now()->addDays(2)->format('Y-m-d')],
            'defaultTimes' => $contextStart && $contextEnd
                ? [
                    ['starts_at' => $contextStart->format('H:i'), 'ends_at' => $contextEnd->format('H:i')],
                    ['starts_at' => $contextStart->addHour()->format('H:i'), 'ends_at' => $contextEnd->addHour()->format('H:i')],
                ]
                : [['starts_at' => '18:00', 'ends_at' => '19:00'], ['starts_at' => '19:00', 'ends_at' => '20:00']],
            'contextEvent' => $contextEvent,
            'telegramChats' => $telegramChats->activeCoordinationChats(),
            'optionVenues' => $eventVenues->handle(),
        ]);
    }

    public function store(
        CreateCoordinationRequest $request,
        CreateCoordinationHandler $handler,
        CurrentActorResolver $actors,
        TelegramChatRegistry $telegramChats,
        PrepareTelegramCoordinationPublicationsHandler $prepareTelegramPublications,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $data = $request->validated();
            $telegramChats->activeCoordinationChats();
            $session = DB::transaction(function () use (
                $actor,
                $data,
                $handler,
                $prepareTelegramPublications,
            ): CoordinationSession {
                $session = $handler->handle($actor, $data);

                if ((bool) ($data['publish_to_telegram'] ?? false)) {
                    $prepareTelegramPublications->handle(
                        $session->polls->firstOrFail(),
                        $data['telegram_chat_ids'] ?? [],
                    );
                }

                return $session;
            });
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('coordination.show', $session)
            ->with('status', 'Опрос создан.');
    }

    public function show(
        Request $request,
        CoordinationSession $coordination,
        CurrentActorResolver $actors,
        CoordinationAccess $access,
        ListEventVenuesHandler $eventVenues,
        VenueEventAvailability $availability,
        AddressDisplayFormatter $addressFormatter,
        TelegramChatRegistry $telegramChats,
    ): Response {
        $coordination->load([
            'organizerActor.user.profile.activeAvatar',
            'decisions.poll',
            'decisions.option',
            'eventTransition.event',
            'polls.decision',
        ]);
        $poll = $this->currentPoll($coordination);
        $ballot = $request->user() === null
            ? null
            : $poll->ballots()->with('selections')->where('user_id', $request->user()->id)->first();
        $actor = $actors->resolveForRequest($request);
        $hasVoted = $ballot !== null;
        $pollIsOpen = $poll->status === PollStatusEnum::OPEN && $poll->closes_at->isFuture();
        $canSeeResults = match ($poll->results_visibility) {
            PollResultsVisibilityEnum::ALWAYS => true,
            PollResultsVisibilityEnum::AFTER_VOTE => $hasVoted || ! $pollIsOpen,
            PollResultsVisibilityEnum::AFTER_CLOSE => ! $pollIsOpen,
        };
        $poll->load([
            'options' => fn ($query) => $query
                ->withCount('selections')
                ->with('proposer.profile')
                ->when(
                    $canSeeResults && ! $poll->is_anonymous,
                    fn ($optionQuery) => $optionQuery->with([
                        'selections.ballot.user.profile',
                    ]),
                ),
        ]);
        $canManage = $actor !== null && $access->canManage($coordination, $actor);
        $canCreateEvent = $canManage
            && $coordination->context_type === null
            && $coordination->status->value === 'completed'
            && $coordination->decision !== null
            && $coordination->eventTransition === null;
        $contextEvent = $coordination->context_type?->value === 'event' && $coordination->context_id
            ? Event::query()->with('venue.location.address')->find($coordination->context_id)
            : null;
        $canApplyEventChange = $canManage
            && $contextEvent !== null
            && $coordination->status->value === 'completed';
        $now = CarbonImmutable::now((string) config('app.timezone', 'Europe/Moscow'));
        $minimumStartsAt = $now->subMinute()->startOfMinute();
        $defaultStartsAt = $now->ceilMinute();
        $decisionDescription = $coordination->decisions->isEmpty()
            ? null
            : 'Согласовано: '.$coordination->decisions
                ->map(fn ($decision): string => $decision->poll->subject_type->label().': '.$decision->option->label)
                ->implode('; ');
        $decisionByType = $coordination->decisions->keyBy(
            fn ($decision): string => $decision->poll->subject_type->value,
        );
        $date = $decisionByType->get(PollSubjectTypeEnum::DATE->value)?->option?->value['date'] ?? null;
        $interval = $decisionByType->get(PollSubjectTypeEnum::TIME_INTERVAL->value)?->option?->value ?? null;
        $venueId = $decisionByType->get(PollSubjectTypeEnum::VENUE->value)?->option?->value['venue_id'] ?? null;
        $coordinatedStartsAt = is_string($date) && is_array($interval)
            ? $date.'T'.($interval['starts_at'] ?? '')
            : null;
        $coordinatedDuration = is_array($interval)
            ? CarbonImmutable::parse($interval['starts_at'])->diffInMinutes(CarbonImmutable::parse($interval['ends_at']))
            : null;
        $configuration = $poll->configuration ?? [];

        if (in_array($coordination->flow_type, [
            CoordinationFlowTypeEnum::EVENT_ATTENDANCE,
            CoordinationFlowTypeEnum::EVENT_TIME_SELECTION,
            CoordinationFlowTypeEnum::EVENT_VENUE_SELECTION,
        ], true)) {
            $coordinatedDuration = (int) ($configuration['duration_minutes'] ?? 60);
            $venueId = $configuration['venue_id'] ?? $venueId;

            if (isset($configuration['starts_at'])) {
                $coordinatedStartsAt = CarbonImmutable::parse((string) $configuration['starts_at'])
                    ->setTimezone((string) config('app.timezone', 'Europe/Moscow'))
                    ->format('Y-m-d\TH:i');
            }

            if ($coordination->flow_type === CoordinationFlowTypeEnum::EVENT_TIME_SELECTION) {
                $selectedTime = $decisionByType->get(PollSubjectTypeEnum::TIME->value)?->option?->value['time'] ?? null;
                $coordinatedStartsAt = is_string($selectedTime) && isset($configuration['date'])
                    ? $configuration['date'].'T'.$selectedTime
                    : null;
            }

            if ($coordination->flow_type === CoordinationFlowTypeEnum::EVENT_VENUE_SELECTION) {
                $venueId = $decisionByType->get(PollSubjectTypeEnum::VENUE->value)?->option?->value['venue_id'] ?? null;
            }

            if (($configuration['automatic_duration'] ?? false)
                && is_numeric($venueId)
                && is_string($coordinatedStartsAt)
                && $coordinatedStartsAt !== '') {
                $venue = Venue::query()
                    ->with(['schedule.intervals', 'schedule.exceptions.intervals'])
                    ->find((int) $venueId);

                if ($venue !== null) {
                    $timezone = $venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
                    $startsAt = CarbonImmutable::parse($coordinatedStartsAt, $timezone)->utc();

                    if ($startsAt->isFuture()) {
                        try {
                            $coordinatedDuration = (int) $startsAt->diffInMinutes(
                                $availability->resolveEndsAt($venue, $startsAt),
                            );
                        } catch (InvalidArgumentException) {
                            // Availability is rechecked on event creation; keep the form usable
                            // so the organizer can choose another venue or start time.
                            $coordinatedDuration = 60;
                        }
                    }
                }
            }
        }
        $organizerUser = $coordination->organizerActor?->user;
        $organizerName = $organizerUser === null
            ? 'Пользователь не найден'
            : (trim(implode(' ', array_filter([
                $organizerUser->profile?->first_name,
                $organizerUser->profile?->last_name,
            ]))) ?: ($organizerUser->username ?: 'Пользователь #'.$organizerUser->id));
        $eventVotingVenue = null;
        $eventVotingStartsAt = null;
        $eventVotingEndsAt = null;
        $eventVotingDate = null;

        if ($contextEvent !== null) {
            $eventVotingVenue = $contextEvent->venue;
            $eventTimezone = $eventVotingVenue?->schedule()->value('timezone')
                ?: config('app.timezone', 'Europe/Moscow');
            $eventVotingStartsAt = $contextEvent->starts_at?->setTimezone($eventTimezone);
            $eventVotingEndsAt = $contextEvent->ends_at?->setTimezone($eventTimezone);
        } elseif (in_array($coordination->flow_type, [
            CoordinationFlowTypeEnum::EVENT_ATTENDANCE,
            CoordinationFlowTypeEnum::EVENT_TIME_SELECTION,
            CoordinationFlowTypeEnum::EVENT_VENUE_SELECTION,
        ], true)) {
            if (is_numeric($venueId)) {
                $eventVotingVenue = Venue::query()
                    ->with('location.address')
                    ->find((int) $venueId);
            }

            $eventTimezone = $eventVotingVenue?->schedule()->value('timezone')
                ?: config('app.timezone', 'Europe/Moscow');

            if (is_string($coordinatedStartsAt) && $coordinatedStartsAt !== '') {
                $eventVotingStartsAt = CarbonImmutable::parse($coordinatedStartsAt, $eventTimezone);

                if (is_numeric($coordinatedDuration) && (int) $coordinatedDuration > 0) {
                    $eventVotingEndsAt = $eventVotingStartsAt->addMinutes((int) $coordinatedDuration);
                }
            } elseif (isset($configuration['date'])) {
                $eventVotingDate = CarbonImmutable::parse((string) $configuration['date'], $eventTimezone);
            }
        }
        $eventVotingAddressModel = $eventVotingVenue?->location?->address;
        $eventVotingAddress = $addressFormatter->format(
            $eventVotingAddressModel?->full_address ?? $eventVotingVenue?->raw_address,
            $eventVotingAddressModel?->city,
            $eventVotingAddressModel?->street,
            $eventVotingAddressModel?->building,
        );
        $eventVotingLatitude = $eventVotingAddressModel?->latitude;
        $eventVotingLongitude = $eventVotingAddressModel?->longitude;
        $pollClosesAt = $poll->closes_at->setTimezone(
            (string) config('app.timezone', 'Europe/Moscow'),
        );
        $coordinationParticipants = collect();

        if ($canCreateEvent && $coordination->flow_type === CoordinationFlowTypeEnum::EVENT_ATTENDANCE) {
            $coordinationParticipants = $poll->options
                ->flatMap(function ($option) {
                    return $option->selections->map(function ($selection) use ($option): array {
                        $user = $selection->ballot->user;

                        return [
                            'id' => $user->id,
                            'name' => trim(implode(' ', array_filter([
                                $user->profile?->first_name,
                                $user->profile?->last_name,
                            ]))) ?: ($user->username ?: 'Пользователь #'.$user->id),
                            'answer' => $option->label,
                            'intent' => $option->value['intent'] ?? null,
                        ];
                    });
                })
                ->unique('id')
                ->values();
        }

        return ThemeResolver::page('coordination.show', [
            'coordination' => $coordination,
            'poll' => $poll,
            'ballot' => $ballot,
            'selectedOptionIds' => $ballot?->selections->pluck('option_id')->all() ?? [],
            'canManage' => $canManage,
            'canSeeResults' => $canSeeResults,
            'canCreateEvent' => $canCreateEvent,
            'canApplyEventChange' => $canApplyEventChange,
            'contextEvent' => $contextEvent,
            'organizerName' => $organizerName,
            'eventVotingVenue' => $eventVotingVenue,
            'eventVotingStartsAt' => $eventVotingStartsAt,
            'eventVotingEndsAt' => $eventVotingEndsAt,
            'eventVotingDate' => $eventVotingDate,
            'eventVotingAddress' => $eventVotingAddress,
            'eventVotingLatitude' => $eventVotingLatitude,
            'eventVotingLongitude' => $eventVotingLongitude,
            'pollClosesAt' => $pollClosesAt,
            'venues' => $canCreateEvent ? $eventVenues->handle() : collect(),
            'suggestionVenues' => $poll->allows_suggestions && $poll->subject_type === PollSubjectTypeEnum::VENUE
                ? $eventVenues->handle()
                : collect(),
            'types' => EventTypeEnum::cases(),
            'visibilities' => EventVisibilityEnum::cases(),
            'defaultType' => EventTypeEnum::GAME_TRAINING,
            'currentDate' => $now->format('Ymd'),
            'defaultTitle' => $coordination->title,
            'defaultDescription' => collect([
                $coordination->description,
                $decisionDescription,
            ])->filter()->implode("\n\n"),
            'defaultStartsAt' => $defaultStartsAt->format('Y-m-d\TH:i'),
            'minimumStartsAt' => $minimumStartsAt->format('Y-m-d\TH:i'),
            'coordinatedStartsAt' => $coordinatedStartsAt,
            'coordinatedVenueId' => is_numeric($venueId) ? (int) $venueId : null,
            'coordinatedDuration' => $coordinatedDuration,
            'durationOptions' => range(30, 480, 30),
            'defaultDuration' => 60,
            'coordinationParticipants' => $coordinationParticipants,
            'telegramChats' => $telegramChats->activeEventChats(),
        ]);
    }

    public function vote(
        VoteInPollRequest $request,
        CoordinationSession $coordination,
        VoteInPollHandler $handler,
    ): RedirectResponse {
        $poll = $this->currentPoll($coordination);

        try {
            $handler->handle($poll->id, $request->user(), $request->validated('option_ids'));
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Ваш голос сохранён.');
    }

    public function suggest(
        SuggestPollOptionRequest $request,
        CoordinationSession $coordination,
        SuggestPollOptionHandler $handler,
    ): RedirectResponse {
        $poll = $this->currentPoll($coordination);

        try {
            $handler->handle($poll->id, $request->user(), $request->validated('option'));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Вариант добавлен.');
    }

    public function close(
        Request $request,
        CoordinationSession $coordination,
        ClosePollHandler $handler,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $handler->handle($this->currentPoll($coordination)->id, $actor);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Голосование закрыто. Выберите итоговый вариант или отмените согласование.');
    }

    public function decide(
        DecideCoordinationRequest $request,
        CoordinationSession $coordination,
        DecideCoordinationHandler $handler,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $handler->handle($coordination->id, (int) $request->validated('option_id'), $actor);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Результат принят.');
    }

    public function cancel(
        Request $request,
        CoordinationSession $coordination,
        CancelCoordinationHandler $handler,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $handler->handle($coordination->id, $actor);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Согласование отменено.');
    }

    public function createEvent(
        CreateEventRequest $request,
        CoordinationSession $coordination,
        CreateEventFromCoordinationHandler $handler,
        CurrentActorResolver $actors,
        TelegramChatRegistry $telegramChats,
        PrepareTelegramEventPublicationsHandler $prepareTelegramPublications,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $data = $request->validated();
            $telegramChats->activeEventChats();
            $event = DB::transaction(function () use (
                $coordination,
                $actor,
                $data,
                $handler,
                $prepareTelegramPublications,
            ) {
                $event = $handler->handle($coordination->id, $actor, $data);

                if ((bool) ($data['publish_to_telegram'] ?? false)) {
                    $prepareTelegramPublications->handle(
                        $event,
                        $data['telegram_chat_ids'] ?? [],
                    );
                }

                return $event;
            });
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $message = $event->booking?->status->value === 'confirmed'
            ? 'Мероприятие создано, площадка забронирована.'
            : 'Мероприятие создано. Бронирование ожидает подтверждения площадки.';

        return redirect()->route('events.show', $event->routeIdentifier())
            ->with('status', $message);
    }

    public function applyEventChange(
        Request $request,
        CoordinationSession $coordination,
        ApplyEventCoordinationHandler $handler,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $event = $handler->handle($coordination->id, $actor);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('events.show', $event->routeIdentifier())
            ->with('status', 'Согласованный перенос применён. Участникам нужно повторно подтвердить участие.');
    }

    private function currentPoll(CoordinationSession $session): Poll
    {
        return $session->polls()
            ->where(function ($query): void {
                $query->where('status', PollStatusEnum::OPEN->value)
                    ->orWhere(function ($closed): void {
                        $closed->where('status', PollStatusEnum::CLOSED->value)
                            ->whereDoesntHave('decision');
                    });
            })
            ->first()
            ?? $session->polls()->latest('step_order')->firstOrFail();
    }
}
