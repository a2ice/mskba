<?php

namespace App\Modules\Notification\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\DirectMessageBlockService;
use App\Modules\Notification\Application\Services\DirectMessageRecipientAccess;
use App\Modules\Notification\Application\Services\SupportTicketAccess;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\DirectConversation;
use App\Modules\Notification\Domain\Models\OperatorConversation;
use App\Modules\Notification\Application\Services\OperatorConversationAccess;
use App\Modules\Notification\Domain\Models\SupportTicket;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Presentation\Presenters\UserNotificationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationPopoverController extends Controller
{
    /**
     * Minimal, permission-checked contract. The browser never derives private
     * Reverb channels or send URLs from untrusted notification payload fields.
     */
    public function __invoke(Request $request, string $kind, int $id,
        SupportTicketAccess $supportAccess, DirectMessageBlockService $blocks,
        DirectMessageRecipientAccess $recipientAccess): JsonResponse
    {
        $viewer = $request->user()->canonical();
        abort_if($viewer->isBlocked() || $viewer->trashed(), 403);

        if ($kind === 'support') {
            $ticket = SupportTicket::query()->findOrFail($id);
            $thread = $ticket->thread()->firstOrFail();
            abort_unless($supportAccess->canRead($viewer, $ticket, $thread), 403);
            $canSend = $supportAccess->canWrite($viewer, $ticket, $thread);

            return $this->respond([
                'kind' => 'support',
                'history_url' => route('account.notifications.threads.support.history', $ticket, false),
                'state_url' => route('account.notifications.threads.support.state', $ticket, false),
                'send_url' => route('account.notifications.threads.support.messages', $ticket, false),
                'channel' => 'support-tickets.'.$thread->id,
                'limit' => 2000,
                'can_send' => $canSend,
                'status' => $ticket->status === 'closed' ? 'Обращение закрыто. История доступна для чтения.' : null,
            ]);
        }

        if ($kind === 'operator') {
            $conversation = OperatorConversation::query()->findOrFail($id);
            $access = app(OperatorConversationAccess::class);
            abort_unless($access->canRead($viewer, $conversation), 403);
            $canSend = $access->canWrite($viewer, $conversation);

            return $this->respond([
                'kind' => 'operator',
                'history_url' => route('account.notifications.threads.operator.history', $conversation, false),
                'state_url' => route('account.notifications.threads.operator.state', $conversation, false),
                'send_url' => route('account.notifications.threads.operator.messages', $conversation, false),
                'channel' => 'operator-conversations.'.$conversation->id,
                'limit' => 2000,
                'can_send' => $canSend,
                'status' => $canSend ? null : 'Отправка сообщений недоступна.',
            ]);
        }

        abort_unless($kind === 'direct', 404);
        $conversation = DirectConversation::query()->findOrFail($id);
        $viewerIds = $viewer->identityIds();
        abort_unless(in_array((int) $conversation->user_low_id, $viewerIds, true)
            || in_array((int) $conversation->user_high_id, $viewerIds, true), 403);
        $otherId = in_array((int) $conversation->user_low_id, $viewerIds, true)
            ? $conversation->user_high_id : $conversation->user_low_id;
        $recipient = User::query()->findOrFail($otherId)->canonical();
        $canSend = ! $recipient->isBlocked() && ! $recipient->trashed()
            && ! $blocks->blockedBetween($viewer, $recipient)
            && $recipientAccess->allows($recipient, $viewer);

        return $this->respond([
            'kind' => 'direct',
            'history_url' => route('account.notifications.threads.direct.history', $conversation, false),
            'state_url' => route('account.notifications.threads.direct.state', $conversation, false),
            'send_url' => route('account.notifications.direct.send', $recipient, false),
            'channel' => 'direct-conversations.'.$conversation->id,
            'limit' => 500,
            'can_send' => $canSend,
            'status' => $canSend ? null : 'Отправка личных сообщений недоступна.',
        ]);
    }

    /** Restore an already read notification without caching its private body in the browser. */
    public function notice(Request $request, int $id, UserNotificationPresenter $presenter): JsonResponse
    {
        $viewer = $request->user()->canonical();
        $notification = UserNotification::query()
            ->whereKey($id)->whereIn('user_id', $viewer->identityIds())
            ->firstOrFail();

        return $this->respond([
            ...$presenter->present($notification),
            'is_read' => $notification->status === UserNotificationStatusEnum::READ,
        ]);
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
