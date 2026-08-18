<?php

namespace Tests\Feature\Team;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamDraftAndCreationLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_cannot_create_more_than_five_permanent_teams(): void
    {
        $user = User::factory()->create([
            'username' => 'five-team-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($user);
        foreach (range(1, 5) as $number) {
            $this->post(route('teams.store'), [
                'name' => "Команда {$number}",
                'sport_types' => ['basketball'],
            ])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->post(route('teams.store'), [
            'name' => 'Шестая команда',
            'sport_types' => ['basketball'],
        ])->assertStatus(422)->assertSee('Достигнут лимит: можно создать не более 5 команд.');

        $this->assertSame(5, Team::query()
            ->whereNull('temporary_for_event_id')
            ->whereHas('createdByActor', fn ($actor) => $actor->where('user_id', $user->id))
            ->count());
    }

    public function test_superadmin_is_not_limited_to_five_teams(): void
    {
        $superadmin = User::factory()->create([
            'username' => 'unlimited-superadmin',
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);

        $this->actingAs($superadmin);
        foreach (range(1, 6) as $number) {
            $this->post(route('teams.store'), [
                'name' => "Команда суперадмина {$number}",
                'sport_types' => ['basketball'],
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(6, Team::query()
            ->whereHas('createdByActor', fn ($actor) => $actor->where('user_id', $superadmin->id))
            ->count());
    }

    public function test_creation_limit_includes_teams_created_by_alias(): void
    {
        $alias = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($alias);
        foreach (range(1, 5) as $number) {
            $this->post(route('teams.store'), [
                'name' => "Команда alias {$number}",
                'sport_types' => ['basketball'],
            ])->assertRedirect();
        }

        $canonical = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();

        $this->actingAs($canonical)->post(route('teams.store'), [
            'name' => 'Шестая команда identity',
            'sport_types' => ['basketball'],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('teams', ['name' => 'Шестая команда identity']);
    }

    public function test_my_teams_can_be_filtered_by_draft_status(): void
    {
        $user = User::factory()->create([
            'username' => 'draft-team-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($user)->post(route('teams.store'), [
            'name' => 'Команда в черновике',
            'sport_types' => ['basketball'],
        ])->assertRedirect();

        $team = Team::query()->where('name', 'Команда в черновике')->firstOrFail();
        $team->update(['status' => TeamStatusEnum::DRAFT]);

        $this->get(route('account.teams', ['status' => TeamStatusEnum::DRAFT->value]))
            ->assertOk()
            ->assertSee('Команда в черновике')
            ->assertSee('Черновик')
            ->assertSee('Открыть настройки');

        $this->get(route('teams.edit', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Восстановление команды')
            ->assertSee('Восстановить команду');
    }
}
