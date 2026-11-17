<?php

namespace Tests\Feature;

use App\Presentation\Theming\ThemeResolver;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class MskbaAppPreviewTest extends TestCase
{
    public function test_preview_is_not_available_to_legacy_theme(): void
    {
        config()->set('themes.active', 'mskba_dark');
        app()->forgetInstance(ThemeResolver::class);

        $this->get('/ui-preview')->assertNotFound();
    }

    public function test_preview_renders_an_inertia_page_only_in_mskba_app(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        $response = $this->get('/ui-preview');

        $response->assertOk();
        $response->assertSee('data-page=', false);
        $response->assertSee('Preview');
    }
}
