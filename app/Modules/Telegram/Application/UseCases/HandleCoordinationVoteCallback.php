<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Coordination\Application\UseCases\VoteInPollHandler;
use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Models\PollBallot;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Telegram\Application\DTO\TelegramUserIdentityDTO;
use App\Modules\Telegram\Domain\Models\TelegramCoordinationPublication;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use InvalidArgumentException;
use Throwable;

final class HandleCoordinationVoteCallback
{
    public function __construct(
        private readonly VoteInPollHandler $vote,
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
            || preg_match('/^coord:(\d+):vote:(\d+)$/', $data, $matches) !== 1) {
            return;
        }

        $pollId = (int) $matches[1];
        $optionId = (int) $matches[2];
        $publication = TelegramCoordinationPublication::query()
            ->where('poll_id', $pollId)
            ->where('message_id', (int) $messageId)
            ->whereHas('chat', fn ($query) => $query->where('telegram_chat_id', (int) $chatId))
            ->first();

        if ($publication === null) {
            $this->answer($callbackId, 'Это сообщение больше не связано с опросом.', true);

            return;
        }

        if ($publication->poll?->selection_mode !== PollSelectionModeEnum::SINGLE) {
            $this->answer($callbackId, 'Выберите варианты в Mini App.', true);

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
            $sourceUser = $resolved['user'];
            $user = $sourceUser->canonical();

            if ($sourceUser->isBlocked() || $user->isBlocked()) {
                throw new InvalidArgumentException('Ваш аккаунт заблокирован.');
            }

            $existingOptionIds = PollBallot::query()
                ->where('poll_id', $pollId)
                ->whereIn('user_id', $user->identityIds())
                ->with('selections')
                ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])
                ->orderBy('id')
                ->first()
                ?->selections
                ->pluck('option_id')
                ->map(fn ($id): int => (int) $id)
                ->all() ?? [];

            if ($existingOptionIds === [$optionId]) {
                $this->answer($callbackId, 'Этот вариант уже выбран.');

                return;
            }

            $this->vote->handle($pollId, $user, [$optionId]);
            $message = 'Ваш голос сохранён.';

            if ($resolved['created']) {
                $message .= ' Аккаунт MSKBA создан.';
            }

            $this->answer($callbackId, $message);
        } catch (InvalidArgumentException $exception) {
            $this->answer($callbackId, $exception->getMessage(), true);
        } catch (Throwable $exception) {
            report($exception);
            $this->answer($callbackId, 'Не удалось сохранить голос. Попробуйте ещё раз.', true);
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
