<?php

namespace Tests\Feature\Notification;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserNotificationSetting;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Jobs\SendUserNotificationEmailJob;
use App\Modules\Notification\Presentation\Mail\UserNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class UserNotificationEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_notification_is_sent_to_verified_primary_email(): void
    {
        Mail::fake();
        [$user, $email] = $this->userWithEmail(UserMessengerNotificationPreferenceEnum::ALL);
        $notification = $this->notification($user);

        (new SendUserNotificationEmailJob($notification->id))->handle();

        Mail::assertSent(UserNotificationMail::class, fn (UserNotificationMail $mail): bool => $mail->hasTo($email));
        $this->assertDatabaseHas('user_notification_deliveries', [
            'user_notification_id' => $notification->id,
            'channel' => 'email',
            'status' => 'sent',
            'recipient' => $email,
        ]);
    }

    public function test_request_notification_respects_email_preference(): void
    {
        Mail::fake();
        [$user] = $this->userWithEmail(UserMessengerNotificationPreferenceEnum::SYSTEM_ONLY);
        $notification = $this->notification($user);

        (new SendUserNotificationEmailJob($notification->id))->handle();

        Mail::assertNothingSent();
        $this->assertDatabaseHas('user_notification_deliveries', [
            'user_notification_id' => $notification->id,
            'channel' => 'email',
            'status' => 'skipped',
        ]);
    }

    /** @return array{User, string} */
    private function userWithEmail(UserMessengerNotificationPreferenceEnum $preference): array
    {
        $user = User::factory()->create();
        $email = 'venue-owner-'.$user->id.'@example.test';
        UserNotificationSetting::query()->create([
            'user_id' => $user->id,
            'messenger_notifications' => UserMessengerNotificationPreferenceEnum::NONE,
            'email_notifications' => $preference,
        ]);
        Contact::query()->create([
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => ContactTypeEnum::EMAIL,
            'value' => $email,
            'is_primary' => true,
            'verified_at' => now(),
        ]);

        return [$user, $email];
    }

    private function notification(User $user): UserNotification
    {
        return UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::SYSTEM,
            'status' => UserNotificationStatusEnum::NEW,
            'title' => 'Новая заявка на аренду',
            'body' => 'Получена новая заявка.',
            'action_url' => '/account/venue-bookings/example',
            'action_text' => 'Открыть заявку',
            'payload' => ['delivery_category' => UserNotificationDeliveryCategoryEnum::REQUEST->value],
        ]);
    }
}
