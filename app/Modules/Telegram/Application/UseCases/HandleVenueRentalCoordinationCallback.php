<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Coordination\Application\UseCases\JoinVenueRentalCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\LeaveVenueRentalCoordinationHandler;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Telegram\Application\DTO\TelegramUserIdentityDTO;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Domain\Models\TelegramVenueRentalPublication;
use App\Modules\Telegram\Domain\Models\TelegramVenueRentalUpdate;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

final readonly class HandleVenueRentalCoordinationCallback
{
    public function __construct(
        private JoinVenueRentalCoordinationHandler $join,
        private LeaveVenueRentalCoordinationHandler $leave,
        private ResolveTelegramUserHandler $resolveTelegramUser,
        private TelegramBotApiClient $telegram,
        private FeatureFlags $features,
    ) {}

    /** @param array<string, mixed> $callback */
    public function handle(array $callback, ?int $updateId = null): void
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
            || preg_match('/^rentalcoord:(\d+):(join|leave)$/', $data, $matches) !== 1) {
            return;
        }

        $coordinationId = (int) $matches[1];
        $action = $matches[2];
        Cache::lock('telegram:venue-rental-callback:'.hash('sha256', $callbackId), 30)
            ->block(5, function () use ($callbackId, $telegramUserId, $telegramUser, $chatId, $messageId, $coordinationId, $action, $updateId): void {
                $this->process(
                    $callbackId,
                    (int) $telegramUserId,
                    $telegramUser,
                    (string) $chatId,
                    (int) $messageId,
                    $coordinationId,
                    $action,
                    $updateId,
                );
            });
    }

    /** @param array<string, mixed> $telegramUser
     */
    private function process(
        string $callbackId,
        int $telegramUserId,
        array $telegramUser,
        string $chatId,
        int $messageId,
        int $coordinationId,
        string $action,
        ?int $updateId,
    ): void {
        $receipt = TelegramVenueRentalUpdate::query()->where('callback_id', $callbackId)->first();
        if ($receipt?->status === 'completed') {
            $this->answer($callbackId, $this->currentState($coordinationId, $telegramUserId));

            return;
        }
        $receipt ??= TelegramVenueRentalUpdate::query()->create([
            'update_id' => $updateId,
            'callback_id' => $callbackId,
            'coordination_id' => $coordinationId,
            'telegram_user_id' => $telegramUserId,
            'action' => $action,
            'status' => 'processing',
        ]);
        $receipt->forceFill([
            'status' => 'processing',
            'attempts' => $receipt->attempts + 1,
            'last_error' => null,
        ])->save();

        $publication = TelegramVenueRentalPublication::query()
            ->where('coordination_id', $coordinationId)
            ->whereHas('chat', fn ($query) => $query->where('telegram_chat_id', $chatId))
            ->where('message_id', $messageId)
            ->first();
        if ($publication === null) {
            $this->complete($receipt);
            $this->answer($callbackId, 'Это сообщение больше не связано со сбором.', true);

            return;
        }
        if (! $this->features->enabled(VenueRentalFeature::COORDINATION)) {
            $this->complete($receipt, 'feature_disabled');
            $this->answer($callbackId, 'Сбор участников сейчас недоступен.', true);

            return;
        }

        try {
            $resolved = $this->resolveTelegramUser->handle(new TelegramUserIdentityDTO(
                id: $telegramUserId,
                username: $this->nullableString(data_get($telegramUser, 'username')),
                firstName: $this->nullableString(data_get($telegramUser, 'first_name')),
                lastName: $this->nullableString(data_get($telegramUser, 'last_name')),
                languageCode: $this->nullableString(data_get($telegramUser, 'language_code')),
                photoUrl: null,
                rawData: ['user' => $telegramUser],
                source: 'telegram_venue_rental_callback',
                registrationChannel: UserRegistrationChannelEnum::TELEGRAM_CHAT,
            ));
            $user = $resolved['user']->canonical();
            if ($action === 'join') {
                $this->join->handle($coordinationId, $user);
                $message = 'Вы присоединились к сбору. Время ещё не забронировано.';
            } else {
                $this->leave->handle($coordinationId, $user);
                $message = 'Вы покинули сбор.';
            }
            $this->complete($receipt);
            $this->answer($callbackId, $message);
        } catch (InvalidArgumentException $exception) {
            $this->complete($receipt, $exception->getMessage());
            $this->answer($callbackId, $exception->getMessage().' '.$this->currentState($coordinationId, $telegramUserId), true);
        } catch (Throwable $exception) {
            $receipt->forceFill([
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                'completed_at' => null,
            ])->save();
            report($exception);
            throw $exception;
        }
    }

    private function currentState(int $coordinationId, int $telegramUserId): string
    {
        $coordination = VenueRentalCoordination::query()->find($coordinationId);
        if ($coordination === null) {
            return 'Сбор больше недоступен.';
        }
        $userId = $this->telegramUserId($telegramUserId);
        $joined = $userId !== null && $coordination->participants()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();

        return 'Актуально: '.$coordination->status->label().'; '.($joined ? 'вы участвуете.' : 'вы не участвуете.');
    }

    private function telegramUserId(int $telegramUserId): ?int
    {
        return TelegramAccount::query()
            ->where('telegram_user_id', $telegramUserId)
            ->first()?->user?->canonical()->id;
    }

    private function complete(TelegramVenueRentalUpdate $receipt, ?string $note = null): void
    {
        $receipt->forceFill([
            'status' => 'completed',
            'last_error' => $note,
            'completed_at' => now(),
        ])->save();
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
