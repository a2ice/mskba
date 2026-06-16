<?php

namespace Tests\Feature\Admin;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_admin_tiles(): void
    {
        $admin = $this->admin();

        $this
            ->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Пользователи')
            ->assertSee('Площадки')
            ->assertSee('События')
            ->assertSee('Команды')
            ->assertSee('Контент')
            ->assertSee('Настройки');
    }

    public function test_users_page_filters_by_search(): void
    {
        $admin = $this->admin();
        User::factory()->create([
            'username' => 'visible-user',
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        User::factory()->create([
            'username' => 'hidden-user',
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.users', ['search' => 'visible']))
            ->assertOk()
            ->assertSee('visible-user')
            ->assertDontSee('hidden-user');
    }

    public function test_venues_page_filters_by_status_and_type(): void
    {
        $admin = $this->admin();
        Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($admin),
            'name' => 'Проверяемая площадка',
            'status' => VenueStatusEnum::CONFIRMED,
            'type' => VenueTypeEnum::SPORTS_HALL,
        ]);
        Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($admin),
            'name' => 'Скрытая площадка',
            'status' => VenueStatusEnum::UNCONFIRMED,
            'type' => VenueTypeEnum::STREET_COURT,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.venues', [
                'status' => VenueStatusEnum::CONFIRMED->value,
                'type' => VenueTypeEnum::SPORTS_HALL->value,
            ]))
            ->assertOk()
            ->assertSee('Проверяемая площадка')
            ->assertDontSee('Скрытая площадка');
    }

    private function admin(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
    }

    private function actorIdFor(User $user): int
    {
        return app(CurrentActorResolver::class)->resolve($user, null)->id;
    }
}
