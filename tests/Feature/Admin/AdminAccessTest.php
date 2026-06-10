<?php

namespace Tests\Feature\Admin;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_admin_dashboard(): void
    {
        $this
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_regular_user_cannot_access_admin_dashboard(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);

        $this
            ->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_unconfirmed_admin_cannot_access_admin_dashboard(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::UNCONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_confirmed_admin_can_access_admin_pages(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);

        foreach ([
            'admin.dashboard',
            'admin.users',
            'admin.venues',
            'admin.events',
            'admin.teams',
            'admin.content',
            'admin.settings',
        ] as $route) {
            $this
                ->actingAs($admin)
                ->get(route($route))
                ->assertOk();
        }
    }
}
