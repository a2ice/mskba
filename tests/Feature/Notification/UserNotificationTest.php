<?php

namespace Tests\Feature\Notification;

use App\Modules\Contact\Application\DTO\ConfirmContactVerificationDTO;
use App\Modules\Contact\Application\UseCases\ConfirmContactVerificationHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Broadcasting\UserNotificationChannel;
use App\Modules\Notification\Infrastructure\Broadcasting\UserNotificationCreatedBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_login_creates_welcome_notification(): void
    {
        $user = User::factory()->create([
            'username' => 'notification_user',
            'password' => 'Password1!',
            'registration_channel' => UserRegistrationChannelEnum::SITE_FULL_REGISTRATION,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        $this->post(route('auth.login'), [
            'login' => 'notification_user',
            'password' => 'Password1!',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::SYSTEM->value,
            'status' => UserNotificationStatusEnum::NEW->value,
            'title' => 'Добро пожаловать в MSKBA',
        ]);

        $notification = UserNotification::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            UserNotificationSourceEnum::IDENTITY_REGISTRATION->value,
            $notification->payload['source'] ?? null,
        );
        $this->assertSame('Подробнее о первых шагах', $notification->action_text);

        $this->assertNotNull($user->refresh()->first_logged_in_at);
    }

    public function test_welcome_notification_is_created_only_on_first_login(): void
    {
        $user = User::factory()->create([
            'username' => 'second_login_notification_user',
            'password' => 'Password1!',
            'registration_channel' => UserRegistrationChannelEnum::SITE_FULL_REGISTRATION,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        $this->post(route('auth.login'), [
            'login' => 'second_login_notification_user',
            'password' => 'Password1!',
        ]);

        $this->post(route('auth.logout'));

        $this->post(route('auth.login'), [
            'login' => 'second_login_notification_user',
            'password' => 'Password1!',
        ]);

        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $user->id)
            ->where('title', 'Добро пожаловать в MSKBA')
            ->count());
    }

    public function test_confirming_user_contact_creates_notification(): void
    {
        $user = User::factory()->create([
            'username' => 'contact_notification_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        $contact = Contact::query()->create([
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'user@example.com',
            'is_primary' => true,
            'is_public' => false,
        ]);

        $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => Hash::make('123456'),
            'sent_to' => 'user@example.com',
            'attempts_count' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10),
        ]);

        app(ConfirmContactVerificationHandler::class)->handle(
            $contact,
            new ConfirmContactVerificationDTO('123456'),
        );

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::PROFILE->value,
            'status' => UserNotificationStatusEnum::NEW->value,
            'title' => 'Контакт подтвержден',
        ]);

        $notification = UserNotification::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            UserNotificationSourceEnum::CONTACT_CONFIRMATION->value,
            $notification->payload['source'] ?? null,
        );
    }

    public function test_user_can_mark_own_notification_as_read(): void
    {
        $user = User::factory()->create([
            'username' => 'read_notification_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $notification = UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::SYSTEM,
            'status' => UserNotificationStatusEnum::NEW,
            'title' => 'Тестовое уведомление',
            'body' => 'Текст уведомления.',
        ]);

        $response = $this->actingAs($user)
            ->patch(route('account.notifications.read', $notification));

        $response->assertRedirect(route('account.notifications'));

        $this->assertDatabaseHas('user_notifications', [
            'id' => $notification->id,
            'status' => UserNotificationStatusEnum::READ->value,
        ]);

        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_json_read_response_contains_synchronized_unread_count(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $notification = UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::SYSTEM,
            'status' => UserNotificationStatusEnum::NEW,
            'title' => 'Realtime',
            'body' => 'Проверка счётчика.',
        ]);

        $this->actingAs($user)
            ->patchJson(route('account.notifications.read', $notification))
            ->assertOk()
            ->assertJsonPath('notification_id', $notification->id)
            ->assertJsonPath('unread_count', 0);
    }

    public function test_user_can_synchronize_latest_new_notifications_for_a_new_tab(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::SYSTEM,
            'status' => UserNotificationStatusEnum::NEW,
            'title' => 'Непрочитанное',
            'body' => 'Должно появиться в новой вкладке.',
        ]);
        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::SYSTEM,
            'status' => UserNotificationStatusEnum::READ,
            'title' => 'Прочитанное',
            'body' => 'Не должно появиться.',
            'read_at' => now(),
        ]);
        UserNotification::query()->create([
            'user_id' => $other->id,
            'type' => UserNotificationTypeEnum::SYSTEM,
            'status' => UserNotificationStatusEnum::NEW,
            'title' => 'Чужое',
            'body' => 'Не должно быть доступно.',
        ]);

        $this->actingAs($user)
            ->getJson(route('account.notifications.new'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.title', 'Непрочитанное');
    }

    public function test_created_notification_is_broadcast_to_its_private_user_channel(): void
    {
        Event::fake([UserNotificationCreatedBroadcast::class]);
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        app(CreateUserNotificationHandler::class)->handle(new CreateUserNotificationDTO(
            userId: $user->id,
            type: UserNotificationTypeEnum::SYSTEM,
            title: 'Новое уведомление',
            body: 'Доставлено через realtime.',
        ));

        Event::assertDispatched(UserNotificationCreatedBroadcast::class, function ($event) use ($user): bool {
            return $event->userId === $user->id
                && $event->unreadCount === 1
                && $event->notification['title'] === 'Новое уведомление';
        });
    }

    public function test_user_can_authorize_only_own_private_notification_channel(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $channel = app(UserNotificationChannel::class);

        $this->assertTrue($channel->join($user, $user->id));
        $this->assertFalse($channel->join($user, $other->id));
    }

    public function test_user_can_view_notifications_page(): void
    {
        $user = User::factory()->create([
            'username' => 'view_notification_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::SYSTEM,
            'status' => UserNotificationStatusEnum::NEW,
            'title' => 'Уведомление для просмотра',
            'body' => 'Текст уведомления для страницы.',
        ]);

        $response = $this->actingAs($user)
            ->get(route('account.notifications'));

        $response->assertOk();
        $response->assertSee('Центр уведомлений');
        $response->assertSee('Уведомление для просмотра');
        $response->assertSee('ti-bell-ringing', false);
        $response->assertSee('ti-settings', false);
        $response->assertSee('Отметить все прочитанными');
        $response->assertSee('>1</span>', false);
    }

    public function test_user_can_mark_all_new_notifications_as_read(): void
    {
        $user = User::factory()->create([
            'username' => 'read_all_notification_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::SYSTEM,
            'status' => UserNotificationStatusEnum::NEW,
            'title' => 'Первое уведомление',
            'body' => 'Текст первого уведомления.',
        ]);

        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::PROFILE,
            'status' => UserNotificationStatusEnum::NEW,
            'title' => 'Второе уведомление',
            'body' => 'Текст второго уведомления.',
        ]);

        UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::SECURITY,
            'status' => UserNotificationStatusEnum::READ,
            'title' => 'Прочитанное уведомление',
            'body' => 'Текст прочитанного уведомления.',
            'read_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)
            ->patch(route('account.notifications.read-all'));

        $response->assertRedirect(route('account.notifications'));

        $this->assertSame(0, UserNotification::query()
            ->where('user_id', $user->id)
            ->where('status', UserNotificationStatusEnum::NEW)
            ->count());

        $this->assertSame(3, UserNotification::query()
            ->where('user_id', $user->id)
            ->where('status', UserNotificationStatusEnum::READ)
            ->count());
    }

    public function test_header_notification_badge_uses_ellipsis_for_more_than_nine_notifications(): void
    {
        $user = User::factory()->create([
            'username' => 'header_badge_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        for ($i = 1; $i <= 10; $i++) {
            UserNotification::query()->create([
                'user_id' => $user->id,
                'type' => UserNotificationTypeEnum::SYSTEM,
                'status' => UserNotificationStatusEnum::NEW,
                'title' => "Уведомление $i",
                'body' => "Текст уведомления $i.",
            ]);
        }

        $response = $this->actingAs($user)
            ->get(route('account.notifications'));

        $response->assertOk();
        $response->assertSee('site-auth__notification-badge', false);
        $response->assertSee('Новые уведомления: 10');
        $response->assertSee('...', false);
    }
}
