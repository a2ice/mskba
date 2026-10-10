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

    public function test_account_heading_is_full_width_above_sidebar_and_profile_panels_share_one_row_wrapper(): void
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('account.profile'))->assertOk()->getContent();

        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " app-account-section__heading ")]//h1[text()="Профиль"]')->length);
        $this->assertSame(1, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " app-account-layout ")]/aside')->length);
        $headingPos = strpos($html, 'class="app-account-section__heading"');
        $sidebarPos = strpos($html, 'class="app-account-layout__aside"');
        $contentPos = strpos($html, 'class="app-account-layout__content"');
        $this->assertNotFalse($headingPos);
        $this->assertNotFalse($sidebarPos);
        $this->assertNotFalse($contentPos);
        $this->assertLessThan($sidebarPos, $headingPos);
        $this->assertLessThan($contentPos, $sidebarPos);

        $panels = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " app-profile__primary-grid ")]/section');
        $this->assertSame(2, $panels->length);
        $this->assertSame('app-profile-photo-heading', $panels->item(0)->getAttribute('aria-labelledby'));
        $this->assertSame('app-profile-address-heading', $panels->item(1)->getAttribute('aria-labelledby'));
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="app-profile-personal-heading"]')->length);
    }

    public function test_all_standard_account_headings_are_outside_sidebar_grid(): void
    {
        $user = User::factory()->create();
        foreach (['account', 'account.profile', 'account.roles', 'account.notifications', 'account.contacts', 'account.settings'] as $route) {
            $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();
            $this->assertStringContainsString('class="app-account-section__heading"', $html, $route);
            $this->assertLessThan(
                strpos($html, 'class="app-account-layout"'),
                strpos($html, 'class="app-account-section__heading"'),
                $route,
            );
        }
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
