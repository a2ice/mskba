<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\Services\ContentBodyRenderer;
use App\Modules\Content\Application\Services\PortalContactContentAudit;
use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Application\Services\PortalContactSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PortalContactEditorialTest extends TestCase
{
    use RefreshDatabase;

    private function material(string $title, string $body): ContentItem
    {
        $user = User::factory()->create();

        return ContentItem::query()->create([
            'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id,
            'type' => 'material', 'title' => $title, 'alias' => 'contact-test-'.uniqid(),
            'short_description' => 'Тест', 'full_description' => $body,
            'content_format' => ContentFormatEnum::SAFE_HTML,
            'publish_in_feed' => false, 'publish_in_telegram' => false,
        ]);
    }

    public function test_audited_articles_are_migrated_and_render_current_public_email(): void
    {
        $order = $this->material('Как заказать изготовление индивидуальной формы',
            '<p>Можно написать на <a href="mailto:support@mskba.ru">support@mskba.ru</a>.</p>');
        $partner = $this->material('Как стать партнёром MSKBA по экипировке',
            '<p>Напишите нам на <a href="mailto:support@mskba.ru?subject='.rawurlencode('MSKBA - сотрудничество').'">support@mskba.ru</a>. <a href="mailto:third@example.test">Партнёр</a></p>');
        $other = $this->material('Другой материал', $order->full_description);
        $migration = require database_path('migrations/2026_11_27_040000_replace_equipment_support_links_with_portal_contact_shortcode.php');
        $migration->up();
        $migration->up();
        $this->assertSame('<p>Можно написать [portal-contact-email].</p>', $order->refresh()->full_description);
        $this->assertStringContainsString('[portal-contact-email partnership]', $partner->refresh()->full_description);
        $this->assertStringContainsString('third@example.test', $partner->full_description);
        $this->assertStringContainsString('support@mskba.ru', $other->refresh()->full_description);
        $this->assertNotContains($order->id, collect(app(PortalContactContentAudit::class)->scan())->where('table', 'content_items')->pluck('id')->all());

        $admin = User::factory()->create(['system_role' => UserSystemRoleEnum::SUPERADMIN, 'status' => UserStatusEnum::CONFIRMED]);
        $settings = app(PortalContactSettings::class);
        $payload = ['email' => 'current@example.test', 'phone' => null, 'telegram_url' => null, 'vk_url' => null,
            'email_public' => true, 'phone_public' => false, 'telegram_public' => false, 'vk_public' => false];
        $settings->save($admin, $payload);
        $renderer = app(ContentBodyRenderer::class);
        $this->assertStringContainsString('mailto:current@example.test', $renderer->render($order));
        $this->assertStringContainsString('mailto:current@example.test?subject='.rawurlencode('MSKBA - сотрудничество'), $renderer->render($partner));
        $settings->save($admin, [...$payload, 'email_public' => false]);
        $html = $renderer->render($partner);
        $this->assertStringNotContainsString('current@example.test', $html);
        $this->assertStringNotContainsString('support@mskba.ru', $html);
        $this->assertStringContainsString(route('faq.index'), $html);
        $this->assertStringContainsString('third@example.test', $html);
    }

    public function test_shortcode_does_not_expand_attributes_or_nested_links(): void
    {
        $material = $this->material('Test', '<p><a title="[portal-contact-email]" href="https://example.test">[portal-contact-email]</a> [portal-contact-email]</p>');
        config(['support.email' => 'dynamic@example.test']);
        $html = app(ContentBodyRenderer::class)->render($material);
        $this->assertSame(1, substr_count($html, 'mailto:dynamic@example.test'));
        $this->assertStringContainsString('title="[portal-contact-email]"', $html);
    }
}
