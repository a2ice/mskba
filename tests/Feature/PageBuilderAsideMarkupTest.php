<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderMarkup;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PageBuilderAsideMarkupTest extends TestCase
{
    public function test_aside_survives_sanitization_with_semantic_attributes(): void
    {
        $markup = app(PageBuilderMarkup::class);
        $html = '<aside class="sidebar" id="venue-filters"><p>Фильтры</p></aside>';

        $markup->validate($html, null);
        $result = $markup->html($html);
        $this->assertStringContainsString('<aside class="sidebar" id="venue-filters">', $result);
        $this->assertStringContainsString('Фильтры', $result);
        $this->assertStringContainsString('</aside>', $result);
    }

    public function test_aside_does_not_allow_arbitrary_attributes_or_inline_javascript(): void
    {
        $markup = app(PageBuilderMarkup::class);
        $clean = $markup->html(
            '<aside id="filters" onclick="alert(1)" data-danger="yes"><p>OK</p></aside>',
        );
        $this->assertStringContainsString('<aside id="filters">', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('data-danger', $clean);

        $this->expectException(ValidationException::class);
        $markup->validate('<aside onmouseover="alert(1)">Unsafe</aside>', null);
    }
}
