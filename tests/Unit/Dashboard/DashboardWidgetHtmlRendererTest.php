<?php

namespace Tests\Unit\Dashboard;

use App\Modules\Dashboard\Application\Services\DashboardWidgetHtmlRenderer;
use PHPUnit\Framework\TestCase;

final class DashboardWidgetHtmlRendererTest extends TestCase
{
    public function test_lists_formatting_and_link_buttons_are_preserved(): void
    {
        $html = app(DashboardWidgetHtmlRenderer::class)->render(
            '<p><strong>Добро пожаловать</strong></p><ul><li>Игры</li><li>Команды</li></ul>'.
            '<a class="button secondary" href="/account/roles">Мои роли</a>'
        );

        self::assertStringContainsString('<strong>Добро пожаловать</strong>', $html);
        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<li>Команды</li>', $html);
        self::assertStringContainsString('href="/account/roles"', $html);
        self::assertStringContainsString('class="button secondary"', $html);
    }

    public function test_inline_js_events_unsafe_urls_and_arbitrary_css_classes_are_discarded(): void
    {
        $html = app(DashboardWidgetHtmlRenderer::class)->render(
            '<script>alert(1)</script><p onclick="evil()">Текст</p>'.
            '<a href="javascript:alert(1)" class="button primary fixed z-50" onmouseover="evil()">Опасная ссылка</a>'.
            '<a href="https://mskba.ru" target="_blank">Официальная ссылка</a>'.
            '<img src="x" onerror="evil()">'
        );

        foreach (['<script', 'onclick', 'onmouseover', 'onerror', 'javascript:', 'fixed', 'z-50'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $html);
        }

        self::assertStringContainsString('class="button primary"', $html);
        self::assertStringContainsString('href="https://mskba.ru"', $html);
        self::assertStringContainsString('noopener noreferrer', $html);
    }

    public function test_dynamic_tokens_are_escaped_and_no_php_or_blade_executed(): void
    {
        $html = app(DashboardWidgetHtmlRenderer::class)->render(
            '<p>Всего <strong>{{ unread_count }}</strong></p><p>{{ evil }}</p>',
            ['unread_count' => '<img src=x onerror=alert(1)>'],
        );
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('{{ evil }}', $html);
        self::assertStringNotContainsString('<img src=x', $html);
    }
}
