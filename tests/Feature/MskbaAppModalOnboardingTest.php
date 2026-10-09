<?php

namespace Tests\Feature;

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppModalOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->previousTheme);
        View::replaceNamespace('theme', resource_path('themes/'.$this->previousTheme.'/views'));
        app()->forgetInstance(ThemeResolver::class);
        parent::tearDown();
    }

    private function pendingUser(): User
    {
        return User::factory()->create([
            'username' => 'modal_onboarding_qa',
            'password' => 'password',
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => null,
        ]);
    }

    public function test_incomplete_account_can_browse_account_with_modal_and_open_other_pages(): void
    {
        $user = $this->pendingUser();
        $this->actingAs($user)->get(route('account'))
            ->assertOk()
            ->assertSee('data-onboarding-pending', false)
            ->assertSee('data-auto-show="1"', false)
            ->assertSee('data-open-onboarding', false);

        // Any account section offers setup on a fresh visit; public GETs do not.
        foreach (['account.profile', 'account.wallet', 'account.roles', 'account.notifications', 'account.settings'] as $name) {
            $this->get(route($name))->assertOk()
                ->assertSee('data-auto-show="1"', false);
        }

        // Direct privacy setup must not open its own modal above itself.
        $this->withSession(['onboarding_required' => true])
            ->get(route('account.privacy.distribution'))->assertOk()
            ->assertSee('data-auto-show="0"', false)
            ->assertSee('data-force-show="0"', false);
        $this->get(route('welcome'))->assertOk()
            ->assertSee('data-auto-show="0"', false);
        $this->get(route('personal-data.consent'))->assertOk();
    }

    public function test_incomplete_account_cannot_mutate_even_after_closing_the_modal(): void
    {
        $user = $this->pendingUser();
        $this->actingAs($user)
            ->patch(route('account.nickname.update'), ['nickname' => 'should-not-change'])
            ->assertStatus(303)
            ->assertRedirect(route('account'))
            ->assertSessionHas('onboarding_required');

        $this->patchJson(route('account.nickname.update'), ['nickname' => 'should-not-change'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ONBOARDING_REQUIRED')
            ->assertJsonPath('redirect_url', route('account.privacy.distribution'));
        $this->assertNotSame('should-not-change', $user->fresh()->nickname);
    }

    public function test_login_and_registration_return_to_account_in_app_theme(): void
    {
        $user = $this->pendingUser();

        $this->postJson(route('auth.login'), [
            'login' => $user->username,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('redirect_url', route('account'));

        $this->post(route('auth.logout'))->assertRedirect('/');
        $this->assertGuest();

        $this->postJson(route('auth.register'), [
            'username' => 'new_modal_app_user',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'privacy_consent' => '1',
        ])->assertCreated()->assertJsonPath('redirect_url', route('account'));

        $this->get(route('account'))->assertOk()
            ->assertSee('data-onboarding-pending', false);
    }

    public function test_fragment_contains_original_form_and_verified_contacts_but_no_real_verification_action(): void
    {
        $user = $this->pendingUser();
        Contact::query()->create([
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => 'email',
            'value' => 'approved@example.test',
            'is_primary' => true,
            'is_public' => false,
            'verified_at' => now(),
        ]);
        Contact::query()->create([
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => 'telegram',
            'value' => 'unverified_channel',
            'verified_at' => null,
        ]);

        $fragment = $this->actingAs($user)
            ->get(route('account.privacy.distribution', ['modal' => 1]))
            ->assertOk()
            ->assertSee('data-privacy-distribution', false)
            ->assertSee('Видимость профиля')
            ->assertSee('Отправлять уведомления на')
            ->assertSee('approved@example.test')
            ->assertSee('Добавить Telegram')
            ->assertSee('Добавить VK')
            ->assertDontSee('unverified_channel')
            ->getContent();

        $this->assertStringNotContainsString('data-onboarding-pending', $fragment);
        $this->assertStringNotContainsString('name="notification[', $fragment);
        $this->get(route('account.privacy.distribution'))->assertOk()
            ->assertSee('Отправлять уведомления на')
            ->assertSee('data-privacy-distribution', false);
    }

    public function test_contact_workspace_starts_hidden_and_exposes_accessible_tabs_without_extra_registration_actions(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($user)
            ->get(route('account.privacy.distribution', ['modal' => 1]))
            ->assertOk()
            ->assertSee('data-notification-workspace hidden', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('role="tabpanel"', false)
            ->assertSee('data-notification-tab="telegram" hidden', false)
            ->assertSee('data-notification-close="vk"', false)
            ->assertSee('class="field-group"', false)
            ->assertSee('Завершить регистрацию')
            ->assertDontSee('Оставить всё закрытым')
            ->assertDontSee('Продолжить позже');
    }

    public function test_json_onboarding_save_completes_setup_and_lifts_mutation_guard(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($user)->putJson(route('account.privacy.distribution.update'), [
            'action' => 'private',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('completed', true);

        $this->assertNotNull($user->fresh()->personal_data_distribution_setup_completed_at);
        $this->patchJson(route('account.nickname.update'), ['nickname' => 'now_allowed'])
            ->assertStatus(200);
    }

    public function test_separate_consent_remains_required_for_public_profile_in_modal(): void
    {
        $user = $this->pendingUser();
        $this->actingAs($user)->putJson(route('account.privacy.distribution.update'), [
            'action' => 'save',
            'privacy_hierarchy' => '1',
            'public' => ['profile' => '1'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['distribution_consent']);

        $this->assertNull($user->fresh()->personal_data_distribution_setup_completed_at);
    }

    public function test_logout_is_possible_before_finishing(): void
    {
        $this->actingAs($this->pendingUser())
            ->post(route('auth.logout'))
            ->assertRedirect('/');
        $this->assertGuest();
    }
}
