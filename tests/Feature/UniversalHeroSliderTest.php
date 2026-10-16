<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\HeroSliderSettings;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderRenderer;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UniversalHeroSliderTest extends TestCase
{
    use RefreshDatabase;

    private function slide(string $name, array $audience = [], array $layers = []): array
    {
        return [
            'layout' => 'layers',
            'name' => $name,
            'enabled' => true,
            'audience' => $audience,
            'elements' => $layers ?: [
                ['type' => 'heading', 'name' => 'Heading', 'content' => $name,
                    'animation' => 'slide-up', 'delay_ms' => 120, 'duration_ms' => 900,
                    'x' => 7, 'y' => 15, 'width' => 70, 'mobile_x' => 5, 'mobile_y' => 20,
                    'mobile_width' => 90, 'z_index' => 4],
                ['type' => 'button', 'name' => 'Join', 'content' => 'Участвовать',
                    'url' => '/events', 'animation' => 'fade', 'delay_ms' => 400],
            ],
        ];
    }

    private function render(array $slides, bool $preview = false): string
    {
        $publisher = app(PageBuilderPublisher::class);
        $slider = PageBuilderComponent::query()->where('slug', 'hero-slider')->firstOrFail();
        $slider->update(['draft_slides' => $slides]);
        $publisher->publish($slider);

        $page = PageBuilderPage::query()->where('path', '/')->firstOrFail();
        $page->update(['draft_html' => '<mskba-component name="hero-slider"></mskba-component>']);
        $publisher->publish($page);

        return app(PageBuilderRenderer::class)->render($page->refresh(), $preview)['html'];
    }

    public function test_new_generic_layers_render_with_escaped_content_and_bounded_coordinates(): void
    {
        $slide = $this->slide('Новый формат');
        $slide['elements'][0]['content'] = '<img src=x onerror=alert(1)> Безопасный текст';
        $slide['elements'][0]['class_name'] = 'bg-blue my-heading';
        $slide['elements'][] = [
            'type' => 'image', 'name' => 'Photo', 'image' => 'page-builder/hero/abcdef.webp',
            'alt' => 'Мяч', 'animation' => 'zoom', 'delay_ms' => 500,
        ];
        $html = $this->render([$slide]);
        $this->assertStringContainsString('mskba-hero-slide--layers', $html);
        $this->assertStringContainsString('data-layer-name="Heading"', $html);
        $this->assertStringContainsString('bg-blue my-heading', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('--layer-x:7%', $html);
        $this->assertStringContainsString('page-builder/hero/abcdef.webp', $html);
        $this->assertStringContainsString('href="/events"', $html);
    }


    public function test_tablet_positions_are_independent_and_fall_back_to_desktop_when_omitted(): void
    {
        $slide = $this->slide('Tablet');
        $slide['elements'][0]['tablet_x'] = 8;
        $slide['elements'][0]['tablet_y'] = 21;
        $slide['elements'][0]['tablet_width'] = 85;
        HeroSliderSettings::validate([$slide], []);

        $html = $this->render([$slide]);
        $this->assertStringContainsString('--layer-x:7%;--layer-y:15%;--layer-width:70%;', $html);
        $this->assertStringContainsString('--layer-tablet-x:8%;--layer-tablet-y:21%;--layer-tablet-width:85%;', $html);
        $this->assertStringContainsString('--layer-mobile-x:5%;--layer-mobile-y:20%;--layer-mobile-width:90%;', $html);
        // The second existing element has no tablet settings: it inherits
        // its normalized desktop coordinates (10%, 20%, 50%).
        $this->assertStringContainsString('--layer-tablet-x:10%;--layer-tablet-y:20%;--layer-tablet-width:50%;', $html);
    }

    public function test_out_of_range_tablet_positions_are_rejected(): void
    {
        $base = $this->slide('Tablet validation');
        foreach ([
            ['tablet_x', -1],
            ['tablet_y', 101],
            ['tablet_width', 4],
            ['tablet_width', 101],
        ] as [$key, $badValue]) {
            $slide = $base;
            $slide['elements'][0][$key] = $badValue;
            try {
                HeroSliderSettings::validate([$slide], []);
                $this->fail('Invalid responsive coordinate accepted: '.$key);
            } catch (ValidationException) {
                // Expected.
            }
        }
    }

    public function test_server_filters_guest_confirmation_and_role_before_output(): void
    {
        $slides = [
            $this->slide('Всем'),
            $this->slide('Только гостям', ['auth' => 'guest']),
            $this->slide('Для игрока', ['auth' => 'authenticated',
                'confirmation' => 'confirmed', 'system_roles' => ['user']]),
        ];
        $guest = $this->render($slides);
        $this->assertStringContainsString('Всем', $guest);
        $this->assertStringContainsString('Только гостям', $guest);
        $this->assertStringNotContainsString('Для игрока', $guest);
        $this->assertStringContainsString('2 из 2', $guest);

        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        $this->actingAs($user);
        $visible = $this->render($slides);
        $this->assertStringContainsString('Для игрока', $visible);
        $this->assertStringNotContainsString('Только гостям', $visible);
        $this->assertStringContainsString('2 из 2', $visible);
    }

    public function test_generic_links_reuse_five_icons_and_support_custom_uploaded_images(): void
    {
        $slide = $this->slide('Хайлайты');
        $slide['elements'][] = [
            'type' => 'links', 'name' => 'Разделы портала', 'class_name' => 'slider-highlights',
            'x' => 43, 'y' => 68, 'width' => 53,
            'links' => [
                ['label' => 'Площадки', 'url' => '/venues', 'icon' => 'court'],
                ['label' => 'Турниры', 'url' => '/tournaments', 'icon' => 'trophy'],
                ['label' => 'Команды', 'url' => '/teams', 'icon' => 'users'],
                ['label' => 'Медиа', 'url' => '/news', 'icon' => 'video'],
                ['label' => 'Рейтинги', 'url' => '/participants', 'icon' => 'chart'],
                ['label' => 'Афиша', 'url' => '/events', 'icon' => 'custom',
                    'image' => 'page-builder/hero/custom-icon.webp'],
            ],
        ];
        HeroSliderSettings::validate([$slide], []);
        $html = $this->render([$slide]);

        $this->assertStringContainsString('mskba-hero-slide__highlights--layers', $html);
        $this->assertStringContainsString('mskba-hero-slide__highlights-group--duplicate', $html);
        $this->assertStringContainsString('aria-hidden="true" inert', $html);
        $this->assertStringContainsString('href="/tournaments"', $html);
        $this->assertStringContainsString('href="/venues"', $html);
        $this->assertStringContainsString('href="/teams"', $html);
        $this->assertStringContainsString('href="/news"', $html);
        $this->assertStringContainsString('href="/participants"', $html);
        $this->assertStringContainsString('custom-icon.webp', $html);
        $this->assertStringContainsString('data-layer-name="Разделы портала"', $html);
    }

    public function test_generic_button_shows_arrow_by_default_and_can_hide_it(): void
    {
        $slide = $this->slide('О портале');
        $html = $this->render([$slide]);
        $this->assertStringContainsString('<span aria-hidden="true">→</span>', $html);
        $slide['elements'][1]['show_arrow'] = false;
        $html = $this->render([$slide]);
        $this->assertStringNotContainsString('<span aria-hidden="true">→</span>', $html);
    }

    public function test_link_groups_reject_unsafe_urls_and_missing_custom_icons(): void
    {
        $slide = $this->slide('Валидация');
        foreach ([
            [['label' => 'Внешний', 'url' => 'javascript:alert(1)', 'icon' => 'court']],
            [['label' => 'Нет картинки', 'url' => '/venues', 'icon' => 'custom']],
            [['label' => 'Неизвестная иконка', 'url' => '/venues', 'icon' => 'script']],
            [],
        ] as $links) {
            $slide['elements'][] = ['type' => 'links', 'name' => 'Ряд ссылок', 'links' => $links];
            try {
                HeroSliderSettings::validate([$slide], []);
                $this->fail('Недопустимый набор ссылок не должен публиковаться.');
            } catch (ValidationException) {
                // Expected.
            } finally {
                array_pop($slide['elements']);
            }
        }
    }

    public function test_no_matching_slides_returns_empty_public_markup(): void
    {
        $html = $this->render([$this->slide('Закрытый', ['auth' => 'guest', 'confirmation' => 'confirmed'])]);
        $this->assertStringNotContainsString('data-mskba-hero-slider', $html);
        $this->assertSame(0, substr_count($html, 'Закрытый'));
    }

    public function test_preview_can_inspect_all_targeted_slides(): void
    {
        $slide = $this->slide('Только авторизованным', ['auth' => 'authenticated']);
        $this->assertStringContainsString('Только авторизованным', $this->render([$slide], true));
    }

    public function test_allows_existing_legacy_slides_and_rejects_unsafe_layers(): void
    {
        $slider = PageBuilderComponent::query()->where('slug', 'hero-slider')->firstOrFail();
        $legacy = $slider->draft_slides;
        $this->assertSame('legacy', $legacy[0]['layout']);
        HeroSliderSettings::validate($legacy, []);

        $good = $this->slide('Validation');
        foreach ([
            ['elements', [['type' => 'button', 'name' => 'Oops', 'content' => 'Go', 'url' => 'javascript:alert(1)']]],
            ['elements', [['type' => 'image', 'name' => 'Oops', 'image' => 'https://outside.test/evil.svg']]],
            ['elements', [['type' => 'text', 'name' => 'Oops', 'content' => 'hello', 'class_name' => 'x" onclick="alert(1)']]],
            ['elements', []],
            ['audience', ['tags_any' => ['vip']]],
        ] as [$key, $bad]) {
            $slide = $good;
            $slide[$key] = $bad;
            try {
                HeroSliderSettings::validate([$slide], []);
                $this->fail('Invalid slider layer was accepted: '.json_encode($bad));
            } catch (ValidationException) {
                // Expected.
            }
        }
    }

    public function test_disabled_slide_is_omitted_and_old_published_snapshot_is_untouched(): void
    {
        $publisher = app(PageBuilderPublisher::class);
        $slider = PageBuilderComponent::query()->where('slug', 'hero-slider')->firstOrFail();
        $publisher->publish($slider);
        $published = $slider->refresh()->published_slides;
        $slider->update(['draft_slides' => [
            $this->slide('Видимая'),
            [...$this->slide('Отключённая'), 'enabled' => false],
        ]]);
        $this->assertSame($published, $slider->refresh()->published_slides);
        $publisher->publish($slider);
        $page = PageBuilderPage::query()->where('path', '/')->firstOrFail();
        $page->update(['draft_html' => '<mskba-component name="hero-slider"></mskba-component>']);
        $publisher->publish($page);
        $html = app(PageBuilderRenderer::class)->render($page->refresh())['html'];
        $this->assertStringContainsString('Видимая', $html);
        $this->assertStringNotContainsString('Отключённая', $html);
        $this->assertStringNotContainsString('data-hero-next', $html);
    }
}
