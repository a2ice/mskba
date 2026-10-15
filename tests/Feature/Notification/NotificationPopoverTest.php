<?php

namespace Tests\Feature\Notification;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\DirectConversation;
use App\Modules\Notification\Domain\Models\SupportTicket;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class NotificationPopoverTest extends TestCase
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

    private function ticket(User $owner): SupportTicket
    {
        $this->actingAs($owner)->post(route('account.notifications.support.store'), [
            'subject' => 'Проверка поповера',
            'body' => 'История от владельца',
        ])->assertRedirect();

        return SupportTicket::query()->latest('id')->firstOrFail();
    }

    public function test_support_popover_returns_only_authorized_routes_and_private_reverb_channel(): void
    {
        $owner = $this->user();
        $agent = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $outsider = $this->user();
        $ticket = $this->ticket($owner);
        $path = route('account.notifications.popover', ['kind' => 'support', 'id' => $ticket->id]);

        $this->actingAs($outsider)->getJson($path)->assertForbidden();
        $ownerView = $this->actingAs($owner)->getJson($path)->assertOk()
            ->assertJsonPath('kind', 'support')
            ->assertJsonPath('can_send', true)
            ->assertJsonPath('limit', 2000)
            ->assertJsonPath('channel', 'support-tickets.'.$ticket->thread->id)
            ->assertJsonPath('history_url', route('account.notifications.threads.support.history', $ticket, false));
        $this->assertStringContainsString('no-store', $ownerView->headers->get('Cache-Control'));

        $this->actingAs($agent)->getJson($path)->assertOk()
            ->assertJsonPath('kind', 'support')
            ->assertJsonPath('can_send', true);
        $this->post(route('account.notifications.threads.support.close', $ticket), [
            'reason' => 'resolved',
        ])->assertRedirect();
        $this->actingAs($owner)->getJson($path)->assertOk()
            ->assertJsonPath('can_send', false)
            ->assertJsonPath('status', 'Обращение закрыто. История доступна для чтения.');
        $this->getJson(route('account.notifications.threads.support.state', $ticket))
            ->assertOk()->assertJsonPath('close_reason', 'Вопрос решён');
        $this->getJson(route('account.notifications.threads.support.history', $ticket))->assertOk();
        $this->postJson(route('account.notifications.threads.support.messages', $ticket), [
            'body' => 'Нельзя писать в закрытое обращение',
        ])->assertForbidden();
    }

    public function test_direct_popover_uses_participant_identity_and_existing_direct_access(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $stranger = $this->user();
        $ids = [$owner->id, $other->id];
        sort($ids);
        $conversation = DirectConversation::query()->create([
            'user_low_id' => $ids[0], 'user_high_id' => $ids[1],
        ]);
        $url = route('account.notifications.popover', ['kind' => 'direct', 'id' => $conversation->id]);

        $this->actingAs($stranger)->getJson($url)->assertForbidden();
        $this->actingAs($owner)->getJson($url)->assertOk()
            ->assertJsonPath('kind', 'direct')
            ->assertJsonPath('channel', 'direct-conversations.'.$conversation->id)
            ->assertJsonPath('limit', 500)
            ->assertJsonPath('send_url', route('account.notifications.direct.send', $other, false));
        $this->actingAs($other)->getJson($url)->assertOk()
            ->assertJsonPath('send_url', route('account.notifications.direct.send', $owner, false));
        $this->getJson(route('account.notifications.popover', ['kind' => 'unknown', 'id' => $conversation->id]))
            ->assertNotFound();
        $this->getJson(route('account.notifications.popover', ['kind' => 'direct', 'id' => 99999999]))
            ->assertNotFound();
    }

    public function test_popover_requires_authentication(): void
    {
        $owner = $this->user();
        $ticket = $this->ticket($owner);
        auth()->logout();
        $this->getJson(route('account.notifications.popover', ['kind' => 'support', 'id' => $ticket->id]))
            ->assertUnauthorized();
    }

    public function test_read_notification_can_restore_its_popover_only_for_its_owner(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $notice = UserNotification::query()->create([
            'user_id' => $owner->id,
            'type' => UserNotificationTypeEnum::SYSTEM,
            'status' => UserNotificationStatusEnum::READ,
            'title' => 'Подробности уведомления',
            'body' => 'Сообщение остаётся доступным после прочтения.',
            'action_url' => '/faq',
        ]);
        $url = route('account.notifications.popover.notice', $notice->id);

        $this->actingAs($stranger)->getJson($url)->assertNotFound();
        $response = $this->actingAs($owner)->getJson($url)->assertOk()
            ->assertJsonPath('id', $notice->id)
            ->assertJsonPath('is_read', true)
            ->assertJsonPath('title', 'Подробности уведомления')
            ->assertJsonPath('body', 'Сообщение остаётся доступным после прочтения.');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->getJson(route('account.notifications.popover.notice', 9999999))->assertNotFound();
    }
}
