<?php

namespace App\Modules\Notification\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Application\UseCases\MarkConversationNotificationsReadHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\DirectConversation;
use App\Modules\Notification\Domain\Models\DirectMessage;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
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

    public function access(Request $request, User $user, UserPrivacyAccessService $privacy): JsonResponse
    {
        $viewer = $request->user()->canonical();
        $recipient = $user->canonical();
        $allowed = ! $viewer->isSameIdentity($recipient)
            && ! $viewer->isBlocked() && ! $recipient->isBlocked() && ! $recipient->trashed()
            && $privacy->allows($recipient, $viewer, UserPrivacySettingTypeEnum::MESSAGES);

        return response()->json([
            'can_message' => $allowed,
            'message' => $allowed
                ? 'Напишите личное сообщение.'
                : 'Этот пользователь не принимает личные сообщения.',
        ]);
    }

    public function send(Request $request, User $user,
        UserPrivacyAccessService $privacy,
        CreateUserNotificationHandler $notifications): JsonResponse
    {
        $viewer = $request->user()->canonical();
        $recipient = $user->canonical();
        abort_if($viewer->isSameIdentity($recipient), 422, 'Нельзя отправить сообщение себе.');
        abort_if($viewer->isBlocked() || $viewer->trashed() || $recipient->isBlocked() || $recipient->trashed(), 403);
        abort_unless($privacy->allows($recipient, $viewer, UserPrivacySettingTypeEnum::MESSAGES),
            403, 'Этот пользователь не принимает личные сообщения.');
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $ids = [(int) $viewer->id, (int) $recipient->id];
        sort($ids);

        [$conversation, $message] = DB::transaction(function () use ($ids, $viewer, $data): array {
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

        return response()->json([
            'message' => 'Сообщение отправлено.',
            'conversation_url' => route('account.notifications.threads.direct', $conversation->id),
        ], 201);
    }

    public function show(Request $request, DirectConversation $conversation): Response
    {
        [$viewer, $recipient] = $this->authorizeParticipant($request, $conversation);
        $messages = $conversation->messages()->with('sender.profile')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);
        $unread = UserNotification::query()->whereIn('user_id', $viewer->identityIds())
            ->where('payload->direct_conversation_id', $conversation->id)
            ->where('status', \App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum::NEW)->count();

        return ThemeResolver::page('account.direct-conversation', compact('conversation', 'viewer', 'recipient', 'messages', 'unread'));
    }

    public function read(Request $request, DirectConversation $conversation,
        MarkConversationNotificationsReadHandler $read): JsonResponse
    {
        [$viewer] = $this->authorizeParticipant($request, $conversation);
        return response()->json(['unread_count' => $read->handle($viewer, null, $conversation->id)]);
    }
}
