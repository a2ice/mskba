<?php

namespace Tests\Unit\Content;

use App\Modules\Content\Application\Services\ContentBodySanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentBodySanitizerTest extends TestCase
{
    #[Test]
    public function it_keeps_supported_markup_and_project_classes(): void
    {
        $result = (new ContentBodySanitizer)->sanitize(
            '<h2>Заголовок</h2><p class="content-lead unknown">Текст <strong>важный</strong></p>'
            .'<table class="content-table"><tbody><tr><td>Значение</td></tr></tbody></table>',
        );

        self::assertStringContainsString('<h2>Заголовок</h2>', $result);
        self::assertStringContainsString('class="content-lead"', $result);
        self::assertStringNotContainsString('unknown', $result);
        self::assertStringContainsString('class="content-table"', $result);
    }

    #[Test]
    public function it_removes_executable_markup_and_unsafe_attributes(): void
    {
        $result = (new ContentBodySanitizer)->sanitize(
            '<script>alert(1)</script>'
            .'<p style="position:fixed" onclick="alert(2)">Безопасный текст</p>'
            .'<a href="javascript:alert(3)">Ссылка</a>'
            .'<img src="data:text/html;base64,PHNjcmlwdD4=" onerror="alert(4)">',
        );

        self::assertStringNotContainsString('script', $result);
        self::assertStringNotContainsString('onclick', $result);
        self::assertStringNotContainsString('style=', $result);
        self::assertStringNotContainsString('javascript:', $result);
        self::assertStringNotContainsString('data:', $result);
        self::assertStringNotContainsString('onerror', $result);
        self::assertStringContainsString('Безопасный текст', $result);
    }

    #[Test]
    public function it_preserves_gallery_layout_and_only_allowlisted_venue_actions(): void
    {
        $html = '<div class="content-gallery d-flex flex-wrap gap-3 evil">'
            .'<a class="content-lightbox" href="/storage/venues/descriptions/a.webp" data-venue-lightbox="court" data-modal-target="danger">'
            .'<img src="/storage/venues/descriptions/a.webp" alt="Зал"></a></div>'
            .'<a href="#app-venue-schedule" data-venue-rental-open="1" onclick="alert(1)">Арендовать</a>'
            .'<a href="#app-venue-about" data-venue-play-open="1" data-unknown="bad">Хочу играть</a>';

        $result = (new ContentBodySanitizer)->sanitize($html);

        self::assertStringContainsString('class="content-gallery d-flex flex-wrap gap-3"', $result);
        self::assertStringContainsString('class="content-lightbox"', $result);
        self::assertStringContainsString('data-venue-lightbox="court"', $result);
        self::assertStringContainsString('data-venue-rental-open="1"', $result);
        self::assertStringContainsString('data-venue-play-open="1"', $result);
        self::assertStringNotContainsString('evil', $result);
        self::assertStringNotContainsString('data-modal-target', $result);
        self::assertStringNotContainsString('data-unknown', $result);
        self::assertStringNotContainsString('onclick', $result);
    }

    #[Test]
    public function it_forces_security_attributes_on_links_and_lazy_loading_on_images(): void
    {
        $result = (new ContentBodySanitizer)->sanitize(
            '<a href="https://mskba.ru">MSKBA</a><img src="/images/example.webp" alt="Фото">',
        );

        self::assertMatchesRegularExpression('/<a[^>]+rel="noopener noreferrer"/', $result);
        self::assertMatchesRegularExpression('/<img[^>]+loading="lazy"/', $result);
    }
}
