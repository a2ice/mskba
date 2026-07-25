<?php

namespace App\Modules\Coordination\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coordination\Application\Services\CoordinationAccess;
use App\Modules\Coordination\Application\UseCases\CancelCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\ClosePollHandler;
use App\Modules\Coordination\Application\UseCases\CreateCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\CreateEventFromCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\DecideCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\SuggestPollOptionHandler;
use App\Modules\Coordination\Application\UseCases\VoteInPollHandler;
use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Coordination\Presentation\Http\Requests\CreateCoordinationRequest;
use App\Modules\Coordination\Presentation\Http\Requests\DecideCoordinationRequest;
use App\Modules\Coordination\Presentation\Http\Requests\SuggestPollOptionRequest;
use App\Modules\Coordination\Presentation\Http\Requests\VoteInPollRequest;
use App\Modules\Event\Application\UseCases\ListEventVenuesHandler;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Presentation\Http\Requests\CreateEventRequest;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Telegram\Application\Services\TelegramChatRegistry;
use App\Modules\Telegram\Application\UseCases\PrepareTelegramCoordinationPublicationsHandler;
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
        TelegramChatRegistry $telegramChats,
        ListEventVenuesHandler $eventVenues,
    ): Response {
        return ThemeResolver::page('coordination.create', [
            'selectionModes' => PollSelectionModeEnum::cases(),
            'subjectTypes' => collect(PollSubjectTypeEnum::cases())
                ->reject(fn (PollSubjectTypeEnum $type): bool => $type === PollSubjectTypeEnum::PARTICIPATION)
                ->values(),
            'resultsVisibilities' => PollResultsVisibilityEnum::cases(),
            'defaultClosesAt' => now()->addHour()->format('Y-m-d\TH:i'),
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
    ): Response {
        $coordination->load([
            'organizerActor.user.profile.activeAvatar',
            'decision.option',
            'eventTransition.event',
            'polls',
        ]);
        $poll = $coordination->polls->firstOrFail();
        $ballot = $request->user() === null
            ? null
            : $poll->ballots()->with('selections')->where('user_id', $request->user()->id)->first();
        $actor = $actors->resolveForRequest($request);
        $hasVoted = $ballot !== null;
        $canSeeResults = match ($poll->results_visibility) {
            PollResultsVisibilityEnum::ALWAYS => true,
            PollResultsVisibilityEnum::AFTER_VOTE => $hasVoted || $poll->status->value !== 'open',
            PollResultsVisibilityEnum::AFTER_CLOSE => $poll->status->value !== 'open',
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
            && $coordination->decision !== null
            && $coordination->eventTransition === null;
        $now = CarbonImmutable::now((string) config('app.timezone', 'Europe/Moscow'));
        $defaultStartsAt = $now->addMinutes(15)->ceilMinute();
        $decisionDescription = $coordination->decision === null
            ? null
            : 'Согласованный вариант: '.$coordination->decision->option->label;

        return ThemeResolver::page('coordination.show', [
            'coordination' => $coordination,
            'poll' => $poll,
            'ballot' => $ballot,
            'selectedOptionIds' => $ballot?->selections->pluck('option_id')->all() ?? [],
            'canManage' => $canManage,
            'canSeeResults' => $canSeeResults,
            'canCreateEvent' => $canCreateEvent,
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
            'durationOptions' => range(30, 480, 30),
            'defaultDuration' => 60,
        ]);
    }

    public function vote(
        VoteInPollRequest $request,
        CoordinationSession $coordination,
        VoteInPollHandler $handler,
    ): RedirectResponse {
        $poll = $coordination->polls()->firstOrFail();

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
        $poll = $coordination->polls()->oldest('id')->firstOrFail();

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
            $handler->handle($coordination->polls()->firstOrFail()->id, $actor);
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
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $event = $handler->handle($coordination->id, $actor, $request->validated());
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $message = $event->booking?->status->value === 'confirmed'
            ? 'Мероприятие создано, площадка забронирована.'
            : 'Мероприятие создано. Бронирование ожидает подтверждения площадки.';

        return redirect()->route('events.show', $event->routeIdentifier())
            ->with('status', $message);
    }
}
