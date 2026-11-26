<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Portal\Application\Services\PortalContactContentAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PortalContactContentAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_audit_reports_locations_without_contact_values(): void
    {
        $page = PageBuilderPage::query()->where('path', '/')->whereNull('design_theme_id')->firstOrFail();
        $markup = '<p>hello@example.test</p><a href="https://t.me/example_test">Telegram</a>';
        $page->update(['draft_html' => $markup]);
        $matches = app(PortalContactContentAudit::class)->scan();
        $this->assertContains(['table' => 'page_builder_pages', 'id' => $page->id, 'field' => 'draft_html', 'types' => 'email,social'], $matches);
        $this->assertStringNotContainsString('hello@example.test', json_encode($matches));
        $this->assertSame($markup, $page->refresh()->draft_html);
    }
}
