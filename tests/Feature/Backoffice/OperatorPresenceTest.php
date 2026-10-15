<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\OperatorUsers\OperatorUserResource;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Application\Services\OperatorActivityService;
use App\Modules\Portal\Infrastructure\Broadcasting\OperatorPresenceChangedBroadcast;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class OperatorPresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('operator_activity.cache_store', 'array');
        config()->set('site_summary.presence_store', 'array');
        config()->set('site_summary.cache_store', 'array');
        Cache::store('array')->clear();
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

    public function test_operator_permissions_are_denied_by_default_even_to_regular_admins(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $user = $this->user();
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $checker = app(UserOperationalPermissionChecker::class);

        foreach ([
            Permission::OPERATOR_PRESENCE_VIEW, Permission::OPERATOR_SESSION_HISTORY_VIEW,
            Permission::OPERATOR_CONVERSATION_HANDLE, Permission::OPERATOR_MARKETING_SEND,
        ] as $permission) {
            $this->assertFalse($checker->allows($admin, $permission));
            $this->assertFalse($checker->allows($user, $permission));
            $this->assertTrue($checker->allows($super, $permission));
        }
    }

    public function test_only_superadmin_can_grant_and_revoke_sensitive_operator_permissions(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::EDITOR);
        $manager = app(GlobalOperationalPermissionManager::class);
        $checker = app(UserOperationalPermissionChecker::class);

        $manager->sync($super, $target, [], [
            Permission::OPERATOR_PRESENCE_VIEW->value,
            Permission::OPERATOR_CONVERSATION_HANDLE->value,
        ]);
        $this->assertTrue($checker->allows($target, Permission::OPERATOR_PRESENCE_VIEW));
        $this->assertTrue($checker->allows($target, Permission::OPERATOR_CONVERSATION_HANDLE));
        $this->assertFalse($checker->allows($target, Permission::OPERATOR_SESSION_HISTORY_VIEW));

        // Forged legacy/admin update must not change sensitive grants.
        app(\App\Modules\Identity\Application\UseCases\AdminUpdateUserOperationalPermissionsHandler::class)
            ->handle($admin, $target->id, [
                Permission::OPERATOR_MARKETING_SEND,
            ]);
        $this->assertFalse($checker->allows($target, Permission::OPERATOR_MARKETING_SEND));
        $this->assertTrue($checker->allows($target, Permission::OPERATOR_PRESENCE_VIEW));

        $manager->sync($super, $target, [], []);
        $this->assertFalse($checker->allows($target, Permission::OPERATOR_PRESENCE_VIEW));
    }

    public function test_heartbeat_records_only_safe_paths_and_bounded_history(): void
    {
        $user = $this->user();
        $service = app(OperatorActivityService::class);
        $this->actingAs($user)->postJson(route('account.presence.heartbeat'), [
            'path' => '/faq?email=hidden',
        ])->assertOk();
        $this->assertSame('/faq', $service->state($user)['path']);
        $this->assertTrue($service->state($user)['online']);

        $this->postJson(route('account.presence.heartbeat'), [
            'path' => '/account/contacts?token=secret',
        ])->assertOk();
        $this->assertSame('/account', $service->state($user)['path']);

        // A staff member with presence-view permission should see the actual
        // Messages page, but never sensitive account routes or URL parameters.
        $this->postJson(route('account.presence.heartbeat'), [
            'path' => '/account/notifications/threads/operator/17?token=private#draft',
        ])->assertOk();
        $this->assertSame('/account/notifications/threads/operator/17', $service->state($user)['path']);
        $this->assertSame('/account/notifications', $service->safePath('/account/notifications'));
        $this->assertSame('/account/notifications/threads/support/42', $service->safePath('/account/notifications/threads/support/42'));
        $this->assertSame('/account/notifications', $service->safePath('/account/notifications/threads/profile/99'));
        $this->assertSame('/account', $service->safePath('/account/contacts/verify/1234'));
        $this->assertSame('/account/notifications', $service->safePath('/account/notifications/read/123?token=xyz'));
        $this->assertSame('/', $service->safePath('https://another.example/faq'));
        $this->assertSame('/', $service->safePath('//another.example/faq'));

        for ($i = 0; $i < 110; $i++) {
            $service->record($user, session()->getId(), '/faq/'.$i);
        }
        $this->assertCount(100, $service->history($user));
        $this->assertSame('/faq/109', $service->history($user)[99]['path']);
    }

    public function test_presence_broadcasts_only_on_actual_page_changes_without_url_payload(): void
    {
        $user = $this->user();
        Event::fake([OperatorPresenceChangedBroadcast::class]);

        $this->actingAs($user)->postJson(route('account.presence.heartbeat'), [
            'path' => '/account/notifications/threads/operator/1?private=hidden',
        ])->assertOk();

        // Repeated heartbeat with a different query must not broadcast.
        $this->postJson(route('account.presence.heartbeat'), [
            'path' => '/account/notifications/threads/operator/1?private=another',
        ])->assertOk();

        Event::assertDispatchedTimes(OperatorPresenceChangedBroadcast::class, 1);
        Event::assertDispatched(OperatorPresenceChangedBroadcast::class,
            fn (OperatorPresenceChangedBroadcast $event): bool =>
                $event->userId === $user->id && $event->broadcastWith() === ['changed' => true]);

        $this->postJson(route('account.presence.heartbeat'), ['path' => '/faq'])->assertOk();
        Event::assertDispatchedTimes(OperatorPresenceChangedBroadcast::class, 2);
    }

    public function test_filament_users_require_presence_right_and_history_requires_extra_permission(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $operator = $this->user(UserSystemRoleEnum::EDITOR);
        $target = $this->user();
        $manager = app(GlobalOperationalPermissionManager::class);
        $checker = app(UserOperationalPermissionChecker::class);

        $this->actingAs($operator);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->assertFalse(OperatorUserResource::canViewAny());

        $manager->sync($super, $operator, [], [Permission::OPERATOR_PRESENCE_VIEW->value]);
        app(\App\Modules\Identity\Application\Services\BackofficePermissionManager::class)->sync(
            $super, $operator, [Permission::BACKOFFICE_ACCESS->value],
        );

        $this->assertTrue($checker->allows($operator, Permission::OPERATOR_PRESENCE_VIEW));
        $this->assertTrue(OperatorUserResource::canViewAny());
        $this->assertFalse($checker->allows($operator, Permission::OPERATOR_SESSION_HISTORY_VIEW));

        $this->actingAs($operator)->get('/backoffice/operator-users')->assertOk()
            ->assertSee($target->username);
    }
}
