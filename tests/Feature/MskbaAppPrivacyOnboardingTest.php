<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserConsent;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppPrivacyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousTheme = config('themes.active');
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

    public function test_linked_legal_distribution_document_is_readable_in_new_theme(): void
    {
        $this->get(route('personal-data.distribution-consent'))
            ->assertOk()
            ->assertSee('разрешённых для распространения')
            ->assertSee('6. Отдельность согласия')
            ->assertDontSee('MSKBA App theme');
    }

    public function test_onboarding_renders_real_page_with_all_existing_distribution_options(): void
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('account.privacy.distribution'));

        $response->assertOk();
        $response->assertSee('Настройка приватности');
        $response->assertSee('Видимость профиля');
        $response->assertSee('Видимость в поиске');
        $response->assertSee('Остался последний шаг регистрации');
        $response->assertSee('name="public[profile]"', false);
        $response->assertSee('name="public[profile_gender]"', false);
        $response->assertSee('name="public[profile_age]"', false);
        $response->assertSee('name="privacy_options[messages]"', false);
        $response->assertSee('name="privacy_options[group_invitations]"', false);
        $response->assertSee('name="distribution_consent"', false);
        $response->assertSee('Завершить регистрацию');
        $response->assertSee('Оставить всё закрытым');
        $response->assertDontSee('MSKBA App theme');
    }

    public function test_public_selection_requires_separate_consent_and_preserves_input(): void
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('account.privacy.distribution'))
            ->put(route('account.privacy.distribution.update'), [
                'action' => 'save',
                'public' => ['profile' => '1'],
            ])
            ->assertRedirect(route('account.privacy.distribution'))
            ->assertSessionHasErrors('distribution_consent');

        $this->get(route('account.privacy.distribution'))
            ->assertOk()
            ->assertSee('1 выбрано')
            ->assertSee('name="public[profile]"', false);
        $this->assertDatabaseMissing('user_consents', [
            'user_id' => $user->id,
            'type' => UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION,
        ]);
    }

    public function test_saving_all_disabled_toggles_does_not_require_separate_consent(): void
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
        ]);

        $this->actingAs($user)
            ->put(route('account.privacy.distribution.update'), [
                'action' => 'save',
                'public' => [],
            ])
            ->assertRedirect(route('account'));

        $this->assertNotNull($user->fresh()->personal_data_distribution_setup_completed_at);
        $this->assertDatabaseMissing('user_consents', [
            'user_id' => $user->id,
            'type' => UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION,
        ]);
    }

    public function test_private_choice_with_nonempty_toggles_still_completes_without_consent(): void
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
        ]);

        $this->actingAs($user)
            ->put(route('account.privacy.distribution.update'), [
                'action' => 'private',
                'public' => ['profile' => '1', 'avatar' => '1'],
            ])
            ->assertRedirect(route('account'));

        $this->assertNotNull($user->fresh()->personal_data_distribution_setup_completed_at);
        $this->assertDatabaseMissing('user_consents', [
            'user_id' => $user->id,
            'type' => UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION,
        ]);
    }

    public function test_public_selection_with_consent_saves_and_redirects(): void
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
        ]);

        $this->actingAs($user)
            ->put(route('account.privacy.distribution.update'), [
                'action' => 'save',
                'public' => ['profile' => '1', 'avatar' => '1'],
                'distribution_consent' => '1',
            ])
            ->assertRedirect(route('account'));

        $consent = $user->consents()
            ->where('type', UserConsent::TYPE_PERSONAL_DATA_DISTRIBUTION)
            ->whereNull('revoked_at')
            ->sole();
        $this->assertSame(['profile', 'avatar'], $consent->payload['allowed_types']);
    }

    public function test_finished_setup_does_not_show_onboarding_again(): void
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => now(),
        ]);
        $this->actingAs($user)->get(route('account.privacy.distribution'))
            ->assertRedirect(route('account.settings'));
    }
}
