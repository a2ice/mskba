<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueRawAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_venue_related_user_can_create_venue_with_raw_address(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);
        $this->assignVenueRelatedRole($user);

        $this
            ->actingAs($user)
            ->post(route('account.venues.store'), [
                'name' => 'Тестовая площадка',
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'description' => 'Описание тестовой площадки',
                'raw_address' => 'Москва, ул. Летниковская, 12',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('venues', [
            'name' => 'Тестовая площадка',
            'raw_address' => 'Москва, ул. Летниковская, 12',
        ]);
    }

    private function assignVenueRelatedRole(User $user): void
    {
        $user->participationRoles()->create([
            'role' => UserParticipationRoleEnum::VENUE_RELATED,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
    }
}
