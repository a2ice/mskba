<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Event\Application\UseCases\JoinEventHandler;
use App\Modules\Event\Application\UseCases\LeaveEventHandler;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Domain\Models\TelegramEventPublication;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use InvalidArgumentException;

final class HandleEventParticipationCallback
{
    public function __construct(
        private readonly JoinEventHandler $join,
        private readonly LeaveEventHandler $leave,
        private readonly TelegramBotApiClient $telegram,
    ) {}

    /** @param array<string, mixed> $callback */
    public function handle(array $callback): void
    {
        $callbackId = data_get($callback, 'id');
        $telegramUserId = data_get($callback, 'from.id');
        $chatId = data_get($callback, 'message.chat.id');
        $messageId = data_get($callback, 'message.message_id');
        $data = data_get($callback, 'data');

        if (! is_string($callbackId)
            || ! is_numeric($telegramUserId)
            || ! is_numeric($chatId)
            || ! is_numeric($messageId)
            || ! is_string($data)
            || preg_match('/^event:(\d+):(join|leave)$/', $data, $matches) !== 1) {
            return;
        }

        $eventId = (int) $matches[1];
        $action = $matches[2];
        $publication = TelegramEventPublication::query()
            ->where('event_id', $eventId)
            ->where('chat_id', (string) $chatId)
            ->where('message_id', (int) $messageId)
            ->first();

        if ($publication === null) {
            $this->answer($callbackId, 'Это сообщение больше не связано с мероприятием.', true);

            return;
        }

        $account = TelegramAccount::query()
            ->with('user')
            ->where('telegram_user_id', (int) $telegramUserId)
            ->first();

        if ($account?->user === null) {
            $this->answer(
                $callbackId,
                'Сначала откройте приложение MSKBA из закреплённого сообщения, чтобы связать аккаунт.',
                true,
            );

            return;
        }

        $event = Event::query()->find($eventId);

        if ($event === null) {
            $this->answer($callbackId, 'Мероприятие больше недоступно.', true);

            return;
        }

        try {
            if ($action === 'join') {
                $this->join->handle($event->routeIdentifier(), $account->user);
                $message = 'Вы записаны на мероприятие.';
            } else {
                $participant = $event->participants()
                    ->where('user_id', $account->user->id)
                    ->first();

                if ($participant?->status === EventParticipantStatusEnum::CONFIRMED) {
                    $this->leave->handle($event->routeIdentifier(), $account->user);
                    $message = 'Участие отменено.';
                } else {
                    $message = 'Вы не записаны на это мероприятие.';
                }
            }

            $this->answer($callbackId, $message);
        } catch (InvalidArgumentException $exception) {
            $this->answer($callbackId, $exception->getMessage(), true);
        }
    }

    private function answer(string $callbackId, string $message, bool $alert = false): void
    {
        $this->telegram->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => mb_substr($message, 0, 200),
            'show_alert' => $alert,
            'cache_time' => 0,
        ]);
    }
}
