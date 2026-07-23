<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Event\Application\UseCases\DeclineEventHandler;
use App\Modules\Event\Application\UseCases\JoinEventHandler;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Telegram\Application\DTO\TelegramUserIdentityDTO;
use App\Modules\Telegram\Domain\Models\TelegramEventPublication;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use InvalidArgumentException;
use Throwable;

final class HandleEventParticipationCallback
{
    public function __construct(
        private readonly JoinEventHandler $join,
        private readonly DeclineEventHandler $decline,
        private readonly ResolveTelegramUserHandler $resolveTelegramUser,
        private readonly TelegramBotApiClient $telegram,
    ) {}

    /** @param array<string, mixed> $callback */
    public function handle(array $callback): void
    {
        $callbackId = data_get($callback, 'id');
        $telegramUserId = data_get($callback, 'from.id');
        $telegramUser = data_get($callback, 'from');
        $chatId = data_get($callback, 'message.chat.id');
        $messageId = data_get($callback, 'message.message_id');
        $data = data_get($callback, 'data');

        if (! is_string($callbackId)
            || ! is_numeric($telegramUserId)
            || ! is_array($telegramUser)
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

        $event = Event::query()->find($eventId);

        if ($event === null) {
            $this->answer($callbackId, 'Мероприятие больше недоступно.', true);

            return;
        }

        try {
            $resolved = $this->resolveTelegramUser->handle(new TelegramUserIdentityDTO(
                id: (int) $telegramUserId,
                username: $this->nullableString(data_get($telegramUser, 'username')),
                firstName: $this->nullableString(data_get($telegramUser, 'first_name')),
                lastName: $this->nullableString(data_get($telegramUser, 'last_name')),
                languageCode: $this->nullableString(data_get($telegramUser, 'language_code')),
                photoUrl: null,
                rawData: ['user' => $telegramUser],
                source: 'telegram_chat_callback',
                registrationChannel: UserRegistrationChannelEnum::TELEGRAM_CHAT,
            ));
            $user = $resolved['user'];

            if ($user->isBlocked()) {
                throw new InvalidArgumentException('Ваш аккаунт заблокирован.');
            }

            if ($action === 'join') {
                $this->join->handle($event->routeIdentifier(), $user);
                $message = 'Вы записаны на мероприятие.';
            } else {
                $this->decline->handle($event->routeIdentifier(), $user);
                $message = 'Ответ «Не пойду» сохранён.';
            }

            if ($resolved['created']) {
                $message .= ' Аккаунт MSKBA создан.';
            }

            $this->answer($callbackId, $message);
        } catch (InvalidArgumentException $exception) {
            $this->answer($callbackId, $exception->getMessage(), true);
        } catch (Throwable $exception) {
            report($exception);
            $this->answer($callbackId, 'Не удалось сохранить ответ. Попробуйте ещё раз.', true);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
