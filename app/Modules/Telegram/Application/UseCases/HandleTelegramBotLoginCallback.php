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
        $challenge = $this->challenges->find($token);

        if ($challenge === null) {
            $this->answerSafely($callbackId, 'Ссылка для входа истекла. Запустите вход на сайте ещё раз.', true);

            return;
        }

        if (($challenge['status'] ?? null) === 'approved') {
            $this->answerSafely($callbackId, 'Вход уже подтверждён. Вернитесь на сайт.');
            $this->markMessageConfirmed((string) $chatId, (int) $messageId);

            return;
        }

        // Telegram keeps an inline button in the loading state until
        // answerCallbackQuery is received. Acknowledge the click before any
        // user resolution / DB work so the client does not spin while the
        // browser login is already being approved in the background.
        $acknowledged = $this->answerSafely($callbackId, 'Подтверждаем вход…', timeoutSeconds: 5);

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
                $currentChallenge = $this->challenges->find($token);
                if (($currentChallenge['status'] ?? null) === 'approved') {
                    if (! $acknowledged) {
                        $this->answerSafely($callbackId, 'Вход уже подтверждён. Вернитесь на сайт.');
                    }
                    $this->markMessageConfirmed((string) $chatId, (int) $messageId);

                    return;
                }

                if (! $acknowledged) {
                    $this->answerSafely($callbackId, 'Этот вход уже подтверждён или ссылка истекла.', true);
                }
                $this->markMessageFailed(
                    (string) $chatId,
                    (int) $messageId,
                    'Этот вход уже недоступен. Вернитесь на сайт и запустите вход ещё раз.',
                );

                return;
            }

            if (! $acknowledged) {
                $this->answerSafely($callbackId, 'Вход подтверждён. Вернитесь на сайт.');
            }
            $this->markMessageConfirmed((string) $chatId, (int) $messageId);
        } catch (InvalidArgumentException $exception) {
            if (! $acknowledged) {
                $this->answerSafely($callbackId, $exception->getMessage(), true);
            }
            $this->markMessageFailed((string) $chatId, (int) $messageId, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            if (! $acknowledged) {
                $this->answerSafely($callbackId, 'Не удалось подтвердить вход. Попробуйте ещё раз.', true);
            }
            $this->markMessageFailed(
                (string) $chatId,
                (int) $messageId,
                'Не удалось подтвердить вход. Вернитесь на сайт и запустите вход ещё раз.',
            );
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function answerSafely(
        string $callbackId,
        string $message,
        bool $alert = false,
        ?int $timeoutSeconds = null,
    ): bool {
        try {
            $this->telegram->call('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => mb_substr($message, 0, 200),
                'show_alert' => $alert,
                'cache_time' => 0,
            ], $timeoutSeconds);

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function markMessageConfirmed(string $chatId, int $messageId): void
    {
        $this->markMessage(
            $chatId,
            $messageId,
            'Вход в MSKBA подтверждён. Можно вернуться на сайт.',
        );
    }

    private function markMessageFailed(string $chatId, int $messageId, string $message): void
    {
        $this->markMessage($chatId, $messageId, $message);
    }

    private function markMessage(string $chatId, int $messageId, string $message): void
    {
        try {
            $this->telegram->call('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $message,
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
