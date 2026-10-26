<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
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

    public function test_venue_detail_breadcrumb_links_to_canonical_venues_catalog(): void
    {
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'name' => 'Школа для проверки навигации',
        ]);

        $html = $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);

        foreach (['Навигационная цепочка', 'Путь страницы'] as $label) {
            $nodes = $xpath->query('//nav[@aria-label="'.$label.'"]//a[text()="Площадки"]');
            $this->assertSame(1, $nodes->length, $label);
            $this->assertSame(url('/venues'), $nodes->item(0)->getAttribute('href'), $label);
        }
        $this->assertStringNotContainsString('href="'.url('/venues/search').'">Площадки</a>', $html);
    }

    public function test_catalog_sidebar_sticky_style_tracks_shared_header_offset(): void
    {
        $css = file_get_contents(resource_path('themes/mskba_app/css/catalog-sidebar-sticky.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString('.mskba-page-builder .mskba-catalog-sidebar', $css);
        $this->assertStringContainsString('--app-sticky-offset', $css);
        $this->assertStringContainsString('+ 15px', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString(
            "@import './catalog-sidebar-sticky.css';",
            file_get_contents(resource_path('themes/mskba_app/css/app.css')),
        );
    }

    public function test_compact_path_contains_all_breadcrumb_segments_with_noninteractive_current_page(): void
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('account.profile'))->assertOk()->getContent();
        $doc = new \DOMDocument;
        @$doc->loadHTML($html);
        $xpath = new \DOMXPath($doc);

        $this->assertSame(1, $xpath->query('//nav[@aria-label="Путь страницы"]/details/summary[@aria-label="Показать путь страницы"]')->length);
        $this->assertSame(0, $xpath->query('//nav[@aria-label="Путь страницы"]//summary/span[contains(@class,"app-context-quick__label")]')->length);
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

    public function test_confirmation_action_sits_between_contextual_and_system_actions(): void
    {
        $user = User::factory()->create([
            'status' => \App\Modules\Identity\Domain\Enums\UserStatusEnum::UNCONFIRMED,
        ]);

        // Events have a contextual "Играть" action followed by the global links.
        $html = $this->actingAs($user)->get(route('events.index'))->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $menu = $xpath->query('//div[contains(@class, "app-context-actions__menu")]')->item(0);
        $this->assertNotNull($menu);

        $elements = [];
        foreach ($menu->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        $this->assertCount(6, $elements);
        $this->assertSame('Играть', trim($elements[0]->textContent));
        $this->assertSame('app-context-actions__separator', $elements[1]->getAttribute('class'));
        $this->assertSame('app-context-actions__verify', $elements[2]->getAttribute('class'));
        $this->assertSame('Подтвердить акк...', trim($elements[2]->textContent));
        $this->assertSame('app-context-actions__separator', $elements[3]->getAttribute('class'));
        $this->assertTrue($elements[4]->hasAttribute('data-app-context-share'));
        $this->assertTrue($elements[5]->hasAttribute('data-context-help'));
    }

    public function test_confirmation_is_first_when_there_are_no_contextual_actions(): void
    {
        $user = User::factory()->create([
            'status' => \App\Modules\Identity\Domain\Enums\UserStatusEnum::UNCONFIRMED,
        ]);
        $html = $this->actingAs($user)->get(route('account.roles'))->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $menu = $xpath->query('//div[contains(@class, "app-context-actions__menu")]')->item(0);
        $this->assertNotNull($menu);

        $elements = [];
        foreach ($menu->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        $this->assertCount(4, $elements);
        $this->assertSame('app-context-actions__verify', $elements[0]->getAttribute('class'));
        $this->assertSame('app-context-actions__separator', $elements[1]->getAttribute('class'));
        $this->assertTrue($elements[2]->hasAttribute('data-app-context-share'));
        $this->assertTrue($elements[3]->hasAttribute('data-context-help'));
    }

    public function test_unconfirmed_member_sees_confirmation_action_across_the_portal(): void
    {
        $user = User::factory()->create(['status' => \App\Modules\Identity\Domain\Enums\UserStatusEnum::UNCONFIRMED]);

        foreach (['account', 'account.roles', 'events.index', 'venues', 'news.index'] as $name) {
            $html = $this->actingAs($user)->get(route($name))->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'Подтвердить акк...'), $name);
            $this->assertSame(2, substr_count($html, 'class="app-context-actions__attention"'), $name);
            $this->assertStringContainsString('href="'.route('account.confirmation').'"', $html, $name);
        }
    }

    public function test_confirmation_page_has_no_confirmation_action_or_indicator(): void
    {
        $user = User::factory()->create(['status' => \App\Modules\Identity\Domain\Enums\UserStatusEnum::UNCONFIRMED]);
        $html = $this->actingAs($user)->get(route('account.confirmation'))->assertOk()->getContent();
        $this->assertStringContainsString('data-app-context-actions', $html);
        $this->assertStringNotContainsString('Подтвердить акк...', $html);
        $this->assertStringNotContainsString('class="app-context-actions__attention"', $html);
    }

    public function test_home_keeps_clean_layout_except_for_unconfirmed_member_actions(): void
    {
        $guest = $this->get(route('welcome'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-app-context-bar', $guest);

        $confirmed = User::factory()->create(['status' => \App\Modules\Identity\Domain\Enums\UserStatusEnum::CONFIRMED]);
        $confirmedHtml = $this->actingAs($confirmed)->get(route('welcome'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-app-context-bar', $confirmedHtml);

        $unconfirmed = User::factory()->create(['status' => \App\Modules\Identity\Domain\Enums\UserStatusEnum::UNCONFIRMED]);
        $html = $this->actingAs($unconfirmed)->get(route('welcome'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-app-context-bar'));
        $this->assertSame(1, substr_count($html, 'Подтвердить акк...'));
        $this->assertSame(2, substr_count($html, 'class="app-context-actions__attention"'));
        $this->assertStringNotContainsString('data-app-context-back', $html);
        $this->assertStringNotContainsString('aria-label="Навигационная цепочка"', $html);
    }

    public function test_confirmed_user_and_guest_have_no_confirmation_action(): void
    {
        $guestHtml = $this->get(route('news.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Подтвердить акк...', $guestHtml);

        $confirmed = User::factory()->create(['status' => \App\Modules\Identity\Domain\Enums\UserStatusEnum::CONFIRMED]);
        $html = $this->actingAs($confirmed)->get(route('events.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Подтвердить акк...', $html);
        $this->assertStringNotContainsString('class="app-context-actions__attention"', $html);
    }

}
