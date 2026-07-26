<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Telegram\Application\DTO\TelegramUserIdentityDTO;
use App\Modules\Telegram\Application\Services\TelegramBotLoginChallengeStore;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use InvalidArgumentException;
use Throwable;

final class HandleTelegramBotLoginCallback
{
    public function __construct(
        private readonly TelegramBotLoginChallengeStore $challenges,
        private readonly ResolveTelegramUserHandler $resolveTelegramUser,
        private readonly TelegramBotApiClient $telegram,
    ) {}

    /** @param array<string, mixed> $callback */
    public function handle(array $callback): void
    {
        $callbackId = data_get($callback, 'id');
        $telegramUser = data_get($callback, 'from');
        $telegramUserId = data_get($callback, 'from.id');
        $chatId = data_get($callback, 'message.chat.id');
        $chatType = data_get($callback, 'message.chat.type');
        $messageId = data_get($callback, 'message.message_id');
        $data = data_get($callback, 'data');

        if (! is_string($callbackId)
            || ! is_array($telegramUser)
            || ! is_numeric($telegramUserId)
            || ! is_numeric($chatId)
            || ! is_numeric($messageId)
            || (string) $chatType !== 'private'
            || (string) $chatId !== (string) $telegramUserId
            || ! is_string($data)
            || preg_match('/^auth:login:([A-Za-z0-9_-]{43})$/', $data, $matches) !== 1) {
            return;
        }

        $token = $matches[1];

        if ($this->challenges->find($token) === null) {
            $this->answer($callbackId, 'Ссылка для входа истекла. Запустите вход на сайте ещё раз.', true);

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
                source: 'telegram_bot_login',
                registrationChannel: UserRegistrationChannelEnum::TELEGRAM_WEB,
                authenticated: true,
            ));

            if ($resolved['user']->isBlocked()) {
                throw new InvalidArgumentException('Аккаунт заблокирован. Обратитесь в поддержку.');
            }

            if (! $this->challenges->approve(
                $token,
                (int) $resolved['user']->id,
                (int) $resolved['telegram_account']->id,
                $resolved['created'],
            )) {
                $this->answer($callbackId, 'Этот вход уже подтверждён или ссылка истекла.', true);

                return;
            }

            $this->answer($callbackId, 'Вход подтверждён. Вернитесь на сайт.');
            $this->markMessageConfirmed((string) $chatId, (int) $messageId);
        } catch (InvalidArgumentException $exception) {
            $this->answer($callbackId, $exception->getMessage(), true);
        } catch (Throwable $exception) {
            report($exception);
            $this->answer($callbackId, 'Не удалось подтвердить вход. Попробуйте ещё раз.', true);
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

    private function markMessageConfirmed(string $chatId, int $messageId): void
    {
        try {
            $this->telegram->call('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => 'Вход в MSKBA подтверждён. Можно вернуться на сайт.',
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
