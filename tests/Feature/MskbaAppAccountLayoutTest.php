<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppAccountLayoutTest extends TestCase
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

    public function test_account_onboarding_uses_full_width_main_and_section_scoped_inner(): void
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('account.privacy.distribution'));

        $response->assertOk()
            ->assertSee('id="mskba-app" class="app-shell"', false)
            ->assertDontSee('id="mskba-app" class="app-shell container"', false)
            ->assertSee('class="app-account-section"', false)
            ->assertSee('class="container app-account-section__inner"', false)
            ->assertSee('data-privacy-onboarding-shell', false)
            ->assertDontSee('class="app-account-layout__aside"', false)
            ->assertDontSee('aria-label="Навигация аккаунта"', false)
            ->assertSee('Остался последний шаг регистрации')
            ->assertSee('Завершить регистрацию');

        $this->assertSame(1, substr_count($response->getContent(), '<main '));
    }

    public function test_other_pages_keep_section_level_container_without_main_container(): void
    {
        foreach (['login', 'register'] as $name) {
            $this->get(route($name))
                ->assertOk()
                ->assertSee('id="mskba-app" class="app-shell"', false)
                ->assertSee('class="container"', false)
                ->assertDontSee('id="mskba-app" class="app-shell container"', false);
        }
    }

    public function test_public_legal_document_stays_inside_its_own_section_container(): void
    {
        $this->get(route('personal-data.distribution-consent'))
            ->assertOk()
            ->assertSee('privacy-onboarding__legal')
            ->assertSee('class="container"', false)
            ->assertSee('6. Отдельность согласия');
    }
}
