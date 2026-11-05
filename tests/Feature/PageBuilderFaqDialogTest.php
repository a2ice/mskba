<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderMarkup;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PageBuilderFaqDialogTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_can_render_declarative_faq_button_with_the_unified_dialog(): void
    {
        $widget = PageBuilderComponent::query()->create([
            'slug' => 'faq-example',
            'name' => 'FAQ example',
            'draft_html' => '<button type="button" class="section-cta"'
                .' data-mskba-faq-section="other"'
                .' data-mskba-faq-article="faq-uniform-selection">Подробнее</button>',
        ]);
        $publisher = app(PageBuilderPublisher::class);
        $publisher->publish($widget);

        $page = PageBuilderPage::query()->where('path', '/')->firstOrFail();
        $page->update([
            'draft_html' => '<section><mskba-component name="faq-example"></mskba-component></section>',
        ]);
        $publisher->publish($page);
        $this->withoutVite();

        $response = $this->get('/')->assertOk()
            ->assertSee('data-mskba-faq-section="other"', false)
            ->assertSee('data-mskba-faq-article="faq-uniform-selection"', false)
            ->assertSee('data-context-help-dialog', false)
            ->assertSee('Помощь и FAQ');

        $html = (string) $response->getContent();
        $this->assertSame(1, substr_count($html, 'data-context-help-dialog'));
        $this->assertStringNotContainsString('data-app-context-actions', $html);
    }

    public function test_faq_attributes_are_preserved_but_inline_handlers_stay_forbidden(): void
    {
        $html = app(PageBuilderMarkup::class)->html(
            '<button type="submit" data-mskba-faq-section="other"'
                .' data-mskba-faq-article="faq-uniform-partnership"'
                .' onclick="alert(1)">Как стать партнёром</button>',
        );

        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('data-mskba-faq-section="other"', $html);
        $this->assertStringContainsString('data-mskba-faq-article="faq-uniform-partnership"', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('type="submit"', $html);
    }
}
