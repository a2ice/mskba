<?php

namespace App\Modules\Notification\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Application\Services\AccountConversationRail;
use App\Modules\Notification\Application\Services\OperatorConversationAccess;
use App\Modules\Notification\Application\Services\OperatorConversationMessaging;
use App\Modules\Notification\Application\UseCases\MarkConversationNotificationsReadHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\OperatorConversation;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class OperatorConversationsController extends Controller
{
    private function authorized(Request $request, OperatorConversation $conversation,
        OperatorConversationAccess $access): void
    {
        abort_unless($access->canRead($request->user(), $conversation), 403);
    }

    public function show(Request $request, OperatorConversation $conversation,
        OperatorConversationAccess $access, AccountConversationRail $rail): Response
    {
        $this->authorized($request, $conversation, $access);
        $viewer = $request->user()->canonical();
        $conversation->loadMissing('operator', 'recipient');
        $contact = $viewer->id === (int) $conversation->operator_user_id
            ? $conversation->recipient : $conversation->operator;
        $page = $this->page($conversation);
        $messages = $page['entries'];
        $nextBefore = $page['next_before'];
        $canSend = $access->canWrite($viewer, $conversation);
        $unread = UserNotification::query()->whereIn('user_id', $viewer->identityIds())
            ->where('payload->operator_conversation_id', $conversation->id)
            ->where('status', UserNotificationStatusEnum::NEW)->count();
        $conversationCards = $rail->items($viewer, $request);
        $activeConversationId = 'operator-'.$conversation->id;

        return ThemeResolver::page('account.operator-conversation', compact(
            'conversation', 'viewer', 'contact', 'messages', 'nextBefore',
            'canSend', 'unread', 'conversationCards', 'activeConversationId',
        ));
    }

    public function history(Request $request, OperatorConversation $conversation,
        OperatorConversationAccess $access): JsonResponse
    {
        $this->authorized($request, $conversation, $access);
        $before = $request->query('before');
        abort_unless($before === null || (ctype_digit((string) $before) && (int) $before > 0), 422);
        $page = $this->page($conversation, $before !== null ? (int) $before : null);
        $viewer = $request->user()->canonical();

        return response()->json([
            'html' => $page['entries']->map(fn ($message) => view('theme::pages.account.partials.chat-bubble', [
                'entry' => [
                    'kind' => 'message', 'id' => 'message-'.$message->id,
                    'text' => $message->body, 'at' => $message->created_at,
                    'sender' => $message->sender,
                ],
                'viewer' => $viewer,
            ])->render())->implode(''),
            'next_before' => $page['next_before'],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, OperatorConversation $conversation,
        OperatorConversationAccess $access, OperatorConversationMessaging $messaging): JsonResponse
    {
        abort_unless($access->canWrite($request->user(), $conversation), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $messaging->send($request->user(), $conversation, $data['body']);

        return response()->json(['message' => 'Сообщение отправлено.'], 201);
    }

    public function state(Request $request, OperatorConversation $conversation,
        OperatorConversationAccess $access): JsonResponse
    {
        $this->authorized($request, $conversation, $access);
        $canSend = $access->canWrite($request->user(), $conversation);

        return response()->json([
            'can_send' => $canSend,
            'status' => $canSend ? '' : 'Отправка сообщений недоступна.',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, OperatorConversation $conversation,
        OperatorConversationAccess $access, MarkConversationNotificationsReadHandler $read): JsonResponse
    {
        $this->authorized($request, $conversation, $access);

        return response()->json([
            'unread_count' => $read->handle($request->user(), operatorConversationId: $conversation->id),
        ]);
    }

    /** @return array{entries: \Illuminate\Support\Collection, next_before: ?string} */
    private function page(OperatorConversation $conversation, ?int $before = null): array
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
}
