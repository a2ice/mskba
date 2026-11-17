<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class PlayerConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private string $oldTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->oldTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        app()->forgetInstance(ThemeResolver::class);
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->oldTheme);
        View::replaceNamespace('theme', resource_path('themes/'.$this->oldTheme.'/views'));
        app()->forgetInstance(ThemeResolver::class);
        parent::tearDown();
    }

    private function player(bool $filled = true, UserSystemRoleEnum $systemRole = UserSystemRoleEnum::USER): User
    {
        $user = User::factory()->create([
            'system_role' => $systemRole,
            'status' => $systemRole === UserSystemRoleEnum::SUPERADMIN
                ? UserStatusEnum::CONFIRMED : UserStatusEnum::UNCONFIRMED,
        ]);
        $user->createProfile([
            'first_name' => 'Игрок',
            'last_name' => 'Тестовый',
            'birth_date' => '1994-02-17',
            'gender' => 'male',
        ]);
        $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        if ($filled) {
            $user->playerProfile()->create([
                'height_cm' => 190,
                'weight_kg' => 85,
                'body_type' => 'normal',
                'experience_started_year' => now()->year - 18,
            ]);
        }

        return $user;
    }

    public function test_confirmation_requires_saved_values_and_remains_independent_of_account_confirmation(): void
    {
        $user = $this->player(false);
        $this->actingAs($user)->post(route('account.player.confirm'))->assertStatus(422);
        $this->assertNull($user->participationRoles(false)->firstOrFail()->fresh()->confirmed_at);

        $this->patch(route('account.player-profile.update'), [
            'section' => 'physical',
            'height_cm' => 191,
            'weight_kg' => 88,
        ])->assertSessionHasNoErrors();
        $this->post(route('account.player.confirm'))->assertStatus(422);

        $this->patch(route('account.player-profile.update'), [
            'section' => 'game', 'experience_started_year' => now()->year - 16,
        ])->assertSessionHasNoErrors();
        $this->post(route('account.player.confirm'))
            ->assertRedirect(route('account.participation-role', ['role' => 'player']));

        $this->assertNotNull($user->participationRoles(false)->firstOrFail()->fresh()->confirmed_at);
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->fresh()->status);
        $this->post(route('account.player.confirm'))->assertStatus(422);
    }

    public function test_confirmed_player_cannot_directly_modify_height_or_started_year_but_can_modify_weight_and_physique(): void
    {
        $user = $this->player();
        $this->actingAs($user)->post(route('account.player.confirm'))->assertRedirect();

        $this->patch(route('account.player-profile.update'), [
            'section' => 'physical', 'height_cm' => 201, 'weight_kg' => 89,
        ])->assertForbidden();

        $this->assertSame(190, $user->playerProfile->fresh()->height_cm);
        $this->assertSame('85.0', $user->playerProfile->fresh()->weight_kg);

        $this->patch(route('account.player-profile.update'), [
            'section' => 'game', 'experience_started_year' => now()->year - 15,
        ])->assertForbidden();

        $this->patch(route('account.player-profile.update'), [
            'section' => 'physical', 'weight_kg' => 90, 'body_type' => 'athletic',
        ])->assertSessionHasNoErrors();

        $profile = $user->playerProfile->fresh();
        $this->assertSame(190, $profile->height_cm);
        $this->assertSame(now()->year - 18, $profile->experience_started_year);
        $this->assertSame('90.0', $profile->weight_kg);
        $this->assertSame('athletic', $profile->body_type->value);
    }

    public function test_player_and_account_change_requests_are_independent_and_player_changes_apply_only_after_review(): void
    {
        $user = $this->player();
        $admin = $this->player(true, UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($user)->post(route('account.player.confirm'))->assertRedirect();
        $this->post(route('account.profile-change-requests.store'), [
            'scope' => 'player', 'height_cm' => 195,
            'experience_started_year' => now()->year - 17,
            'reason' => 'Исправляю ошибочно введённые данные игрока.',
        ])->assertRedirect(route('account.participation-role', ['role' => 'player']));

        $change = ProfileChangeRequest::query()->sole();
        $this->assertSame(ProfileChangeRequest::SCOPE_PLAYER, $change->scope);
        $this->assertSame(190, $user->playerProfile->fresh()->height_cm);

        $this->post(route('account.profile-change-requests.store'), [
            'scope' => 'player', 'height_cm' => 200,
            'experience_started_year' => now()->year - 16,
            'reason' => 'Повторно меняю те же данные до рассмотрения.',
        ])->assertSessionHasErrors('reason');

        $user->forceFill(['status' => UserStatusEnum::CONFIRMED])->save();
        $this->post(route('account.profile-change-requests.store'), [
            'first_name' => 'Новое имя', 'last_name' => 'Тестовый',
            'birth_date' => '1994-02-17', 'gender' => 'male',
            'reason' => 'Исправляю ошибочно записанное имя пользователя.',
        ])->assertRedirect(route('account.profile'));
        $this->assertSame(2, ProfileChangeRequest::query()->count());

        $this->actingAs($admin)->post(route('admin.profile-change-requests.review', $change), [
            'action' => 'approve',
        ])->assertRedirect(route('admin.profile-change-requests'));

        $this->assertSame(ProfileChangeRequest::APPROVED, $change->fresh()->status);
        $this->assertSame(195, $user->playerProfile->fresh()->height_cm);
        $this->assertSame(now()->year - 17, $user->playerProfile->fresh()->experience_started_year);
    }

    public function test_player_and_account_confirmation_dialogs_are_available_in_app_theme(): void
    {
        $user = $this->player();
        $this->actingAs($user)->get(route('account.participation-role', ['role' => 'player']))
            ->assertOk()
            ->assertSee('form="player-confirmation-form"', false)
            ->assertSee('>Подтвердить</button>', false)
            ->assertSee('data-confirm-missing-target="player-confirmation-form"', false)
            ->assertSee('data-confirm-review-target="player-confirmation-form"', false)
            ->assertDontSee('Статус игрока');

        $this->post(route('account.player.confirm'))->assertRedirect();
        $this->get(route('account.participation-role', ['role' => 'player', 'tab' => 'physical']))
            ->assertOk()
            ->assertSee('Подтверждён')
            ->assertSee('data-player-change-request-open', false)
            ->assertDontSee('form="player-confirmation-form"', false)
            ->assertSee('id="player-height"', false);
        $this->get(route('account.participation-role', ['role' => 'player', 'tab' => 'game']))
            ->assertOk()
            ->assertSee('name="experience_started_year"', false);

        $this->get(route('account.confirmation'))->assertOk();
    }
    public function test_confirmation_action_stays_available_for_unfilled_player_with_missing_data_dialog(): void
    {
        $user = $this->player(false);
        $this->actingAs($user)->get(route('account.participation-role', ['role' => 'player', 'tab' => 'physical']))
            ->assertOk()
            ->assertSee('form="player-confirmation-form"', false)
            ->assertSee('data-confirm-requirement', false)
            ->assertSee('data-confirm-label="Рост"', false)
            ->assertSee('data-confirm-label="Играю с"', false)
            ->assertSee('data-confirm-missing-list', false)
            ->assertDontSee('Статус игрока');

        $this->get(route('account.participation-role', ['role' => 'player', 'tab' => 'game']))
            ->assertOk()
            ->assertSee('id="player-experience-started-year"', false);
    }

}
