<?php

namespace App\Modules\Notification\Infrastructure\Jobs;

use App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum;
use App\Modules\Identity\Domain\Models\UserNotificationSetting;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Domain\Models\UserNotificationDelivery;
use App\Modules\Notification\Presentation\Mail\UserNotificationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendUserNotificationEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $notificationId) {}

    public function handle(): void
    {
        $notification = UserNotification::query()->with('user')->find($this->notificationId);
        if ($notification === null || $notification->user === null) {
            return;
        }

        $canonicalUser = $notification->user->canonical();
        $delivery = UserNotificationDelivery::query()->firstOrCreate(
            ['user_notification_id' => $notification->id, 'channel' => 'email'],
            ['status' => 'pending', 'queued_at' => now()],
        );
        if ($delivery->status === 'sent') {
            return;
        }

        $category = UserNotificationDeliveryCategoryEnum::tryFrom(
            (string) data_get($notification->payload, 'delivery_category', UserNotificationDeliveryCategoryEnum::GENERAL->value),
        ) ?? UserNotificationDeliveryCategoryEnum::GENERAL;
        $preference = UserNotificationSetting::query()->where('user_id', $canonicalUser->id)->first()?->email_notifications
            ?? UserMessengerNotificationPreferenceEnum::ALL;

        if (! $preference->allows($category)) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Disabled by user preference.']);

            return;
        }

        $email = $canonicalUser->primaryEmail();
        if ($email === null || ! $email->hasBeenVerified()) {
            $delivery->update(['status' => 'skipped', 'last_error' => 'Verified primary email is missing.']);

            return;
        }

        $delivery->update([
            'status' => 'pending',
            'recipient' => $email->value,
            'attempts' => $delivery->attempts + 1,
            'last_error' => null,
            'failed_at' => null,
        ]);

        try {
            Mail::to($email->value)->send(new UserNotificationMail($notification));
            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $exception) {
            $delivery->update(['status' => 'failed', 'last_error' => $exception->getMessage(), 'failed_at' => now()]);
            throw $exception;
        }
    }
}
