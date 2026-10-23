<?php

namespace Tests\Feature\Notification;

use App\Filament\Backoffice\Resources\SupportTickets\SupportTicketResource;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserOperationalPermissionsHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\SupportTicketAccess;
use App\Modules\Notification\Application\Services\SupportTicketLifecycleText;
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
        $this->withoutVite();
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

    public function test_support_conversations_are_visually_grouped_without_merging_tickets(): void
    {
        $customer = $this->user();
        $agent = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $first = $this->createTicket($customer);
        $second = $this->createTicket($customer);

        $this->actingAs($agent)->postJson(route('account.notifications.threads.support.messages', $second), [
            'body' => 'Ответ по второму обращению',
        ])->assertCreated();
        $this->actingAs($customer);
        $page = $this->get(route('account.notifications'))->assertOk()
            ->assertSee('data-conversation-group="support"', false)
            ->assertSee('Техподдержка')
            ->assertSee('data-conversation="support-'.$first->id.'"', false)
            ->assertSee('data-conversation="support-'.$second->id.'"', false)
            ->assertSee('Непрочитанных сообщений в группе: 1');
        $this->assertSame(1, substr_count($page->getContent(), 'data-conversation-group="support"'));
        preg_match_all('/<details class="app-messages__group"[^>]*>/', $page->getContent(), $details);
        $this->assertCount(1, array_filter($details[0], fn (string $tag): bool => str_contains($tag, ' open')));
        $this->assertStringContainsString(' open', $details[0][0]);
        $this->assertSame(2, SupportTicket::query()->count());

        $threadPage = $this->get(route('account.notifications.threads.support', $first))->assertOk()
            ->assertSee('data-group-active="true"', false)
            ->assertSee('data-rail-conversation="support-'.$first->id.'"', false)
            ->assertSee('data-rail-conversation="support-'.$second->id.'"', false);
        $this->assertSame(1, substr_count($threadPage->getContent(), 'data-conversation-group="support"'));
        preg_match_all('/<details class="app-messages__rail-group"[^>]*>/', $threadPage->getContent(), $railDetails);
        $this->assertCount(1, array_filter($railDetails[0], fn (string $tag): bool => str_contains($tag, ' open')));
        $rail = $this->getJson(route('account.notifications.rail', [
            'active' => 'support-'.$first->id,
        ]))->assertOk();
        $this->assertStringContainsString('data-conversation-group="support"', $rail->json('html'));
        $this->assertStringContainsString('data-group-active="true"', $rail->json('html'));
    }

    public function test_faq_source_is_a_clickable_internal_link_in_support_history_for_both_sides(): void
    {
        $customer = $this->user();
        $agent = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($customer)->postJson(route('faq.questions.store'), [
            'topic' => 'account',
            'source_path' => '/account',
            'message' => 'Помогите изменить данные моего аккаунта',
        ])->assertCreated();
        $ticket = SupportTicket::query()->latest('id')->firstOrFail();
        $link = 'href="/account" target="_blank" rel="noopener noreferrer"';

        $this->get(route('account.notifications.threads.support', $ticket))
            ->assertOk()->assertSee($link, false)->assertSee('Помогите изменить данные моего аккаунта');
        $history = $this->getJson(route('account.notifications.threads.support.history', $ticket))->assertOk();
        $this->assertStringContainsString($link, $history->json('html'));
        $this->actingAs($agent)->get(route('account.notifications.threads.support', $ticket))
            ->assertOk()->assertSee($link, false);

        // The preview shows the question, not a raw internal path.
        $this->actingAs($customer)->get(route('account.notifications'))
            ->assertOk()->assertSee('Помогите изменить данные моего аккаунта')
            ->assertDontSee('Страница обращения: /account');

        // Protocol-relative external URLs are never promoted to clickable links.
        $this->actingAs($customer)->post(route('account.notifications.support.store'), [
            'subject' => 'Проверка ссылки',
            'body' => 'Страница обращения: //example.com\\n\\nОбычный текст',
        ])->assertRedirect();
        $other = SupportTicket::query()->latest('id')->firstOrFail();
        $this->get(route('account.notifications.threads.support', $other))
            ->assertOk()->assertDontSee('href="//example.com"', false);
    }

    public function test_close_reason_actor_and_system_timeline_are_visible_to_both_sides_and_reopen(): void
    {
        $customer = $this->user();
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        // Factory usernames can be absent. This test verifies an @login format.
        $lead->forceFill(['username' => 'qa_support_lead'])->save();
        $ticket = $this->createTicket($customer);
        $close = route('account.notifications.threads.support.close', $ticket);
        $reopen = route('account.notifications.threads.support.reopen', $ticket);

        $this->actingAs($lead)->post($close, [
            'reason' => 'other',
        ])->assertSessionHasErrors(['description']);
        $this->assertSame('open', $ticket->fresh()->status);

        $this->post($close, [
            'reason' => 'other', 'description' => 'Проверка причины закрытия',
        ])->assertRedirect();
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertSame('other', $ticket->fresh()->close_reason);
        $this->assertSame('Проверка причины закрытия', $ticket->fresh()->close_description);
        $this->assertSame($lead->id, (int) $ticket->fresh()->closed_by_user_id);
        $this->assertNotNull($ticket->fresh()->closed_at);

        $event = $ticket->thread->events()->where('action', 'support_closed')->firstOrFail();
        $this->assertSame(
            'Обращение закрыл @'.$lead->username.'. Причина: Проверка причины закрытия.',
            app(SupportTicketLifecycleText::class)
                ->closed($lead->username, 'other', 'Проверка причины закрытия'),
        );
        $this->assertSame('other', $event->metadata['reason']);
        $this->assertSame($lead->id, (int) $event->actor_user_id);

        foreach ([$customer, $lead] as $viewer) {
            $page = $this->actingAs($viewer)->get(route('account.notifications.threads.support', $ticket))
                ->assertOk()->assertSee('Система MSKBA')
                ->assertSee('Обращение закрыл @'.$lead->username)
                ->assertSee('Причина: Проверка причины закрытия')
                ->assertDontSee('Описание причины:')->assertDontSee('Другая причина.')
                ->assertSee($lead->username);
            $this->assertStringContainsString('Обращение закрыл @'.$lead->username, $page->getContent());
            $history = $this->getJson(route('account.notifications.threads.support.history', $ticket))
                ->assertOk()->json('html');
            $this->assertStringContainsString('Обращение закрыл @'.$lead->username, $history);
            $this->assertStringContainsString('Причина: Проверка причины закрытия', $history);
            $this->assertStringNotContainsString('Описание причины:', $history);
            $this->assertStringNotContainsString('Другая причина.', $history);
        }
        $this->actingAs($customer)->post($reopen)->assertForbidden();
        $this->actingAs($lead)->post($reopen)->assertRedirect();
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->close_reason);
        $this->assertNull($ticket->fresh()->closed_by_user_id);
        $this->assertSame(1, $ticket->thread->events()->where('action', 'support_reopened')->count());
        $page = $this->actingAs($customer)->get(route('account.notifications.threads.support', $ticket))
            ->assertOk()->assertSee('Обращение возобновил @'.$lead->username)->assertDontSee('Возобновил:');
        $this->assertStringContainsString('Обращение закрыл @'.$lead->username, $page->getContent());
        $this->actingAs($lead)->post($reopen)->assertForbidden();
        $this->assertSame(1, $ticket->thread->events()->where('action', 'support_reopened')->count());
    }

    public function test_reason_is_enum_and_non_other_reasons_discard_description(): void
    {
        $customer = $this->user();
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $ticket = $this->createTicket($customer);

        $this->actingAs($lead)->post(route('account.notifications.threads.support.close', $ticket), [
            'reason' => 'malicious_invalid',
        ])->assertSessionHasErrors('reason');
        $this->assertSame('open', $ticket->fresh()->status);

        $this->post(route('account.notifications.threads.support.close', $ticket), [
            'reason' => 'no_longer_relevant', 'description' => 'Should not persist',
        ])->assertRedirect();
        $this->assertSame('no_longer_relevant', $ticket->fresh()->close_reason);
        $this->assertNull($ticket->fresh()->close_description);
        $this->actingAs($customer)->get(route('account.notifications.threads.support', $ticket))
            ->assertOk()->assertSee('<p>Причина: Не актуально</p>', false)
            ->assertDontSee('Should not persist');
    }

    public function test_support_history_keyset_paginates_system_events_and_messages_without_leaking(): void
    {
        $customer = $this->user();
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $stranger = $this->user();
        $ticket = $this->createTicket($customer);
        $thread = $ticket->thread;
        foreach (range(1, 14) as $index) {
            $thread->messages()->create(['sender_user_id' => $customer->id, 'body' => 'Сообщение '.$index]);
        }
        $this->actingAs($lead)->post(route('account.notifications.threads.support.close', $ticket), [
            'reason' => 'resolved',
        ])->assertRedirect();

        $seen = [];
        $cursor = null;
        for ($page = 0; $page < 4; $page++) {
            $response = $this->actingAs($customer)->getJson(
                route('account.notifications.threads.support.history', $ticket)
                .($cursor !== null ? '?before='.rawurlencode($cursor) : '')
            )->assertOk();
            $seen[] = $response->json('html');
            $cursor = $response->json('next_before');
            if ($cursor === null) {
                break;
            }
        }
        $joined = implode(' ', $seen);
        $this->assertStringContainsString('Обращение закрыл @'.$lead->username, $joined);
        $this->assertStringContainsString('Сообщение 1', $joined);
        $this->assertStringContainsString('Сообщение 14', $joined);
        $this->actingAs($stranger)->getJson(
            route('account.notifications.threads.support.history', $ticket)
        )->assertForbidden();
        $this->actingAs($customer)->getJson(
            route('account.notifications.threads.support.history', $ticket).'?before=bad!!'
        )->assertStatus(422);
    }

    public function test_filament_support_view_is_read_only_and_obeys_historical_permissions(): void
    {
        $customer = $this->user();
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $ticket = $this->createTicket($customer);
        $base = '/backoffice/support-tickets';

        $this->actingAs($customer)->get($base)->assertForbidden();
        $this->actingAs($lead)->get($base)->assertOk()
            ->assertSee('Техподдержка')
            ->assertSee('Управление - ', false)
            ->assertSee('originalSuffix', false);
        $this->get('/backoffice/dashboard-button-styles')->assertOk()
            ->assertSee('Стили кнопок')->assertSee('Сайт');
        $this->get($base.'/'.$ticket->id)->assertOk()
            ->assertSee('Вопрос об аккаунте')->assertSee('Прошу помочь с подтверждением');
        $this->assertFalse(SupportTicketResource::canCreate());
        $this->assertFalse(SupportTicketResource::canEdit($ticket));
        $this->assertFalse(SupportTicketResource::canDelete($ticket));

        $staff = $this->user(UserSystemRoleEnum::ADMIN);
        // Filament AuthenticateSession binds the preceding logged-in identity.
        $this->flushSession();
        $this->actingAs($staff)->get($base)->assertForbidden();
        app(AdminUpdateUserOperationalPermissionsHandler::class)
            ->handle($lead, $staff->id, [
                UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS,
            ]);
        $this->actingAs($staff)->get($base)->assertOk()
            ->assertDontSee('Вопрос об аккаунте');
        $this->get($base.'/'.$ticket->id)->assertNotFound();
    }

    public function test_support_state_reconciles_open_closed_and_reopened_composer_without_reverb(): void
    {
        $customer = $this->user();
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $ticket = $this->createTicket($customer);
        $stateUrl = route('account.notifications.threads.support.state', $ticket);

        $open = $this->actingAs($customer)->getJson($stateUrl)->assertOk()
            ->assertJsonPath('status', 'open')->assertJsonPath('can_send', true);
        $openSignature = $open->json('signature');
        $this->assertNotEmpty($openSignature);
        $this->assertStringContainsString('no-store', $open->headers->get('Cache-Control'));

        $openPage = $this->get(route('account.notifications.threads.support', $ticket))
            ->assertOk()->assertSee('data-chat-support-state-url=', false)
            ->assertSee('data-chat-support-signature="'.$openSignature.'"', false)
            ->assertSee('data-chat-compose', false);

        $this->actingAs($lead)->post(route('account.notifications.threads.support.close', $ticket), [
            'reason' => 'other', 'description' => 'Вопрос решён другим способом',
        ])->assertRedirect();

        $closed = $this->actingAs($customer)->getJson($stateUrl)->assertOk()
            ->assertJsonPath('status', 'closed')->assertJsonPath('can_send', false);
        $this->assertNotSame($openSignature, $closed->json('signature'));
        $closedPage = $this->get(route('account.notifications.threads.support', $ticket))
            ->assertOk()
            ->assertSee('Обращение закрыто. История доступна для чтения.')
            ->assertSee('Обращение закрыл @'.$lead->username)
            ->assertSee('Причина: Вопрос решён другим способом')
            ->assertDontSee('Описание причины:')
            ->assertSee('<p>Причина: Вопрос решён другим способом</p>', false)
            ->assertDontSee('data-chat-compose', false);
        $this->assertSame(1, substr_count(
            $closedPage->getContent(), 'Обращение закрыл @'.$lead->username,
        ));
        $this->assertStringContainsString('data-chat-support-signature="'.$closed->json('signature').'"',
            $closedPage->getContent());

        $this->actingAs($lead)->post(route('account.notifications.threads.support.reopen', $ticket))
            ->assertRedirect();

        $reopened = $this->actingAs($customer)->getJson($stateUrl)->assertOk()
            ->assertJsonPath('status', 'open')->assertJsonPath('can_send', true);
        $this->assertNotSame($closed->json('signature'), $reopened->json('signature'));
        $this->get(route('account.notifications.threads.support', $ticket))
            ->assertOk()->assertSee('data-chat-compose', false)
            ->assertDontSee('Обращение закрыто. История доступна для чтения.');
    }

    public function test_support_state_does_not_reveal_status_to_other_users_or_revoked_agents(): void
    {
        $lead = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $customer = $this->user();
        $outsider = $this->user();
        $ticket = $this->createTicket($customer);
        $url = route('account.notifications.threads.support.state', $ticket);
        $this->actingAs($outsider)->getJson($url)->assertForbidden();

        $staff = $this->user(UserSystemRoleEnum::ADMIN);
        app(AdminUpdateUserOperationalPermissionsHandler::class)
            ->handle($lead, $staff->id, [
                UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS,
            ]);
        $this->actingAs($staff)->getJson($url)->assertForbidden();

        $this->actingAs($customer)->getJson($url)->assertOk()
            ->assertJsonPath('can_send', true);
    }

    public function test_closure_text_avoids_duplicate_reason_label_and_punctuation(): void
    {
        $formatter = app(SupportTicketLifecycleText::class);
        $this->assertSame('Обращение закрыл @admin. Причина: Вопрос решён.',
            $formatter->closed('admin', 'resolved', 'Игнорируемая заметка'));
        $this->assertSame('Обращение закрыл @admin. Причина: Не актуально.',
            $formatter->closed('admin', 'no_longer_relevant', null));
        $this->assertSame('Обращение закрыл @admin. Причина: Подтверждена ошибка!',
            $formatter->closed('admin', 'other', 'Подтверждена ошибка!'));
        $this->assertSame('Обращение закрыл @admin.',
            $formatter->closed('admin', null, null));
        $this->assertSame('Обращение возобновил @admin.', $formatter->reopened('admin'));
        $this->assertSame('Обращение возобновил @сотрудник_поддержки.', $formatter->reopened(null));
        $this->assertSame('Не актуально', $formatter->reason('no_longer_relevant', null));
        $this->assertSame('Проверка сохранения', $formatter->reason('other', 'Проверка сохранения'));
        $this->assertNull($formatter->reason(null, null));
    }
}
