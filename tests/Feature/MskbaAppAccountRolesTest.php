<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppAccountRolesTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = (string) config('themes.active');
        $this->switchTheme('mskba_app');
    }

    protected function tearDown(): void
    {
        $this->switchTheme($this->previousTheme);
        parent::tearDown();
    }

    private function switchTheme(string $theme): void
    {
        config()->set('themes.active', $theme);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/'.$theme.'/views'));
    }

    private function user(bool $setupComplete = true): User
    {
        return User::factory()->create([
            'personal_data_distribution_required_at' => $setupComplete ? null : now(),
            'personal_data_distribution_setup_completed_at' => null,
        ]);
    }

    private function toggle(string $role, bool $enabled = true)
    {
        return $this->patchJson(route('account.roles.update-one', ['role' => $role]), [
            'enabled' => $enabled,
        ]);
    }

    public function test_roles_screen_shows_seven_instant_saving_switches_with_no_save_footer(): void
    {
        $user = $this->user();
        $response = $this->actingAs($user)->get(route('account.roles'))->assertOk()
            ->assertSee('Основные роли')
            ->assertSee('Другие направления')
            ->assertSee('Выбери, как ты хочешь участвовать в жизни MSKBA.')
            ->assertDontSee('Включай и отключай роли переключателями')
            ->assertSee('data-role-feedback', false)
            ->assertSee('data-role-editor', false)
            ->assertDontSee('Сохранить роли')
            ->assertDontSee('app-roles__footer')
            ->assertDontSee('View for page');

        foreach (UserParticipationRoleEnum::cases() as $role) {
            $response->assertSee($role->label())
                ->assertSee($role->description())
                ->assertSee('data-role-url="'.route('account.roles.update-one', ['role' => $role->value]).'"', false)
                ->assertSee('id="app-participation-role-'.$role->value.'"', false);
        }
        $this->assertSame(7, substr_count($response->getContent(), 'data-role-card'));
        $this->assertSame(7, substr_count($response->getContent(), 'data-role-loader'));
    }

    public function test_parameters_link_is_visible_only_for_active_roles_and_detail_pages_work(): void
    {
        $user = $this->user();
        $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $html = $this->actingAs($user)->get(route('account.roles'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-role-id="player"[\s\S]+?data-role-parameters\s*>[\s\S]*?href="'.preg_quote(route('account.participation-role', 'player'), '/').'"\s+class="app-roles__settings-link"/',
            $html,
        );
        $this->assertStringContainsString('aria-label="Параметры роли Игрок"', $html);
        $this->assertStringContainsString('title="Параметры роли Игрок"', $html);
        // The gear must not break the accepted switch-label input styling.
        $this->assertSame(7, substr_count($html, 'class="app-roles__control switch-label"'));
        $this->assertSame(7, substr_count($html, '<svg width="14" height="14"'));
        $this->assertStringContainsString('<svg width="14" height="14"', $html);
        $this->assertStringNotContainsString('class="app-roles__parameters-link"', $html);
        $this->assertMatchesRegularExpression(
            '/data-role-id="coach"[\s\S]+?data-role-parameters\s+hidden/',
            $html,
        );
        // The gear is outside the input labels, so following it must not toggle the switch.
        $this->assertMatchesRegularExpression(
            '/class="app-roles__heading"[\s\S]*?<\/label>\s*<span class="app-roles__actions"/',
            $html,
        );

        $this->get(route('account.participation-role', 'player'))
            ->assertOk()
            ->assertSee('Параметры: Игрок')
            ->assertSee('Индивидуальные настройки этой роли появятся здесь')
            ->assertSee('Вернуться к ролям')
            ->assertDontSee('View for page');

        $this->get(route('account.participation-role', 'coach'))->assertNotFound();
        $this->get(route('account.participation-role', 'not_a_role'))->assertNotFound();
    }

    public function test_single_role_ajax_toggle_returns_confirmed_state_and_preserves_system_role(): void
    {
        $user = $this->user();
        $systemRole = $user->system_role;

        $this->actingAs($user);
        $this->toggle('player')
            ->assertOk()
            ->assertJsonPath('role', 'player')
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('active_roles.0', 'player')
            ->assertJsonPath('retry_after', 5);

        $this->assertSame($systemRole, $user->fresh()->system_role);
        $this->assertDatabaseHas('user_participation_roles', [
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
        ]);
        $this->get(route('account.roles'))->assertOk()
            ->assertSee('Мой MSKBA')
            ->assertSee('Мои команды');
    }

    public function test_ajax_role_toggle_switches_roles_sidebar_between_direct_link_and_nested_group(): void
    {
        $this->actingAs($this->user());
        $xpathFor = static function (string $html): \DOMXPath {
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);

            return new \DOMXPath($dom);
        };
        $group = '//nav[@aria-label="Разделы аккаунта"]/descendant::details[summary/span[text()="Роли в проекте"]]';
        $directLink = '//nav[@aria-label="Разделы аккаунта"]/a[span[text()="Роли в проекте"]]';

        $before = $xpathFor($this->get(route('account.roles'))->assertOk()->getContent());
        $this->assertSame(0, $before->query($group)->length);
        $this->assertSame(2, $before->query($directLink)->length);

        $this->toggle('player')->assertOk()->assertJsonPath('enabled', true);
        $afterPlayer = $xpathFor($this->get(route('account.roles'))->assertOk()->getContent());
        $this->assertSame(2, $afterPlayer->query($group.'[@open]')->length);
        $this->assertSame(0, $afterPlayer->query($directLink)->length);
        $this->assertSame(2, $afterPlayer->query($group.'//a[span[text()="Все роли"]]')->length);
        $this->assertSame(2, $afterPlayer->query($group.'//a[span[text()="Игрок"]]')->length);

        $this->toggle('coach')->assertOk()->assertJsonPath('enabled', true);
        $afterCoach = $xpathFor($this->get(route('account.roles'))->assertOk()->getContent());
        $this->assertSame(2, $afterCoach->query($group.'//a[span[text()="Тренер"]]')->length);
        $this->travel(6)->seconds();
        $this->toggle('player', false)->assertOk()->assertJsonPath('enabled', false);
        $afterDisable = $xpathFor($this->get(route('account.roles'))->assertOk()->getContent());
        $this->assertSame(0, $afterDisable->query($group.'//a[span[text()="Игрок"]]')->length);
        $this->assertSame(2, $afterDisable->query($group.'//a[span[text()="Тренер"]]')->length);
        $this->travel(6)->seconds();
        $this->toggle('coach', false)->assertOk()->assertJsonPath('enabled', false);
        $afterLast = $xpathFor($this->get(route('account.roles'))->assertOk()->getContent());
        $this->assertSame(0, $afterLast->query($group)->length);
        $this->assertSame(2, $afterLast->query($directLink)->length);
    }

    public function test_cooldown_survives_reload_and_only_disables_the_modified_role(): void
    {
        $this->actingAs($this->user());
        $this->toggle('player')->assertOk();

        $html = $this->get(route('account.roles'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-role-id="player"\s+data-role-cooldown-seconds="5"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/id="app-participation-role-player"[^>]*\sdisabled\s/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/data-role-id="coach"\s+data-role-cooldown-seconds="0"/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="app-participation-role-coach"[^>]*\sdisabled\s/s',
            $html,
        );

        $this->travel(6)->seconds();

        $after = $this->get(route('account.roles'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/data-role-id="player"\s+data-role-cooldown-seconds="0"/',
            $after,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="app-participation-role-player"[^>]*\sdisabled\s/s',
            $after,
        );
    }

    public function test_separate_roles_can_be_enabled_without_five_second_global_delay(): void
    {
        $this->actingAs($this->user());
        $this->toggle('coach')->assertOk()->assertJsonPath('enabled', true);
        $this->toggle('media')->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonCount(2, 'active_roles');

        $this->get(route('account.roles'))->assertOk()
            ->assertSee('Параметры')
            ->assertSee('Мой MSKBA')
            ->assertSee('Тренер')
            ->assertSee('Медиа')
            ->assertDontSee('Параметры: Тренер')
            ->assertDontSee('Параметры: Медиа');
    }

    public function test_repeated_same_role_request_is_throttled_for_five_seconds_then_succeeds(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->toggle('player')->assertOk();
        $this->toggle('player', false)->assertStatus(429)
            ->assertJsonPath('retry_after', 5);
        $this->assertTrue($user->hasActiveRole('player'));

        $this->travel(6)->seconds();

        $this->toggle('player', false)->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonCount(0, 'active_roles');
        $this->assertFalse($user->hasActiveRole('player'));

        $this->travel(6)->seconds();

        $this->toggle('player')->assertOk()->assertJsonPath('enabled', true);
        $this->assertSame(1, $user->participationRoles(false)->count());
    }

    public function test_invalid_ajax_inputs_are_rejected_without_mutations(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->toggle('not-existing-role')->assertNotFound();
        $this->patchJson(route('account.roles.update-one', 'coach'), [
            'enabled' => 'not-a-boolean',
        ])->assertUnprocessable()->assertJsonValidationErrors('enabled');

        $this->patch(route('account.roles.update-one', 'coach'), [
            'enabled' => 1,
        ])->assertStatus(406);

        $this->assertSame(0, $user->participationRoles(false)->count());
    }

    public function test_pending_onboarding_blocks_ajax_mutation_and_guest_requires_login(): void
    {
        $user = $this->user(false);

        $this->actingAs($user)->get(route('account.roles'))->assertOk()
            ->assertSee('Сначала заверши регистрацию');

        $this->toggle('player')
            ->assertStatus(409)
            ->assertJsonPath('code', 'ONBOARDING_REQUIRED');

        $this->assertSame(0, $user->participationRoles(false)->count());

        auth()->logout();
        $this->toggle('player')->assertUnauthorized();
    }

    public function test_legacy_full_update_form_and_route_remain_available(): void
    {
        $this->switchTheme('mskba_dark');
        $user = $this->user();
        $this->actingAs($user)->get(route('account.roles'))->assertOk()
            ->assertSee('account-role-grid')
            ->assertSee('Сохранить роли');

        $this->toggle('player')->assertNotFound();
        $this->assertSame(0, $user->participationRoles(false)->count());
    }
}
