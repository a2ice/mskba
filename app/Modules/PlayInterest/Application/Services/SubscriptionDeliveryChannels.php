<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserNotificationSetting;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Telegram\Application\Services\TelegramNotificationRecipientResolver;
use Illuminate\Validation\ValidationException;

final class SubscriptionDeliveryChannels
{
    public function __construct(private readonly TelegramNotificationRecipientResolver $telegram) {}

    /** @return array<string, array{available:bool, reason:?string}> */
    public function availability(User $user): array
    {
        $canonical = $user->canonical();
        $settings = UserNotificationSetting::query()->where('user_id', $canonical->id)->first();
        $category = UserNotificationDeliveryCategoryEnum::GENERAL;
        $telegramAllowed = ($settings?->messenger_notifications ?? UserMessengerNotificationPreferenceEnum::ALL)->allows($category);
        $emailAllowed = ($settings?->email_notifications ?? UserMessengerNotificationPreferenceEnum::ALL)->allows($category);
        $telegramLinked = $this->telegram->resolve($canonical) !== null;
        $verifiedEmail = $canonical->primaryEmail()?->hasBeenVerified() === true;
        return [
            'in_app' => ['available' => true, 'reason' => null],
            'telegram' => [
                'available' => $telegramAllowed && $telegramLinked,
                'reason' => ! $telegramAllowed ? 'Уведомления запрещены в настройках аккаунта.'
                    : (! $telegramLinked ? 'Добавь и подтверди Telegram через бота, затем открой приватный чат с ботом.' : null),
            ],
            'email' => [
                'available' => $emailAllowed && $verifiedEmail,
                'reason' => ! $emailAllowed ? 'Email-уведомления запрещены в настройках аккаунта.'
                    : (! $verifiedEmail ? 'Добавь и подтверди основной адрес электронной почты.' : null),
            ],
            'phone' => [
                'available' => false,
                'reason' => 'SMS-уведомления пока не подключены. Можно добавить телефон в контакты.',
            ],
        ];
    }

    /** @param array<mixed> $channels
     * @return list<string>
     */
    public function validate(User $user, array $channels): array
    {
        $allowed = $this->availability($user);
        $result = array_values(array_unique(array_map('strval', $channels)));
        if ($result === []) {
            throw ValidationException::withMessages(['delivery_channels' => 'Выбери хотя бы один доступный канал уведомлений.']);
        }
        foreach ($result as $channel) {
            if (! isset($allowed[$channel]) || ! $allowed[$channel]['available']) {
                throw ValidationException::withMessages([
                    'delivery_channels' => $allowed[$channel]['reason'] ?? 'Канал недоступен.',
                ]);
            }
        }
        return $result;
    }
}
