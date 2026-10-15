<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\OperatorConversation;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChangedBroadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Shared write path for Filament and the account chat. Only service notices. */
final class OperatorConversationMessaging
{
    public function __construct(
        private readonly OperatorConversationAccess $access,
        private readonly CreateUserNotificationHandler $notifications,
    ) {}

    public function start(User $actor, User $recipient, string $body): OperatorConversation
    {
        $actor = $actor->canonical();
        $recipient = $recipient->canonical();
        abort_unless($this->access->mayContact($actor, $recipient), 403);
        $body = $this->validatedBody($body);
        $this->rateLimit($actor);

        [$conversation, $messageId] = DB::transaction(function () use ($actor, $recipient, $body): array {
            $ids = [$actor->id, $recipient->id];
            sort($ids);
            $locked = User::query()->whereIn('id', $ids)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($this->access->mayContact($locked[$actor->id], $locked[$recipient->id]), 403);

            DB::table('operator_conversations')->insertOrIgnore([
                'operator_user_id' => $actor->id,
                'recipient_user_id' => $recipient->id,
                'purpose' => 'service',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $conversation = OperatorConversation::query()
                ->where('operator_user_id', $actor->id)
                ->where('recipient_user_id', $recipient->id)
                ->lockForUpdate()->firstOrFail();
            $message = $conversation->messages()->create([
                'sender_user_id' => $actor->id,
                'body' => $body,
            ]);
            $conversation->touch();

            return [$conversation, $message->id];
        });

        $this->notify($conversation, $actor, $recipient, $body, $messageId);

        return $conversation;
    }

    public function send(User $actor, OperatorConversation $conversation, string $body): void
    {
        $actor = $actor->canonical();
        abort_unless($this->access->canWrite($actor, $conversation), 403);
        $body = $this->validatedBody($body);
        $this->rateLimit($actor);

        [$conversation, $targetId, $messageId] = DB::transaction(function () use ($actor, $conversation, $body): array {
            $locked = OperatorConversation::query()
                ->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $locked->load('operator', 'recipient');
            abort_unless($this->access->canWrite($actor, $locked), 403);
            $message = $locked->messages()->create([
                'sender_user_id' => $actor->id,
                'body' => $body,
            ]);
            $locked->touch();

            return [$locked, $actor->id === (int) $locked->operator_user_id
                ? (int) $locked->recipient_user_id : (int) $locked->operator_user_id, $message->id];
        });

        $target = User::query()->findOrFail($targetId);
        $this->notify($conversation, $actor, $target, $body, $messageId);
    }

    private function notify(OperatorConversation $conversation, User $sender, User $target,
        string $body, int $messageId): void
    {
        $isOperator = (int) $sender->id === (int) $conversation->operator_user_id;
        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: (int) $target->id,
            type: UserNotificationTypeEnum::MESSAGE,
            title: $isOperator
                ? 'Новое сообщение оператора MSKBA'
                : 'Ответ пользователя @'.$sender->username,
            body: $body,
            actionUrl: route('account.notifications.threads.operator', $conversation->id),
            actionText: 'Открыть переписку',
            payload: [
                'source' => 'operator.message',
                'operator_conversation_id' => $conversation->id,
                'operator_user_id' => $conversation->operator_user_id,
                'operator_message_id' => $messageId,
            ],
        ));

        try {
            ConversationChangedBroadcast::dispatch('operator', $conversation->id);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function validatedBody(string $body): string
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 2000) {
            throw ValidationException::withMessages([
                'body' => 'Сообщение должно содержать от 1 до 2000 символов.',
            ]);
        }

        return $body;
    }

    private function rateLimit(User $actor): void
    {
        // Reuse the same 3 messages / 10 seconds ceiling for all operator chats.
        $key = 'operator-messages:'.(int) $actor->canonical()->id;
        abort_unless(RateLimiter::attempt($key, 3, static fn (): bool => true, 10),
            429, 'Слишком много сообщений. Попробуйте позже.');
    }
}
