<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminCreationPermissionEntryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantCreationPermissionsToTestActors = false;

    public function test_admin_and_superadmin_can_open_creation_without_verified_contact(): void
    {
        foreach ([UserSystemRoleEnum::ADMIN, UserSystemRoleEnum::SUPERADMIN] as $role) {
            $user = User::factory()->create([
                'status' => UserStatusEnum::CONFIRMED,
                'system_role' => $role,
            ]);

            $this->actingAs($user)
                ->get(route('events.wizard', ['type' => 'game']))
                ->assertOk()
                ->assertSessionMissing('operational_permission_intent');

            $this->actingAs($user)
                ->get(route('tournaments.create'))
                ->assertOk()
                ->assertSessionMissing('operational_permission_intent');
        }
    }
}
