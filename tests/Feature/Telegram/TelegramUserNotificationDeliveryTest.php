<?php

namespace Tests\Feature\Telegram;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserNotificationSetting;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Telegram\Application\Services\TelegramMiniAppStartDestinationResolver;
use App\Modules\Telegram\Application\Services\TelegramNotificationRecipientResolver;
use App\Modules\Telegram\Application\Services\TelegramUserNotificationMessageBuilder;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Jobs\SendUserNotificationToTelegramJob;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelegramUserNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_notification_is_sent_to_verified_available_private_chat(): void
    {
        config([
            'telegram.bot_token' => 'test-token',
            'telegram.bot_username' => 'MSKBATestBot',
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 321]])]);
        [$user, $account] = $this->telegramUser(UserMessengerNotificationPreferenceEnum::ALL);
        $notification = $this->notification($user, UserNotificationDeliveryCategoryEnum::REQUEST);

        $this->deliver($notification);

        $this->assertDatabaseHas('user_notification_deliveries', [
            'user_notification_id' => $notification->id,
            'channel' => 'telegram',
            'status' => 'sent',
            'recipient' => (string) $account->private_chat_id,
            'external_message_id' => '321',
        ]);
        Http::assertSent(fn ($request): bool => $request['chat_id'] === $account->private_chat_id
            && str_contains((string) $request['text'], 'Новая заявка')
            && $request['reply_markup']['inline_keyboard'][0][0]['url']
                === 'https://t.me/MSKBATestBot?startapp=notification_'.$notification->id);
    }

    public function test_canonical_notification_uses_verified_telegram_account_kept_on_alias(): void
    {
        config([
            'telegram.bot_token' => 'test-token',
            'telegram.bot_username' => 'MSKBATestBot',
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 654]])]);

        $canonical = User::factory()->create();
        $alias = User::factory()->create();
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();
        UserNotificationSetting::query()->create([
            'user_id' => $canonical->id,
            'messenger_notifications' => UserMessengerNotificationPreferenceEnum::ALL,
        ]);
        Contact::query()->create([
            'contactable_type' => 'user',
            'contactable_id' => $alias->id,
            'type' => ContactTypeEnum::TELEGRAM,
            'value' => '200500',
            'verified_at' => now(),
        ]);
        $account = TelegramAccount::query()->create([
            'user_id' => $alias->id,
            'telegram_user_id' => 200500,
            'private_chat_id' => 200500,
            'private_chat_started_at' => now(),
            'private_chat_available_at' => now(),
        ]);
        $notification = $this->notification($canonical, UserNotificationDeliveryCategoryEnum::REQUEST);

        $this->deliver($notification);

        $this->assertDatabaseHas('user_notification_deliveries', [
            'user_notification_id' => $notification->id,
            'status' => 'sent',
            'recipient' => (string) $account->private_chat_id,
        ]);
        Http::assertSent(fn ($request): bool => $request['chat_id'] === $account->private_chat_id);
    }

    public function test_notification_start_destination_is_available_only_to_its_identity_owner(): void
    {
        $canonical = User::factory()->create();
        $alias = User::factory()->create();
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();
        $otherUser = User::factory()->create();
        $notification = $this->notification($alias, UserNotificationDeliveryCategoryEnum::REQUEST);
        $resolver = app(TelegramMiniAppStartDestinationResolver::class);

        $this->assertSame(
            '/teams/1/join-requests',
            $resolver->resolve('notification_'.$notification->id, $canonical->id),
        );
        $this->assertNull($resolver->resolve('notification_'.$notification->id, $otherUser->id));
    }

    public function test_request_notification_is_skipped_for_system_only_preference(): void
    {
        config(['telegram.bot_token' => 'test-token']);
        Http::fake();
        [$user] = $this->telegramUser(UserMessengerNotificationPreferenceEnum::SYSTEM_ONLY);
        $notification = $this->notification($user, UserNotificationDeliveryCategoryEnum::REQUEST);

        $this->deliver($notification);

        $this->assertDatabaseHas('user_notification_deliveries', [
            'user_notification_id' => $notification->id,
            'status' => 'skipped',
        ]);
        Http::assertNothingSent();
    }

    /** @return array{User, TelegramAccount} */
    private function telegramUser(UserMessengerNotificationPreferenceEnum $preference): array
    {
        $user = User::factory()->create();
        UserNotificationSetting::query()->create([
            'user_id' => $user->id,
            'messenger_notifications' => $preference,
        ]);
        Contact::query()->create([
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => ContactTypeEnum::TELEGRAM,
            'value' => '100500',
            'verified_at' => now(),
        ]);
        $account = TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 100500,
            'private_chat_id' => 100500,
            'private_chat_started_at' => now(),
            'private_chat_available_at' => now(),
        ]);

        return [$user, $account];
    }

    private function notification(User $user, UserNotificationDeliveryCategoryEnum $category): UserNotification
    {
        return UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::SYSTEM,
            'status' => UserNotificationStatusEnum::NEW,
            'title' => 'Новая заявка',
            'body' => 'Пользователь хочет вступить в команду.',
            'action_url' => '/teams/1/join-requests',
            'action_text' => 'Просмотреть заявку',
            'payload' => ['delivery_category' => $category->value],
        ]);
    }

    private function deliver(UserNotification $notification): void
    {
        (new SendUserNotificationToTelegramJob($notification->id))->handle(
            app(TelegramBotApiClient::class),
            app(TelegramUserNotificationMessageBuilder::class),
            app(TelegramNotificationRecipientResolver::class),
        );
    }
}
