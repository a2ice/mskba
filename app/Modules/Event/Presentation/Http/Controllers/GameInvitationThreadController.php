<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\GameAdmissionNotificationStateService;
use App\Modules\Event\Application\Services\GameAdmissionService;
use App\Modules\Event\Application\Services\GameInvitationConversation;
use App\Modules\Event\Application\Services\GameInvitationTeammates;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\GameInvitationThread;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Notification\Application\Services\AccountConversationRail;
use App\Modules\Notification\Application\UseCases\MarkConversationNotificationsReadHandler;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class GameInvitationThreadController extends Controller
{
    public function show(Request $request, GameInvitationThread $context, GameInvitationConversation $conversations,
        AccountConversationRail $rail, MarkConversationNotificationsReadHandler $read, GameInvitationTeammates $teammates): Response
    {
        $viewer = $request->user()->canonical();
        abort_unless($conversations->canRead($context, $viewer), 403);
        $thread = $context->thread()->firstOrFail();
        $messages = $thread->messages()->with('sender')->latest('id')->paginate(10);
        $invitations = $conversations->invitations($context)->orderBy('id')->get();
        $read->handle($viewer, gameInvitationThreadId: $context->id);
        $context->loadMissing(['game.event.venue', 'team']);
        $game = $context->game;
        $canRespond = $conversations->canRepresentTeam($context, $viewer)
            && $game && !$game->trashed() && $game->hasSportConfiguration()
            && $game->status->value === 'scheduled' && $game->actual_started_at === null
            && $game->sides_confirmed_at === null && $game->event?->status?->value === 'published'
            && !$game->event?->ends_at?->isPast();

        $latest = $invitations->last();
        $canNotify = $latest !== null && $conversations->canRepresentTeam($context, $viewer) && $teammates->available($context, $latest);
        $players = $canNotify ? $teammates->eligible($context, $viewer) : collect();
        $notified = $latest ? $teammates->alreadyNotified($latest) : [];

        return ThemeResolver::page('account.game-invitation-conversation', [
            'context' => $context,
            'thread' => $thread,
            'messages' => $messages,
            'invitations' => $invitations,
            'canRespond' => $canRespond,
            'canNotify' => $canNotify,
            'players' => $players,
            'notified' => $notified,
            'latestInvitation' => $latest,
            'viewer' => $viewer,
            'conversationCards' => $rail->items($viewer, $request),
            'activeConversationId' => 'game-invitation-'.$context->id,
        ]);
    }

    public function state(Request $request, GameInvitationThread $context, GameInvitationConversation $conversations): JsonResponse
    {
        $viewer = $request->user()->canonical();
        abort_unless($conversations->canRead($context, $viewer), 403);
        $thread = $context->thread()->firstOrFail();
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $messages = $thread->messages()->with('sender')->when(isset($data['before']),
            fn ($query) => $query->where('id', '<', $data['before']))->latest('id')->limit(11)->get();
        $page = $messages->take(10);

        return response()->json([
            'id' => $context->id,
            'game_id' => $context->game_id,
            'team_id' => $context->team_id,
            'can_represent_team' => $conversations->canRepresentTeam($context, $viewer),
            'can_organize' => $conversations->canOrganize($context, $viewer),
            'invitations' => $conversations->invitations($context)->orderBy('id')->get()
                ->map(fn ($admission) => [
                    'id' => $admission->id,
                    'status' => $admission->status->value,
                    'created_at' => $admission->created_at,
                    'responded_at' => $admission->responded_at,
                ])->values(),
            'messages' => $page->reverse()->values()->map(fn ($message) => [
                'id' => $message->id,
                'body' => $message->body,
                'sender' => $message->sender?->username,
                'mine' => in_array((int) $message->sender_user_id, $viewer->identityIds(), true),
                'created_at' => $message->created_at,
            ]),
            'next_before' => $messages->count() > 10 ? $page->last()->id : null,
        ]);
    }

    public function message(Request $request, GameInvitationThread $context, GameInvitationConversation $conversations): JsonResponse|RedirectResponse
    {
        $viewer = $request->user()->canonical();
        abort_unless($conversations->canRead($context, $viewer), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $conversations->send($context, $viewer, $data['body']);

        return $request->expectsJson() ? response()->json(['message' => 'Сообщение отправлено.'], 201)
            : redirect()->route('account.notifications.threads.game-invitation', $context);
    }

    public function notifyTeammates(Request $request, GameInvitationThread $context, int $admission,
        GameInvitationTeammates $teammates): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'recipients' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'recipients.*' => ['required', 'integer', 'distinct', 'min:1'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $count = $teammates->send($context, $admission, $request->user(), $data['recipients'], $data['comment'] ?? null);
        $message = $count ? 'Игроки оповещены: '.$count : 'Выбранные игроки уже оповещены.';

        return $request->expectsJson() ? response()->json(['sent' => $count, 'message' => $message])
            : redirect()->route('account.notifications.threads.game-invitation', $context)->with('status', $message);
    }

    public function respond(Request $request, GameInvitationThread $context, int $admission,
        GameInvitationConversation $conversations, CurrentActorResolver $actors,
        GameAdmissionService $admissions, GameAdmissionNotificationStateService $notificationState): JsonResponse|RedirectResponse
    {
        $viewer = $request->user()->canonical();
        abort_unless($conversations->canRepresentTeam($context, $viewer), 403);
        $item = $conversations->invitations($context)->whereKey($admission)->firstOrFail();
        $game = $context->game;
        abort_if($game === null || $game->trashed(), 422);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'declined'])],
            'response_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updated = $admissions->respond($game, $item, $actors->resolveForRequest($request) ?? abort(403),
                GameAdmissionStatusEnum::from($data['decision']), $data['response_comment'] ?? null);
            $notificationState->resolve($updated);
            event(new EventChanged($game->event_id));
        } catch (InvalidArgumentException $exception) {
            return $request->expectsJson() ? response()->json(['message' => $exception->getMessage()], 422)
                : back()->withErrors(['invitation' => $exception->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['message' => 'Ответ сохранён.'])
            : redirect()->route('account.notifications.threads.game-invitation', $context);
    }
}
