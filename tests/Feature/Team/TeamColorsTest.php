<?php

namespace Tests\Feature\Team;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamColorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_store_partial_and_clear_team_colors(): void
    {
        $owner = User::factory()->create([
            'username' => 'team-colors-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда цветов',
            'sport_types' => ['basketball'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Команда цветов')->firstOrFail();

        $this->patch(route('teams.settings.colors.update', $team->routeIdentifier()), [
            'colors' => [
                'home_primary' => '#FF6600',
                'home_secondary' => '#101010',
                'away_primary' => '#FFFFFF',
                'away_secondary' => '#3366CC',
            ],
        ])->assertSessionHas('status')->assertSessionHasNoErrors();

        $this->assertSame([
            'home_primary' => '#ff6600',
            'home_secondary' => '#101010',
            'away_primary' => '#ffffff',
            'away_secondary' => '#3366cc',
        ], $team->fresh()->colors);

        $this->patch(route('teams.settings.colors.update', $team->routeIdentifier()), [
            'colors' => [
                'home_primary' => '#112233',
                'home_secondary' => null,
                'away_primary' => null,
                'away_secondary' => null,
            ],
        ])->assertSessionHas('status')->assertSessionHasNoErrors();

        $this->assertSame(['home_primary' => '#112233'], $team->fresh()->colors);

        $this->patch(route('teams.settings.colors.update', $team->routeIdentifier()), [
            'colors' => [
                'home_primary' => null,
                'home_secondary' => null,
                'away_primary' => null,
                'away_secondary' => null,
            ],
        ])->assertSessionHas('status')->assertSessionHasNoErrors();

        $this->assertNull($team->fresh()->colors);
    }

    public function test_invalid_team_color_is_rejected_without_overwriting_existing_colors(): void
    {
        $owner = User::factory()->create([
            'username' => 'team-colors-validation-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда проверки цветов',
            'sport_types' => ['basketball'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Команда проверки цветов')->firstOrFail();
        $team->update(['colors' => ['home_primary' => '#123456']]);

        $this->patch(route('teams.settings.colors.update', $team->routeIdentifier()), [
            'colors' => [
                'home_primary' => '#12345',
            ],
        ])->assertSessionHasErrors('colors.home_primary');

        $this->assertSame(['home_primary' => '#123456'], $team->fresh()->colors);
    }

    public function test_team_colors_edit_uses_compact_controls_and_safe_home_gradient(): void
    {
        $owner = User::factory()->create([
            'username' => 'team-colors-ui-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда цветного фона',
            'sport_types' => ['basketball'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Команда цветного фона')->firstOrFail();
        $team->update([
            'colors' => [
                'home_primary' => '#ffffff',
                'home_secondary' => '#ff6600',
            ],
        ]);

        $this->get(route('teams.edit', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('team-color-picker', false)
            ->assertSee('team-color-reset', false)
            ->assertSee('Применить')
            ->assertDontSee('Сохранить цвета')
            ->assertSee('linear-gradient(180deg, var(--page) 0 110px, transparent 170px)', false)
            ->assertSee('linear-gradient(90deg, #ffffff, #ff6600)', false);
    }
}
