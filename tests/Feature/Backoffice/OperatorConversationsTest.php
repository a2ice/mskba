<?php

namespace Tests\Feature\Backoffice;

use App\Livewire\Backoffice\OperatorLiveChat;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Application\Services\OperatorActivityService;
use App\Modules\Portal\Infrastructure\Broadcasting\OperatorPresenceChannel;
use App\Modules\Notification\Application\Services\OperatorConversationMessaging;
use App\Modules\Notification\Domain\Models\OperatorConversation;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChannel;
use App\Presentation\Theming\ThemeResolver;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\TestCase;

final class OperatorConversationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    private function user(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
        $user->createProfile([]);

        return $user;
    }

    private function grant(User $super, User $agent): void
    {
        app(GlobalOperationalPermissionManager::class)->sync($super, $agent, [], [
            Permission::OPERATOR_CONVERSATION_HANDLE->value,
        ]);
        app(BackofficePermissionManager::class)->sync($super, $agent, [
            Permission::BACKOFFICE_ACCESS->value,
        ]);
        RateLimiter::clear('operator-messages:'.$agent->id);
    }

    public function test_authorized_operator_can_contact_offline_user_and_reuse_conversation(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $operator = $this->user(UserSystemRoleEnum::EDITOR);
        $customer = $this->user();
        $this->grant($super, $operator);

        $conversation = app(OperatorConversationMessaging::class)
            ->start($operator, $customer, 'Здравствуйте, можем помочь?');
        $this->assertSame('service', $conversation->purpose);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $customer->id,
            'title' => 'Новое сообщение оператора MSKBA',
            'body' => 'Здравствуйте, можем помочь?',
        ]);
        $next = app(OperatorConversationMessaging::class)
            ->start($operator, $customer, 'Второе сообщение');
        $this->assertSame($conversation->id, $next->id);
        $this->assertSame(1, OperatorConversation::query()->count());
        $this->assertSame(2, $conversation->messages()->count());

        $this->actingAs($customer)->get(route('account.notifications.threads.operator', $conversation))
            ->assertOk()->assertSee('Здравствуйте, можем помочь?');
        $this->get('/account/notifications')
            ->assertOk()->assertSee('Сообщение оператора MSKBA');
        $this->getJson(route('account.notifications.popover', ['kind' => 'operator', 'id' => $conversation->id]))
            ->assertOk()->assertJson(['kind' => 'operator', 'can_send' => true]);
        $this->postJson(route('account.notifications.threads.operator.messages', $conversation), [
            'body' => 'Спасибо за помощь!',
        ])->assertCreated();
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $operator->id,
            'body' => 'Спасибо за помощь!',
        ]);

        $this->patchJson(route('account.notifications.threads.operator.read', $conversation))
            ->assertOk()->assertJsonStructure(['unread_count']);
    }

    public function test_operator_chat_shows_current_page_live_only_with_presence_permission(): void
    {
        config()->set('operator_activity.cache_store', 'array');
        Cache::store('array')->clear();

        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $operator = $this->user(UserSystemRoleEnum::EDITOR);
        $customer = $this->user();
        $this->grant($super, $operator);
        $conversation = app(OperatorConversationMessaging::class)
            ->start($operator, $customer, 'Проверка активности');

        $activity = app(OperatorActivityService::class);
        $activity->record($customer, 'current-session-test', '/account/notifications/threads/operator/'.$conversation->id);

        $this->actingAs($operator);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        Livewire::test(OperatorLiveChat::class, ['conversationId' => $conversation->id])
            ->assertSee('Проверка активности')
            ->assertDontSee('Сейчас на странице:')
            ->assertDontSee('/account/notifications/threads/operator/'.$conversation->id);
        $this->assertFalse(app(OperatorPresenceChannel::class)->join($operator, $customer->id));

        // Granting navigation visibility does not change the separate right to chat.
        app(GlobalOperationalPermissionManager::class)->sync($super, $operator, [], [
            Permission::OPERATOR_CONVERSATION_HANDLE->value,
            Permission::OPERATOR_PRESENCE_VIEW->value,
        ]);

        $this->assertTrue(app(OperatorPresenceChannel::class)->join($operator, $customer->id));
        $this->assertFalse(app(OperatorPresenceChannel::class)->join($super, $customer->id));

        $chat = Livewire::test(OperatorLiveChat::class, ['conversationId' => $conversation->id])
            ->assertSee('Онлайн')
            ->assertSee('Сейчас на странице:')
            ->assertSee('/account/notifications/threads/operator/'.$conversation->id);

        // Simulates Livewire's existing periodic refresh, without messages
        // being sent from either side.
        $activity->record($customer, 'current-session-test', '/faq');
        $chat->call('refreshConversation')
            ->assertSee('Сейчас на странице:')
            ->assertSee('/faq')
            ->assertDontSee('/account/notifications/threads/operator/'.$conversation->id);

        $activity->forget($customer);
        $chat->call('refreshConversation')
            ->assertSee('Офлайн')
            ->assertSee('Данные об активности пока отсутствуют.');

        // Revoking only presence access instantly hides the location on the
        // next authorized refresh, but keeps the operator's chat accessible.
        app(GlobalOperationalPermissionManager::class)->sync($super, $operator, [], [
            Permission::OPERATOR_CONVERSATION_HANDLE->value,
        ]);
        $chat->call('refreshConversation')
            ->assertDontSee('Данные об активности пока отсутствуют.')
            ->assertSee('Проверка активности');
        $this->assertFalse(app(OperatorPresenceChannel::class)->join($operator, $customer->id));
    }

    public function test_stranger_and_operator_without_permission_cannot_read_or_write(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $operator = $this->user(UserSystemRoleEnum::EDITOR);
        $customer = $this->user();
        $stranger = $this->user();
        $this->grant($super, $operator);
        $conversation = app(OperatorConversationMessaging::class)
            ->start($operator, $customer, 'Закрытая переписка');
        $access = app(ConversationChannel::class);

        $this->assertFalse($access->operator($stranger, $conversation->id));
        $this->assertTrue($access->operator($customer, $conversation->id));
        $this->assertTrue($access->operator($operator, $conversation->id));

        $this->actingAs($stranger)
            ->get(route('account.notifications.threads.operator.history', $conversation))->assertForbidden();
        $this->postJson(route('account.notifications.threads.operator.messages', $conversation), [
            'body' => 'Нельзя отправить',
        ])->assertForbidden();
        $this->getJson(route('account.notifications.popover', [
            'kind' => 'operator', 'id' => $conversation->id,
        ]))->assertForbidden();
        $this->assertDatabaseMissing('operator_messages', ['body' => 'Нельзя отправить']);

        app(GlobalOperationalPermissionManager::class)->sync($super, $operator, [], []);
        $this->assertFalse($access->operator($operator, $conversation->id));
        $this->actingAs($operator)->get(route('account.notifications.threads.operator', $conversation))
            ->assertForbidden();
        $this->postJson(route('account.notifications.threads.operator.messages', $conversation), [
            'body' => 'Нельзя после отзыва',
        ])->assertForbidden();
    }

    public function test_filament_chat_receives_customer_reply_and_denies_nonmembers(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $operator = $this->user(UserSystemRoleEnum::EDITOR);
        $customer = $this->user();
        $other = $this->user();
        $this->grant($super, $operator);
        $conversation = app(OperatorConversationMessaging::class)->start($operator, $customer, 'Привет!');

        $this->actingAs($operator);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->get('/backoffice/operator-conversations/'.$conversation->id)
            ->assertOk()->assertSee('Привет!');
        $chat = Livewire::test(OperatorLiveChat::class, ['conversationId' => $conversation->id])
            ->assertSee('Привет!');

        $this->actingAs($customer)->postJson(route('account.notifications.threads.operator.messages', $conversation), [
            'body' => 'Ответ из браузера',
        ])->assertCreated();

        $this->actingAs($operator);
        $chat->call('refreshConversation')->assertSee('Ответ из браузера');
        $chat->set('draft', 'Ответ от сотрудника')->call('send')
            ->assertHasNoErrors()->assertSet('draft', '')->assertSee('Ответ от сотрудника');

        $this->actingAs($other);
        $this->get('/backoffice/operator-conversations/'.$conversation->id)->assertForbidden();
        Livewire::test(OperatorLiveChat::class, ['conversationId' => $conversation->id])
            ->assertForbidden();
    }

    public function test_revocation_redacts_old_operator_notification_previews_without_affecting_the_customer(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $operator = $this->user(UserSystemRoleEnum::EDITOR);
        $customer = $this->user();
        $this->grant($super, $operator);
        $conversation = app(OperatorConversationMessaging::class)->start($operator, $customer, 'Операторский привет');
        $this->actingAs($customer)->postJson(
            route('account.notifications.threads.operator.messages', $conversation),
            ['body' => 'Приватный ответ получен'],
        )->assertCreated();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $operator->id,
            'body' => 'Приватный ответ получен',
        ]);

        app(GlobalOperationalPermissionManager::class)->sync($super, $operator, [], []);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $operator->id,
            'title' => 'Операторские диалоги MSKBA',
            'body' => 'Доступ к переписке прекращён.',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $customer->id,
            'body' => 'Операторский привет',
        ]);
    }

    public function test_new_operators_cannot_start_until_explicitly_granted_and_marketing_is_separate(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $customer = $this->user();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(OperatorConversationMessaging::class)->start($admin, $customer, 'Оператор без права');
    }
}
