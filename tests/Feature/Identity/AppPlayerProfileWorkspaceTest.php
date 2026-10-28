<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class AppPlayerProfileWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private string $oldTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldTheme = (string) config('themes.active');
        $this->theme('mskba_app');
    }

    protected function tearDown(): void
    {
        $this->theme($this->oldTheme);
        parent::tearDown();
    }

    private function theme(string $theme): void
    {
        config()->set('themes.active', $theme);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/'.$theme.'/views'));
    }

    private function player(UserGenderEnum $gender): User
    {
        $user = User::factory()->create();
        $user->profile()->create(['gender' => $gender]);
        $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);

        return $user;
    }

    public function test_app_player_has_two_tabs_and_gender_specific_character_fields(): void
    {
        $female = $this->player(UserGenderEnum::FEMALE);

        $this->actingAs($female)->get(route('account.participation-role', ['role' => 'player']))
            ->assertOk()
            ->assertSee('Физические данные')
            ->assertSee('Игровые характеристики')
            ->assertSee('data-player-preview', false)
            ->assertSee('body-female.png')
            ->assertSee('Выраженность жировой ткани')
            ->assertSee('character[chest_volume]', false)
            ->assertSee('type="hidden" name="character[facial_hair]"', false);

        $this->get(route('account.participation-role', ['role' => 'player', 'tab' => 'game']))
            ->assertOk()->assertSee('Самооценка игровых навыков')
            ->assertSee('Объективные игровые показатели')
            ->assertSee('data-player-skill', false);
    }

    public function test_saving_physical_and_game_tabs_never_clears_other_tab(): void
    {
        $user = $this->player(UserGenderEnum::FEMALE);
        $this->actingAs($user)->patch(route('account.player-profile.update'), [
            'section' => 'game',
            'experience_started_year' => now()->year - 15,
            'positions' => ['point_guard', 'center'],
            'self_assessment' => ['stamina' => 8, 'passing' => 7],
            'comment' => 'Центровая',
        ])->assertRedirect(route('account.participation-role', ['role' => 'player', 'tab' => 'game']))
          ->assertSessionHasNoErrors();

        $this->patch(route('account.player-profile.update'), [
            'section' => 'physical',
            'height_cm' => 182,
            'weight_kg' => 81,
            'body_type' => 'athletic',
            'character' => [
                'skin_tone' => 'warm',
                'hairstyle' => 'female_ponytail',
                'hair_color' => 'black',
                'facial_hair' => 'none',
                'uniform_kit' => 'mskba_home',
                'chest_volume' => 'large',
                'adiposity' => 'elevated',
            ],
        ])->assertRedirect(route('account.participation-role', ['role' => 'player', 'tab' => 'physical']))
          ->assertSessionHasNoErrors();

        $profile = $user->playerProfile()->with('positions', 'selfAssessment')->firstOrFail();
        $this->assertSame(182, $profile->height_cm);
        $this->assertSame('elevated', $profile->extra['character']['adiposity']);
        $this->assertSame('large', $profile->extra['character']['chest_volume']);
        $this->assertSame(now()->year - 15, $profile->experience_started_year);
        $this->assertSame('Центровая', $profile->comment);
        $this->assertCount(2, $profile->positions);
        $this->assertSame(8, $profile->selfAssessment->stamina);

        $this->patch(route('account.player-profile.update'), [
            'section' => 'game',
            'positions' => ['center'],
            'self_assessment' => ['stamina' => 9],
        ])->assertSessionHasNoErrors();

        $profile->refresh();
        $this->assertSame(182, $profile->height_cm);
        $this->assertSame('elevated', $profile->extra['character']['adiposity']);
        $this->assertCount(1, $profile->positions()->get());
        $this->assertSame(9, $profile->selfAssessment()->firstOrFail()->stamina);
    }

    public function test_unrecognized_body_fat_level_is_rejected(): void
    {
        $user = $this->player(UserGenderEnum::MALE);
        $this->actingAs($user)->patch(route('account.player-profile.update'), [
            'section' => 'physical',
            'character' => [
                'skin_tone' => 'warm',
                'hairstyle' => 'male_fade',
                'hair_color' => 'black',
                'facial_hair' => 'none',
                'uniform_kit' => 'mskba_home',
                'adiposity' => 'medical_obesity_diagnosis',
            ],
        ])->assertSessionHasErrors('character.adiposity');
    }

    public function test_other_roles_still_use_generic_settings_page(): void
    {
        $user = User::factory()->create();
        $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::COACH,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);

        $this->actingAs($user)->get(route('account.participation-role', ['role' => 'coach']))
            ->assertOk()->assertSee('Твоя роль')->assertDontSee('data-player-preview', false);
    }
}
