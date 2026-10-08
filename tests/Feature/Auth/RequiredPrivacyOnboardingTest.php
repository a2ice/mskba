<?php

namespace Tests\Feature\Auth;

use App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RequiredPrivacyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function pendingUser(): User
    {
        return User::factory()->create([
            'password' => 'password',
            'username' => 'incomplete_setup_user',
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => null,
        ]);
    }

    public function test_pending_user_is_redirected_from_protected_portal_pages_but_can_open_privacy_and_legal_pages(): void
    {
        $user = $this->pendingUser();
        $this->actingAs($user)
            ->get(route('account'))
            ->assertStatus(303)
            ->assertRedirect(route('account.privacy.distribution'));

        $this->getJson(route('account.notifications'))
            ->assertStatus(409)
            ->assertJsonPath('redirect_url', route('account.privacy.distribution'));

        $this->get(route('account.privacy.distribution'))
            ->assertOk()
            ->assertSee('Настройка публичности');

        $this->get(route('personal-data.distribution-consent'))->assertOk();
        $this->get(route('welcome'))->assertOk();
    }

    public function test_pending_user_cannot_mutate_account_via_html_or_json(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($user)
            ->patch(route('account.nickname.update'), ['nickname' => 'should-not-change'])
            ->assertStatus(303)
            ->assertRedirect(route('account.privacy.distribution'));

        $this->patchJson(route('account.nickname.update'), ['nickname' => 'should-not-change'])
            ->assertStatus(409)
            ->assertJsonPath('redirect_url', route('account.privacy.distribution'))
            ->assertJsonPath('message', 'Сначала завершите настройку приватности аккаунта.');

        $this->assertNotSame('should-not-change', $user->fresh()->nickname);
    }

    public function test_pending_user_can_logout_without_redirect_loop(): void
    {
        $this->actingAs($this->pendingUser())
            ->post(route('auth.logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_pending_user_login_resumes_privacy_page_and_preserves_original_target(): void
    {
        $user = $this->pendingUser();
        $target = route('account.notifications');

        $this->withSession(['url.intended' => $target])
            ->post(route('auth.login'), ['login' => $user->username, 'password' => 'password'])
            ->assertRedirect(route('account.privacy.distribution'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame($target, session('privacy.distribution.return_to'));

        $this->get($target)->assertRedirect(route('account.privacy.distribution'));

        $this->put(route('account.privacy.distribution.update'), [
            'action' => 'private',
        ])->assertRedirect($target);

        $this->assertNotNull($user->fresh()->personal_data_distribution_setup_completed_at);
        $this->get($target)->assertOk();
    }

    public function test_json_login_also_returns_onboarding_url(): void
    {
        $user = $this->pendingUser();

        $this->postJson(route('auth.login'), [
            'login' => $user->username,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('redirect_url', route('account.privacy.distribution'));

        $this->assertSame(route('account'), session('privacy.distribution.return_to'));
    }

    public function test_legacy_and_completed_users_are_not_redirected(): void
    {
        $legacy = User::factory()->create([
            'personal_data_distribution_required_at' => null,
        ]);
        $this->actingAs($legacy)->get(route('account'))->assertOk();

        $completed = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => now(),
        ]);
        $this->actingAs($completed)->get(route('account'))->assertOk();
    }

    public function test_new_account_has_no_prechecked_distribution_types(): void
    {
        $user = $this->pendingUser();

        $this->assertSame([], app(PersonalDataDistributionConsentService::class)->selectedForForm($user));
        $response = $this->actingAs($user)->get(route('account.privacy.distribution'));

        $response->assertOk()
            ->assertSee('name="public[profile]"', false)
            ->assertDontSee('name="public[profile]" value="1" checked', false);
    }

    public function test_public_profile_requires_explicit_separate_consent_and_closed_profile_does_not(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($user)
            ->put(route('account.privacy.distribution.update'), [
                'action' => 'save',
                'public' => ['profile' => '1'],
            ])->assertSessionHasErrors('distribution_consent');

        $this->assertNull($user->fresh()->personal_data_distribution_setup_completed_at);

        $this->put(route('account.privacy.distribution.update'), [
            'action' => 'save',
            'public' => [],
        ])->assertRedirect(route('account'));

        $this->assertNotNull($user->fresh()->personal_data_distribution_setup_completed_at);
    }
}
