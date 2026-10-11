<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppContextBarCompactTest extends TestCase
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

    public function test_compact_path_contains_all_breadcrumb_segments_with_noninteractive_current_page(): void
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('account.profile'))->assertOk()->getContent();
        $doc = new \DOMDocument;
        @$doc->loadHTML($html);
        $xpath = new \DOMXPath($doc);

        $this->assertSame(1, $xpath->query('//nav[@aria-label="Путь страницы"]/details/summary[@aria-label="Показать путь страницы"]')->length);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Навигационная цепочка"]')->length);
        $this->assertSame(3, $xpath->query('//nav[@aria-label="Путь страницы"]//ol/li')->length);
        $this->assertSame(2, $xpath->query('//nav[@aria-label="Путь страницы"]//ol/li/a')->length);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Путь страницы"]//ol/li[last()]/span[@aria-current="page" and text()="Профиль"]')->length);
        $this->assertSame(0, $xpath->query('//nav[@aria-label="Путь страницы"]//ol/li[last()]/a')->length);
    }

    public function test_back_and_actions_use_matching_icon_pills_with_accessible_labels(): void
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('account.roles'))->assertOk()->getContent();
        $doc = new \DOMDocument;
        @$doc->loadHTML($html);
        $xpath = new \DOMXPath($doc);
        $this->assertSame(1, $xpath->query('//button[contains(@class,"app-context-quick") and @data-app-context-back and @aria-label="Назад"]')->length);
        $this->assertSame(1, $xpath->query('//details[@data-app-context-actions]/summary[contains(@class,"app-context-quick") and @aria-label="Действия"]')->length);
        $this->assertSame(1, $xpath->query('//details[@data-app-context-actions]/div[contains(@class,"app-context-actions__menu")]')->length);
        // Existing actions and fallback navigation remain available.
        $this->assertSame(1, $xpath->query('//button[@data-app-context-share]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-context-help]')->length);
    }
}
