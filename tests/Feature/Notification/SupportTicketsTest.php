<?php

namespace Tests\Feature\Notification;

use App\Modules\Identity\Application\UseCases\AdminUpdateUserOperationalPermissionsHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\SupportTicketAccess;
use App\Modules\Notification\Domain\Models\SupportTicket;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChannel;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class SupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    private function user(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        $user = User::factory()->create(['system_role' => $role, 'status' => UserStatusEnum::CONFIRMED]);
        $user->createProfile([]);

        return $user;
    }

    private function createTicket(User $customer): SupportTicket
    {
        $this->actingAs($customer)->post(route('account.notifications.support.store'), [
            'subject' => 'Вопрос об аккаунте', 'body' => 'Прошу помочь с подтверждением',
        ])->assertRedirect();

        return SupportTicket::query()->latest('id')->firstOrFail();
    }

    public function test_support_form_uses_compact_workspace_and_inbox_intro_action_and_configured_email(): void
    {
        config()->set('support.email', 'helpdesk-test@mskba.example');
        $customer = $this->user();
        $this->createTicket($customer);

        $index = $this->actingAs($customer)->get(route('account.notifications'))->assertOk()
            ->assertSee('app-account-intro--with-action', false)
            ->assertSee('app-account-intro__action', false)
            ->assertSee('Написать в техподдержку')
            ->assertDontSee('app-messages__inbox-toolbar', false);
        $this->assertSame(1, substr_count($index->getContent(), 'class="button primary app-account-intro__action"'));
        $this->assertLessThan(
            strpos($index->getContent(), 'class="app-messages"'),
            strpos($index->getContent(), 'app-account-intro__action'),
            'The support action belongs in the introduction, before the inbox.',
        );

        $response = $this->get(route('account.notifications.support.start'))->assertOk()
            ->assertSee('app-account-layout--compact-sidebar', false)
            ->assertSee('app-message-workspace--support-create', false)
            ->assertSee('data-conversation-rail', false)
            ->assertSee('data-rail-conversation="support-1"', false)
            ->assertSee('Вы также можете написать нам на почту')
            ->assertSee('href="mailto:helpdesk-test@mskba.example"', false)
            ->assertSee('class="app-messages__compose-footer"', false)
            ->assertSee('Отмена')
            ->assertSee('Отправить обращение');
        $markup = $response->getContent();
        $this->assertLessThan(
            strpos($markup, '<section class="panel app-messages__support-form"'),
            strpos($markup, '<aside class="panel app-messages__rail"'),
            'The list of other conversations must appear before the compose form.',
        );
        $this->assertLessThan(
            strpos($markup, 'class="app-messages__support-email"'),
            strpos($markup, 'class="app-messages__compose-footer"'),
            'The email alternative follows the right-aligned action row.',
        );
    }

    public function test_system_history_is_private_and_support_ticket_is_a_separate_conversation(): void
    {
        $customer = $this->user();
        $outsider = $this->user();
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $ticket = $this->createTicket($customer);
        $thread = $ticket->thread()->firstOrFail();
        $this->assertSame($lead->id, (int) $thread->lead_user_id);

        $this->actingAs($customer)->get(route('account.notifications.threads.system'))->assertOk()
            ->assertSee('data-chat-history', false)
            ->assertSee('data-conversation-rail', false)
            ->assertSee('Написать в техподдержку')
            ->assertDontSee('data-chat-entry="support-', false);
        $this->getJson(route('account.notifications.threads.system.history'))->assertOk()
            ->assertDontSee('Прошу помочь с подтверждением');
        $this->get(route('account.notifications.threads.support', $ticket))->assertOk()
            ->assertSee('Прошу помочь с подтверждением')
            ->assertSee('support-tickets.'.$thread->id, false);
        $this->actingAs($outsider)->get(route('account.notifications.threads.support', $ticket))
            ->assertForbidden();
        $this->getJson(route('account.notifications.threads.support.history', $ticket))->assertForbidden();
        $this->postJson(route('account.notifications.threads.support.messages', $ticket), [
            'body' => 'Попытка чужого ответа',
        ])->assertForbidden();
        $this->assertFalse(app(ConversationChannel::class)->support($outsider, $thread->id));
        $this->assertTrue(app(ConversationChannel::class)->support($lead, $thread->id));
        $this->actingAs($lead)->get(route('account.notifications'))->assertOk()
            ->assertSee('Техподдержка №'.$ticket->id);
        $this->get(route('account.notifications.threads.support', $ticket))->assertOk()
            ->assertSee('<title>Сообщения — Техподдержка №'.$ticket->id.' · MSKBA</title>', false);
        $rail = $this->getJson(route('account.notifications.rail').'?active=support-'.$ticket->id)->assertOk();
        $this->assertStringContainsString('data-rail-conversation="support-'.$ticket->id.'"', $rail->json('html'));
    }

    public function test_support_agent_grant_does_not_retroactively_open_old_tickets_and_revocation_is_immediate(): void
    {
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $first = $this->user();
        $staff = $this->user(UserSystemRoleEnum::ADMIN);
        $old = $this->createTicket($first);
        app(AdminUpdateUserOperationalPermissionsHandler::class)->handle($admin, $staff->id, [
            UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS,
        ]);
        $access = app(SupportTicketAccess::class);
        $this->assertFalse($access->canRead($staff, $old, $old->thread));
        $this->actingAs($staff)->get(route('account.notifications.threads.support', $old))->assertForbidden();
        $second = $this->createTicket($first);
        $this->assertTrue($access->canRead($staff, $second, $second->thread));
        $this->actingAs($staff)->get(route('account.notifications.threads.support', $second))->assertOk();
        $this->postJson(route('account.notifications.threads.support.messages', $second), [
            'body' => 'Ответ техподдержки',
        ])->assertCreated();
        $this->assertTrue(app(ConversationChannel::class)->support($staff, $second->thread->id));
        app(AdminUpdateUserOperationalPermissionsHandler::class)->handle($admin, $staff->id, []);
        $this->assertFalse(app(ConversationChannel::class)->support($staff, $second->thread->id));
        $this->actingAs($staff)->getJson(route('account.notifications.threads.support.history', $second))
            ->assertForbidden();
        $this->postJson(route('account.notifications.threads.support.messages', $second), [
            'body' => 'Отозвано',
        ])->assertForbidden();
        app(AdminUpdateUserOperationalPermissionsHandler::class)->handle($admin, $staff->id, [
            UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS,
        ]);
        $this->assertFalse($access->canRead($staff, $second, $second->thread));
    }

    public function test_lead_can_transfer_historical_ticket_to_permitted_agent_and_revocation_orphans_lead(): void
    {
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $customer = $this->user();
        $ticket = $this->createTicket($customer);
        $agent = $this->user(UserSystemRoleEnum::ADMIN);
        app(AdminUpdateUserOperationalPermissionsHandler::class)->handle($lead, $agent->id, [
            UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS,
        ]);
        $this->assertFalse(app(SupportTicketAccess::class)->canRead($agent, $ticket, $ticket->thread));
        $this->actingAs($agent)->post(route('account.notifications.threads.support.close', $ticket))
            ->assertForbidden();
        $this->actingAs($lead)->post(route('account.notifications.threads.support.transfer', $ticket), [
            'user_id' => $agent->id,
        ])->assertRedirect();
        $this->assertSame($agent->id, (int) $ticket->thread->fresh()->lead_user_id);
        $this->actingAs($agent)->get(route('account.notifications.threads.support', $ticket))->assertOk();
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $agent->id, 'body' => 'Вам передали ведение обращения №'.$ticket->id.'.',
        ]);
        app(AdminUpdateUserOperationalPermissionsHandler::class)->handle($lead, $agent->id, []);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $agent->id, 'body' => 'Доступ к обращению прекращён.', 'status' => 'read',
        ]);
        $this->assertNull($ticket->thread->fresh()->lead_user_id);
        $this->actingAs($agent)->getJson(route('account.notifications.threads.support.history', $ticket))
            ->assertForbidden();
        app(AdminUpdateUserOperationalPermissionsHandler::class)->handle($lead, $agent->id, [
            UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS,
        ]);
        $this->actingAs($agent)->getJson(route('account.notifications.threads.support.history', $ticket))
            ->assertForbidden();
        $this->actingAs($lead)->post(route('account.notifications.threads.support.claim', $ticket))
            ->assertRedirect();
        $this->assertSame($lead->id, (int) $ticket->thread->fresh()->lead_user_id);
    }

    public function test_support_reply_notifications_are_scoped_and_close_prevents_new_messages(): void
    {
        $customer = $this->user();
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $ticket = $this->createTicket($customer);
        $this->actingAs($lead)->postJson(route('account.notifications.threads.support.messages', $ticket), [
            'body' => 'Ваш вопрос получен',
        ])->assertCreated();
        $this->actingAs($customer)->get(route('account.notifications'))->assertOk()
            ->assertSee('Ваш вопрос получен');
        $this->getJson(route('account.notifications.threads.system.history'))->assertOk()
            ->assertJsonPath('html', '');
        $this->patchJson(route('account.notifications.threads.support.read', $ticket))->assertOk();
        $this->assertSame(0, UserNotification::query()->where('user_id', $customer->id)
            ->where('payload->support_ticket_id', $ticket->id)->where('status', 'new')->count());
        $this->actingAs($lead)->post(route('account.notifications.threads.support.close', $ticket))
            ->assertRedirect();
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->actingAs($customer)->postJson(route('account.notifications.threads.support.messages', $ticket), [
            'body' => 'Позднее сообщение',
        ])->assertForbidden();
    }
}
