<?php

namespace App\Modules\Notification\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\Services\AccountConversationRail;
use App\Modules\Notification\Application\Services\DirectMessageBlockService;
use App\Modules\Notification\Application\Services\DirectMessageRecipientAccess;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Application\UseCases\MarkConversationNotificationsReadHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\DirectConversation;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChangedBroadcast;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class DirectMessagesController extends Controller
{
    private function authorizeParticipant(Request $request, DirectConversation $conversation): array
    {
        $viewer = $request->user()->canonical();
        abort_unless(in_array((int) $conversation->user_low_id, $viewer->identityIds(), true)
            || in_array((int) $conversation->user_high_id, $viewer->identityIds(), true), 403);
        $otherId = in_array((int) $conversation->user_low_id, $viewer->identityIds(), true)
            ? $conversation->user_high_id : $conversation->user_low_id;
        $recipient = User::query()->with('profile')->findOrFail($otherId)->canonical();

        return [$viewer, $recipient];
    }

    /** QA-only discovery: do not expose a global messenger directory in production. */
    public function testCandidates(Request $request, SearchDiscoverableUsers $search,
        DirectMessageBlockService $blocks): JsonResponse
    {
        abort_unless(config('features.direct_messages.test_entry'), 404);
        $viewer = $request->user()->canonical();
        abort_if($viewer->isBlocked() || $viewer->trashed(), 403);
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 60) {
            return response()->json(['users' => []]);
        }
        // Confirmation is a separate account privilege, not a prerequisite
        // for private chats. Search still enforces privacy and excludes blocked users.
        $users = $search->handle($viewer, $q, limit: 12,
            requiredAccess: UserPrivacySettingTypeEnum::MESSAGES,
            alternateRequiredAccess: static function (Builder $candidateQuery) use ($viewer): void {
                // One correlated EXISTS, not a candidate-by-candidate permission check.
                // A real outgoing message from the candidate grants this viewer a reply.
                $candidateQuery->whereExists(static function (QueryBuilder $query) use ($viewer): void {
                    $query->selectRaw('1')
                        ->from('direct_conversations as dc')
                        ->join('direct_messages as dm', 'dm.direct_conversation_id', '=', 'dc.id')
                        ->whereColumn('dm.sender_user_id', 'users.id')
                        ->where('dm.kind', 'message')
                        ->where(static fn (QueryBuilder $pair) => $pair
                            ->where(static fn (QueryBuilder $low) => $low
                                ->where('dc.user_low_id', $viewer->id)
                                ->whereColumn('dc.user_high_id', 'users.id'))
                            ->orWhere(static fn (QueryBuilder $high) => $high
                                ->where('dc.user_high_id', $viewer->id)
                                ->whereColumn('dc.user_low_id', 'users.id')));
                });
            });

        return response()->json(['users' => $users
            ->reject(fn (User $candidate) => $blocks->blockedBetween($viewer, $candidate))
            ->take(10)->map(fn (User $candidate) => [
                'id' => $candidate->id,
                'name' => $candidate->username ?: (trim(($candidate->profile?->first_name ?? '').' '.($candidate->profile?->last_name ?? '')) ?: 'Участник'),
            ])->values()]);
    }

    public function access(Request $request, User $user, DirectMessageRecipientAccess $recipientAccess,
        DirectMessageBlockService $blocks): JsonResponse
    {
        $viewer = $request->user()->canonical();
        $recipient = $user->canonical();
        $allowed = ! $viewer->isSameIdentity($recipient)
            && ! $viewer->isBlocked() && ! $recipient->isBlocked() && ! $recipient->trashed()
            && ! $blocks->blockedBetween($viewer, $recipient)
            && $recipientAccess->allows($recipient, $viewer);

        return response()->json([
            'can_message' => $allowed,
            'message' => $allowed
                ? 'Напишите личное сообщение.'
                : 'Этот пользователь не принимает личные сообщения.',
        ]);
    }

    public function send(Request $request, User $user,
        DirectMessageRecipientAccess $recipientAccess,
        CreateUserNotificationHandler $notifications,
        DirectMessageBlockService $blocks): JsonResponse
    {
        $viewer = $request->user()->canonical();
        $recipient = $user->canonical();
        abort_if($viewer->isSameIdentity($recipient), 422, 'Нельзя отправить сообщение себе.');
        abort_if($viewer->isBlocked() || $viewer->trashed() || $recipient->isBlocked() || $recipient->trashed(), 403);
        abort_unless(! $blocks->blockedBetween($viewer, $recipient)
            && $recipientAccess->allows($recipient, $viewer),
            403, 'Отправка личных сообщений недоступна.');
        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);
        $ids = [(int) $viewer->id, (int) $recipient->id];
        sort($ids);

        [$conversation, $message] = DB::transaction(function () use ($ids, $viewer, $recipient, $data, $recipientAccess, $blocks): array {
            // Same user-row lock order as block/unblock: no send-after-block race.
            User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            abort_unless(! $blocks->blockedBetween($viewer, $recipient)
                && $recipientAccess->allows($recipient, $viewer), 403);
            DB::table('direct_conversations')->insertOrIgnore([
                'user_low_id' => $ids[0],
                'user_high_id' => $ids[1],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $conversation = DirectConversation::query()
                ->where('user_low_id', $ids[0])->where('user_high_id', $ids[1])
                ->lockForUpdate()->firstOrFail();
            $message = $conversation->messages()->create([
                'sender_user_id' => $viewer->id,
                'body' => trim($data['body']),
            ]);
            $conversation->touch();

            return [$conversation, $message];
        });

        $name = $viewer->username ?: (trim(($viewer->profile?->first_name ?? '').' '.($viewer->profile?->last_name ?? '')) ?: 'Участник');
        $notifications->handle(new CreateUserNotificationDTO(
            userId: (int) $recipient->id,
            type: UserNotificationTypeEnum::SYSTEM,
            title: 'Личное сообщение от '.$name,
            body: $message->body,
            actionUrl: route('account.notifications.threads.direct', $conversation->id),
            actionText: 'Открыть переписку',
            payload: [
                'source' => 'direct.message',
                'direct_conversation_id' => $conversation->id,
                'direct_message_id' => $message->id,
            ],
        ));

        try {
            ConversationChangedBroadcast::dispatch('direct', $conversation->id);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return response()->json([
            'message' => 'Сообщение отправлено.',
            'conversation_url' => route('account.notifications.threads.direct', $conversation->id),
        ], 201);
    }

    public function show(Request $request, DirectConversation $conversation,
        DirectMessageBlockService $blocks, DirectMessageRecipientAccess $recipientAccess): Response
    {
        [$viewer, $recipient] = $this->authorizeParticipant($request, $conversation);
        $page = $this->page($conversation);
        $messages = $page['entries'];
        $blockedByMe = $blocks->blockedBy($viewer, $recipient);
        $canSend = ! $viewer->isBlocked() && ! $viewer->trashed()
            && ! $recipient->isBlocked() && ! $recipient->trashed()
            && ! $blocks->blockedBetween($viewer, $recipient)
            && $recipientAccess->allows($recipient, $viewer);
        $nextBefore = $page['next_before'];
        $unread = UserNotification::query()->whereIn('user_id', $viewer->identityIds())
            ->where('payload->direct_conversation_id', $conversation->id)
            ->where('status', UserNotificationStatusEnum::NEW)->count();

        $conversationCards = app(AccountConversationRail::class)->items($viewer, $request);
        $activeConversationId = 'direct-'.$conversation->id;

        return ThemeResolver::page('account.direct-conversation', compact('conversation', 'viewer', 'recipient', 'messages', 'unread', 'nextBefore', 'canSend', 'blockedByMe', 'conversationCards', 'activeConversationId'));
    }

    public function block(Request $request, User $user, DirectMessageBlockService $blocks): JsonResponse|RedirectResponse
    {
        $changed = $blocks->update($request->user(), $user, true);
        if ($changed) {
            $this->notifyBlockChange($request->user(), $user);
        }

        return $request->expectsJson() ? response()->json(['updated' => $changed]) : back();
    }

    public function unblock(Request $request, User $user, DirectMessageBlockService $blocks): JsonResponse|RedirectResponse
    {
        $changed = $blocks->update($request->user(), $user, false);
        if ($changed) {
            $this->notifyBlockChange($request->user(), $user);
        }

        return $request->expectsJson() ? response()->json(['updated' => $changed]) : back();
    }

    public function state(Request $request, DirectConversation $conversation,
        DirectMessageBlockService $blocks, DirectMessageRecipientAccess $recipientAccess): JsonResponse
    {
        [$viewer, $recipient] = $this->authorizeParticipant($request, $conversation);
        $byMe = $blocks->blockedBy($viewer, $recipient);
        $canSend = ! $viewer->isBlocked() && ! $viewer->trashed()
            && ! $recipient->isBlocked() && ! $recipient->trashed()
            && ! $blocks->blockedBetween($viewer, $recipient)
            && $recipientAccess->allows($recipient, $viewer);

        return response()->json([
            'can_send' => $canSend,
            'blocked_by_me' => $byMe,
            'status' => $canSend ? '' : ($byMe
                ? 'Вы заблокировали пользователя.'
                : 'Отправка личных сообщений недоступна.'),
        ]);
    }

    private function notifyBlockChange(User $viewer, User $other): void
    {
        $ids = [$viewer->canonical()->id, $other->canonical()->id];
        sort($ids);
        $conversation = DirectConversation::query()->where('user_low_id', $ids[0])
            ->where('user_high_id', $ids[1])->first();
        if ($conversation !== null) {
            try {
                ConversationChangedBroadcast::dispatch('direct', $conversation->id, true);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    public function history(Request $request, DirectConversation $conversation): JsonResponse
    {
        [$viewer] = $this->authorizeParticipant($request, $conversation);
        $before = $request->query('before');
        abort_unless($before === null || (ctype_digit((string) $before) && (int) $before > 0), 422);
        $page = $this->page($conversation, $before !== null ? (int) $before : null);

        return response()->json([
            'html' => $page['entries']->map(fn ($message) => view('theme::pages.account.partials.direct-chat-entry', [
                'message' => $message, 'viewer' => $viewer,
            ])->render())->implode(''),
            'next_before' => $page['next_before'],
        ]);
    }

    private function page(DirectConversation $conversation, ?int $before = null): array
    {
        $query = $conversation->messages()->with('sender')->orderByDesc('id');
        if ($before !== null) {
            $query->where('id', '<', $before);
        }
        $records = $query->limit(11)->get();

        return [
            'entries' => $records->take(10)->reverse()->values(),
            'next_before' => $records->count() > 10 ? (string) $records->take(10)->last()->id : null,
        ];
    }

    public function read(Request $request, DirectConversation $conversation,
        MarkConversationNotificationsReadHandler $read): JsonResponse
    {
        [$viewer] = $this->authorizeParticipant($request, $conversation);

        return response()->json(['unread_count' => $read->handle($viewer, null, $conversation->id)]);
    }
}
