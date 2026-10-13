<?php

namespace Tests\Feature\Notification;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class AccountMessageThreadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->createProfile([]);

        return $user;
    }

    private function notification(User $user, string $title, UserNotificationStatusEnum $status = UserNotificationStatusEnum::NEW): UserNotification
    {
        return UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotificationTypeEnum::SYSTEM,
            'status' => $status,
            'title' => $title,
            'body' => 'Текст: '.$title,
            'read_at' => $status === UserNotificationStatusEnum::READ ? now() : null,
        ]);
    }

    public function test_empty_inbox_has_a_simple_empty_state(): void
    {
        $this->actingAs($this->user())->get(route('account.notifications'))->assertOk()
            ->assertSee('Сообщения')
            ->assertSee('Сообщений пока нет')
            ->assertDontSee('data-conversation="system"', false);
    }

    public function test_group_previews_latest_unread_and_falls_back_to_latest_read(): void
    {
        $user = $this->user();
        $this->notification($user, 'Старое непрочитанное');
        $this->notification($user, 'Последнее непрочитанное');
        $this->notification($user, 'Новое прочитанное', UserNotificationStatusEnum::READ);

        $this->actingAs($user)->get(route('account.notifications'))->assertOk()
            ->assertSee('Система MSKBA')
            ->assertSee('data-conversation="system"', false)
            ->assertSee('Последнее непрочитанное')
            ->assertDontSee('Старое непрочитанное')
            ->assertDontSee('Новое прочитанное')
            ->assertSee('Непрочитанных сообщений: 2')
            ->assertSee(route('account.notifications.threads.system'), false);

        $this->patchJson(route('account.notifications.read-all'))->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->get(route('account.notifications'))->assertOk()
            ->assertSee('Новое прочитанное')
            ->assertDontSee('Непрочитанных сообщений:');
    }

    public function test_system_thread_is_private_and_displays_chronological_chat_history(): void
    {
        $user = $this->user();
        $stranger = $this->user();
        $this->notification($user, 'Первое сообщение');
        $this->notification($user, 'Второе сообщение');
        $this->notification($stranger, 'Секрет другого пользователя');

        $response = $this->actingAs($user)->get(route('account.notifications.threads.system'))
            ->assertOk()
            ->assertSee('Система MSKBA')
            ->assertSee('aria-label="Все сообщения"', false)
            ->assertDontSee('Notifications')
            ->assertSee('role="log"', false)
            ->assertSee('data-chat-read-url', false)
            ->assertDontSee('Секрет другого пользователя');
        $response->assertSeeTextInOrder(['Первое сообщение', 'Второе сообщение']);

        $this->assertSame(2, substr_count($response->getContent(), 'data-chat-entry='));
        $this->patchJson(route('account.notifications.read-all'))->assertOk()
            ->assertJsonPath('unread_count', 0);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $stranger->id,
            'status' => UserNotificationStatusEnum::NEW->value,
        ]);
        $this->get(route('account.notifications'))->assertOk()
            ->assertDontSee('Непрочитанных сообщений:');
    }

    public function test_system_thread_has_private_keyset_pagination_by_tens(): void
    {
        $user = $this->user();
        for ($i = 1; $i <= 24; $i++) {
            $notification = $this->notification($user, 'Сообщение номер '.$i);
            $notification->forceFill(['created_at' => now()->addSeconds($i)])->save();
        }

        $firstPage = $this->actingAs($user)
            ->get(route('account.notifications.threads.system'))->assertOk()
            ->assertSee('Сообщение номер 24')
            ->assertDontSee('>Сообщение номер 1</strong>', false)
            ->assertSee('data-chat-history', false);
        $this->assertSame(10, substr_count($firstPage->getContent(), 'data-chat-entry='));
        $firstPage->assertSeeTextInOrder(['Сообщение номер 15', 'Сообщение номер 24']);
        $history = route('account.notifications.threads.system.history');
        $latest = $this->getJson($history)->assertOk();
        $this->assertSame(10, substr_count($latest->json('html'), 'data-chat-entry='));
        $older = $this->getJson($history.'?before='.$latest->json('next_before'))->assertOk();
        $this->assertSame(10, substr_count($older->json('html'), 'data-chat-entry='));
        $this->assertStringContainsString('Сообщение номер 5', $older->json('html'));
        $this->assertStringNotContainsString('Сообщение номер 24', $older->json('html'));
    }

    public function test_guest_does_not_get_access_to_messages(): void
    {
        $this->get(route('account.notifications'))->assertRedirect(route('login'));
        $this->get(route('account.notifications.threads.system'))->assertRedirect(route('login'));
        $this->patchJson(route('account.notifications.read-all'))->assertUnauthorized();
    }
}
