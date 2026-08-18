<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum;
use App\Modules\Identity\Domain\Models\UserNotificationSetting;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Domain\Models\UserNotificationDelivery;
use App\Modules\Telegram\Application\Services\TelegramNotificationRecipientResolver;
use App\Modules\Telegram\Application\Services\TelegramUserNotificationMessageBuilder;
use App\Modules\Telegram\Infrastructure\Exceptions\TelegramBotApiException;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SendUserNotificationToTelegramJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $notificationId) {}

    public function handle(
        TelegramBotApiClient $client,
        TelegramUserNotificationMessageBuilder $builder,
        TelegramNotificationRecipientResolver $recipientResolver,
    ): void {
        $notification = UserNotification::query()->with('user')->find($this->notificationId);
        if ($notification === null || $notification->user === null) {
            return;
        }

        $canonicalUser = $notification->user->canonical();
        $delivery = UserNotificationDelivery::query()->firstOrCreate(
            ['user_notification_id' => $notification->id, 'channel' => 'telegram'],
            ['status' => 'pending', 'queued_at' => now()],
        );
        if ($delivery->status === 'sent') {
            return;
        }

        $category = UserNotificationDeliveryCategoryEnum::tryFrom(
            (string) data_get($notification->payload, 'delivery_category', UserNotificationDeliveryCategoryEnum::GENERAL->value),
        ) ?? UserNotificationDeliveryCategoryEnum::GENERAL;
        $setting = UserNotificationSetting::query()->where('user_id', $canonicalUser->id)->first();
        $preference = $setting?->messenger_notifications ?? UserMessengerNotificationPreferenceEnum::ALL;

        if (! $this->allows($preference, $category)) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Disabled by user preference.']);

            return;
        }

        $account = $recipientResolver->resolve($canonicalUser);
        if ($account === null) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Verified available Telegram private chat is missing.']);

            return;
        }

        $delivery->update([
            'status' => 'pending',
            'recipient' => (string) $account->private_chat_id,
            'attempts' => $delivery->attempts + 1,
            'last_error' => null,
            'failed_at' => null,
        ]);

        try {
            $response = $client->call('sendMessage', $builder->build($notification, $account->private_chat_id));
            $messageId = data_get($response, 'result.message_id');
            $delivery->update([
                'status' => 'sent',
                'external_message_id' => is_scalar($messageId) ? (string) $messageId : null,
                'sent_at' => now(),
            ]);
            $account->update([
                'private_chat_available_at' => now(),
                'private_chat_unavailable_at' => null,
                'last_delivery_error' => null,
            ]);
        } catch (TelegramBotApiException $exception) {
            $message = $exception->getMessage();
            $unavailable = str_contains($message, '403')
                || str_contains(strtolower($message), 'blocked')
                || str_contains(strtolower($message), 'chat not found');
            if ($unavailable) {
                $account->update([
                    'private_chat_unavailable_at' => now(),
                    'last_delivery_error' => $message,
                ]);
                $delivery->update(['status' => 'failed', 'last_error' => $message, 'failed_at' => now()]);

                return;
            }

            $delivery->update(['status' => 'failed', 'last_error' => $message, 'failed_at' => now()]);
            throw $exception;
        } catch (Throwable $exception) {
            $delivery->update(['status' => 'failed', 'last_error' => $exception->getMessage(), 'failed_at' => now()]);
            throw $exception;
        }
    }

    private function allows(
        UserMessengerNotificationPreferenceEnum $preference,
        UserNotificationDeliveryCategoryEnum $category,
    ): bool {
        return match ($preference) {
            UserMessengerNotificationPreferenceEnum::ALL => true,
            UserMessengerNotificationPreferenceEnum::SYSTEM_AND_REQUESTS => in_array($category, [
                UserNotificationDeliveryCategoryEnum::SYSTEM,
                UserNotificationDeliveryCategoryEnum::REQUEST,
            ], true),
            UserMessengerNotificationPreferenceEnum::SYSTEM_ONLY => $category === UserNotificationDeliveryCategoryEnum::SYSTEM,
            UserMessengerNotificationPreferenceEnum::REQUESTS_ONLY => $category === UserNotificationDeliveryCategoryEnum::REQUEST,
            UserMessengerNotificationPreferenceEnum::NONE => false,
        };
    }
}
