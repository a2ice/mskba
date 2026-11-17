<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PageBuilderAgentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'staging', 'app.url' => 'https://dev.mskba.ru']);
    }

    private function token(
        UserSystemRoleEnum $role = UserSystemRoleEnum::SUPERADMIN,
        array $abilities = ['page-builder:read', 'page-builder:write', 'page-builder:publish'],
        string $name = 'mskba-pagebuilder-agent:test',
    ): string {
        $user = User::factory()->create([
            'system_role' => $role,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        return $user->createToken($name, $abilities, now()->addDays(3))->plainTextToken;
    }

    private function sceneComponent(): PageBuilderComponent
    {
        $record = PageBuilderComponent::query()->create([
            'slug' => 'agent-test',
            'name' => 'Agent Test',
            'draft_html' => '<section class="scene">Старая версия</section>',
            'draft_css' => '.scene { color: #fff; }',
        ]);
        $record->forceFill([
            'published_html' => $record->draft_html,
            'published_css' => $record->draft_css,
            'published_at' => now(),
        ])->save();

        return $record->refresh();
    }

    public function test_endpoint_requires_short_lived_dedicated_superadmin_token_and_staging(): void
    {
        $component = $this->sceneComponent();
        $uri = '/api/v1/internal/page-builder/components/'.$component->slug;

        $this->getJson($uri)->assertUnauthorized();
        // Sanctum's guard caches a resolved user for the current Laravel test
        // process: reset it whenever the Authorization bearer changes.
        app('auth')->forgetGuards();
        $this->withToken($this->token(UserSystemRoleEnum::USER))->getJson($uri)->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->token(name: 'mskba-client:mobile'))->getJson($uri)->assertForbidden();
        app('auth')->forgetGuards();

        $this->withToken($this->token())->getJson($uri)->assertOk()
            ->assertJsonPath('data.sources.css.published', '.scene { color: #fff; }');

        config(['app.url' => 'https://mskba.ru']);
        app('auth')->forgetGuards();
        $this->getJson($uri)->assertNotFound();
    }

    public function test_draft_css_does_not_publish_and_conflicts_prevent_overwrite(): void
    {
        $component = $this->sceneComponent();
        $uri = '/api/v1/internal/page-builder/components/'.$component->slug;
        $this->withToken($this->token());

        $this->patchJson($uri.'/draft', [
            'field' => 'css',
            'value' => '.scene { color: #f90; }',
            'expected_draft_sha256' => hash('sha256', (string) $component->draft_css),
            'reason' => 'Проверка нового расположения',
        ])->assertOk()->assertJsonPath('data.sources.css.pending', true);

        $component->refresh();
        $this->assertSame('.scene { color: #fff; }', $component->published_css);
        $this->assertSame('.scene { color: #f90; }', $component->draft_css);

        $this->patchJson($uri.'/draft', [
            'field' => 'css',
            'value' => '.scene { color: red; }',
            'expected_draft_sha256' => hash('sha256', '.scene { color: #fff; }'),
            'reason' => 'Конфликтующие изменения',
        ])->assertStatus(409);

        $this->postJson($uri.'/publish', [
            'field' => 'css',
            'expected_draft_sha256' => hash('sha256', '.scene { color: #f90; }'),
            'expected_published_sha256' => hash('sha256', '.scene { color: #fff; }'),
            'reason' => 'Публикация обновлённого CSS',
        ])->assertOk();

        $component->refresh();
        $this->assertSame('.scene { color: #f90; }', $component->published_css);
        $this->assertSame('.scene { color: #fff; }', $component->previous_css);
        $this->assertTrue($component->has_previous_css);

        $this->postJson($uri.'/rollback', [
            'field' => 'css',
            'expected_published_sha256' => hash('sha256', '.scene { color: #f90; }'),
            'reason' => 'Возвращаем проверенную версию',
        ])->assertOk();

        $this->assertSame('.scene { color: #fff; }', $component->fresh()->published_css);
        $this->assertSame(1, PageBuilderRevision::query()
            ->where('entity_type', 'component')
            ->where('entity_id', $component->id)
            ->where('action', 'agent_rollback')->count());
    }

    public function test_html_validation_blocks_executable_content_and_isolated_publish_keeps_css_draft(): void
    {
        $component = $this->sceneComponent();
        $uri = '/api/v1/internal/page-builder/components/'.$component->slug;
        $this->withToken($this->token());

        $this->patchJson($uri.'/draft', [
            'field' => 'html',
            'value' => '<script>alert(1)</script>',
            'expected_draft_sha256' => hash('sha256', (string) $component->draft_html),
            'reason' => 'Недопустимый HTML',
        ])->assertUnprocessable();

        // An unrelated CSS draft must not be deployed by publishing HTML.
        $component->draft_css = '.scene { color: #f90; }';
        $component->save();
        $this->patchJson($uri.'/draft', [
            'field' => 'html',
            'value' => '<section class="scene">Новая версия</section>',
            'expected_draft_sha256' => hash('sha256', (string) $component->draft_html),
            'reason' => 'Новый безопасный контент',
        ])->assertOk();
        $this->postJson($uri.'/publish', [
            'field' => 'html',
            'expected_draft_sha256' => hash('sha256', '<section class="scene">Новая версия</section>'),
            'expected_published_sha256' => hash('sha256', '<section class="scene">Старая версия</section>'),
            'reason' => 'Сохраняем только HTML',
        ])->assertStatus(409);

        $this->assertSame('<section class="scene">Старая версия</section>', $component->fresh()->published_html);
    }

    public function test_js_staging_publishing_and_restoring_uses_existing_store_and_audit(): void
    {
        $component = $this->sceneComponent();
        $uri = '/api/v1/internal/page-builder/components/'.$component->slug;
        $this->withToken($this->token());

        $this->patchJson($uri.'/draft', [
            'field' => 'js',
            'value' => 'window.mskbaTest = 1;',
            'expected_draft_sha256' => hash('sha256', ''),
            'reason' => 'Подготовить скрипт сцены',
        ])->assertOk();

        $this->postJson($uri.'/publish', [
            'field' => 'js',
            'expected_draft_sha256' => hash('sha256', 'window.mskbaTest = 1;'),
            'expected_published_sha256' => hash('sha256', ''),
            'reason' => 'Публикация скрипта сцены',
        ])->assertOk()->assertJsonPath('data.js_enabled', true);

        $script = PageBuilderScriptAsset::query()
            ->where('entity_type', 'component')->where('entity_id', $component->id)->firstOrFail();
        $this->assertSame('window.mskbaTest = 1;', $script->published_js);
        $this->assertSame(hash('sha256', 'window.mskbaTest = 1;'), $script->published_hash);
    }
}
