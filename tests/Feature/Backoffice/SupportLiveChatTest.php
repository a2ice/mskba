<?php

namespace Tests\Feature\Backoffice;

use App\Livewire\Backoffice\SupportLiveChat;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserOperationalPermissionsHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\SupportTicket;
use App\Presentation\Theming\ThemeResolver;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\TestCase;

final class SupportLiveChatTest extends TestCase
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
            'system_role' => $role, 'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);

        return $user;
    }

    private function ticket(User $customer): SupportTicket
    {
        $this->actingAs($customer)->post(route('account.notifications.support.store'), [
            'subject' => 'Проверка общего чата',
            'body' => 'Первое сообщение от пользователя',
        ])->assertRedirect();

        return SupportTicket::query()->latest('id')->firstOrFail();
    }

    public function test_filament_agent_reads_and_replies_using_existing_customer_thread(): void
    {
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $customer = $this->user();
        $ticket = $this->ticket($customer);

        $this->actingAs($lead);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->get('/backoffice/support-tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee('Переписка с пользователем')
            ->assertSee('Первое сообщение от пользователя');

        Livewire::test(SupportLiveChat::class, ['ticketId' => $ticket->id])
            ->assertSee('Первое сообщение от пользователя')
            ->assertSee('Ответ пользователю')
            ->set('draft', 'Здравствуйте, мы поможем разобраться')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('draft', '')
            ->assertSee('Здравствуйте, мы поможем разобраться');

        $this->assertDatabaseHas('request_thread_messages', [
            'request_thread_id' => $ticket->thread->id,
            'sender_user_id' => $lead->id,
            'body' => 'Здравствуйте, мы поможем разобраться',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $customer->id,
            'body' => 'Здравствуйте, мы поможем разобраться',
        ]);
        $this->actingAs($customer)->get(route('account.notifications.threads.support', $ticket))
            ->assertOk()->assertSee('Здравствуйте, мы поможем разобраться');
        // A user reply from the portal/popover is visible after refreshing the
        // existing Livewire chat, even if the agent has not sent anything.
        $this->actingAs($customer)->postJson(route('account.notifications.threads.support.messages', $ticket), [
            'body' => 'Новое сообщение из плавающего окна',
        ])->assertCreated();
        $this->actingAs($lead);
        Livewire::test(SupportLiveChat::class, ['ticketId' => $ticket->id])
            ->call('refreshConversation')
            ->assertSee('Новое сообщение из плавающего окна');

    }

    public function test_livewire_rejects_stranger_before_mount_and_legacy_access_rules_remain(): void
    {
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $customer = $this->user();
        $ticket = $this->ticket($customer);
        $stranger = $this->user();

        $this->actingAs($stranger);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->get('/backoffice/support-tickets/'.$ticket->id)->assertForbidden();
        Livewire::test(SupportLiveChat::class, ['ticketId' => $ticket->id])->assertForbidden();

        // Portal access to own conversation does not imply Filament permissions.
        $this->actingAs($customer);
        Livewire::test(SupportLiveChat::class, ['ticketId' => $ticket->id])->assertForbidden();
    }

    public function test_lost_permission_is_checked_again_for_each_livewire_action(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $staff = $this->user(UserSystemRoleEnum::ADMIN);
        $customer = $this->user();
        app(AdminUpdateUserOperationalPermissionsHandler::class)
            ->handle($super, $staff->id, [UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS]);
        $ticket = $this->ticket($customer);

        $this->actingAs($staff);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $chat = Livewire::test(SupportLiveChat::class, ['ticketId' => $ticket->id])
            ->assertSee('Первое сообщение от пользователя');
        $chat->set('draft', 'Сообщение до отзыва');

        app(AdminUpdateUserOperationalPermissionsHandler::class)
            ->handle($super, $staff->id, []);
        $chat->call('send')->assertForbidden();

        $this->assertDatabaseMissing('request_thread_messages', [
            'request_thread_id' => $ticket->thread->id,
            'body' => 'Сообщение до отзыва',
        ]);
    }

    public function test_closing_support_ticket_disables_live_chat_and_blocks_late_send(): void
    {
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $customer = $this->user();
        $ticket = $this->ticket($customer);
        $this->actingAs($lead);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $chat = Livewire::test(SupportLiveChat::class, ['ticketId' => $ticket->id])
            ->set('draft', 'Нельзя после закрытия');

        $this->post(route('account.notifications.threads.support.close', $ticket), [
            'reason' => 'other',
            'description' => 'Пользователь получил подробный ответ',
        ])->assertRedirect();

        $chat->call('send')->assertForbidden();
        Livewire::test(SupportLiveChat::class, ['ticketId' => $ticket->id])
            ->assertSee('Обращение закрыто')
            ->assertSee('Причина: Пользователь получил подробный ответ')
            ->assertDontSee('Ответ пользователю');

        $this->assertDatabaseMissing('request_thread_messages', [
            'request_thread_id' => $ticket->thread->id,
            'body' => 'Нельзя после закрытия',
        ]);
        $this->post(route('account.notifications.threads.support.reopen', $ticket))
            ->assertRedirect();
        Livewire::test(SupportLiveChat::class, ['ticketId' => $ticket->id])
            ->assertSee('Ответ пользователю')
            ->assertSee('Обращение возобновил @'.$lead->username);
    }

    public function test_live_chat_never_accepts_blank_or_oversized_messages(): void
    {
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $customer = $this->user();
        $ticket = $this->ticket($customer);
        $this->actingAs($lead);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(SupportLiveChat::class, ['ticketId' => $ticket->id])
            ->set('draft', '   ')->call('send')->assertHasErrors(['draft']);
        Livewire::test(SupportLiveChat::class, ['ticketId' => $ticket->id])
            ->set('draft', str_repeat('x', 2001))->call('send')->assertHasErrors(['draft']);
        $this->assertCount(1, $ticket->thread->messages()->get());
    }
}
