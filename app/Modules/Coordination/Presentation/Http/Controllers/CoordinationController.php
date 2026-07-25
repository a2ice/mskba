<?php

namespace App\Modules\Coordination\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coordination\Application\Services\CoordinationAccess;
use App\Modules\Coordination\Application\UseCases\CancelCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\ClosePollHandler;
use App\Modules\Coordination\Application\UseCases\CreateCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\DecideCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\VoteInPollHandler;
use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Coordination\Presentation\Http\Requests\CreateCoordinationRequest;
use App\Modules\Coordination\Presentation\Http\Requests\DecideCoordinationRequest;
use App\Modules\Coordination\Presentation\Http\Requests\VoteInPollRequest;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function create(): Response
    {
        return ThemeResolver::page('coordination.create', [
            'selectionModes' => PollSelectionModeEnum::cases(),
            'resultsVisibilities' => PollResultsVisibilityEnum::cases(),
            'defaultClosesAt' => now()->addHour()->format('Y-m-d\TH:i'),
        ]);
    }

    public function store(
        CreateCoordinationRequest $request,
        CreateCoordinationHandler $handler,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $session = $handler->handle($actor, $request->validated());
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
    ): Response {
        $coordination->load([
            'organizerActor.user.profile.activeAvatar',
            'decision.option',
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
                ->when(
                    $canSeeResults && ! $poll->is_anonymous,
                    fn ($optionQuery) => $optionQuery->with([
                        'selections.ballot.user.profile',
                    ]),
                ),
        ]);

        return ThemeResolver::page('coordination.show', [
            'coordination' => $coordination,
            'poll' => $poll,
            'ballot' => $ballot,
            'selectedOptionIds' => $ballot?->selections->pluck('option_id')->all() ?? [],
            'canManage' => $actor !== null && $access->canManage($coordination, $actor),
            'canSeeResults' => $canSeeResults,
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
}
