<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\Services\ProjectUpdateDrafts;
use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Domain\Models\ProjectUpdateDetail;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class FirstSystemUpdateProductionSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_release_publishes_one_idempotent_update_with_private_links(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://mskba.ru']);
        $dev = User::factory()->create([
            'username' => 'dev',
            'system_role' => UserSystemRoleEnum::SYSTEM,
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);
        $payload = [
            'source_key' => 'mskba-pr821-task329',
            'title' => 'Упростили работу с дублирующимися площадками',
            'summary' => 'Площадки стало удобнее объединять.',
            'content' => 'Ручное назначение дублей с защитой данных.',
            'github_pr' => 'https://github.com/a2ice/mskba/pull/821',
            'task_reference' => '329',
            'documentation' => ['docs/tasks/329/description.md'],
        ];
        app(ProjectUpdateDrafts::class)->upsert($payload, $dev);

        Http::fake([
            'https://mskba.ru/api/v1/internal/project-updates' => Http::response(
                ['data' => ['status' => 'draft']], 200,
            ),
            'https://mskba.ru/feed/*' => Http::response(
                '<html><h1>Упростили работу с дублирующимися площадками</h1></html>', 200,
            ),
        ]);

        $firstExit = Artisan::call('project-updates:publish-task-329', ['--confirm-release' => true]);
        $this->assertSame(0, $firstExit, Artisan::output());

        $item = ContentItem::query()->sole();
        $this->assertSame(ContentStatusEnum::PUBLISHED, $item->status);
        $this->assertTrue($item->publish_in_feed);
        $this->assertFalse($item->publish_in_telegram);
        $this->assertNotNull($item->feed_published_at);
        $detail = ProjectUpdateDetail::query()->sole();
        $this->assertNotNull($detail->production_verified_at);
        $this->assertSame('release-acceptance:github-actions', $detail->source);
        $this->assertSame('https://github.com/a2ice/mskba/pull/821', $detail->pr_url);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // A later production push does not produce a duplicate article.
        $secondExit = Artisan::call('project-updates:publish-task-329', ['--confirm-release' => true]);
        $this->assertSame(0, $secondExit, Artisan::output());
        $this->assertDatabaseCount('content_items', 1);
        $this->assertDatabaseCount('project_update_details', 1);
    }

    public function test_command_refuses_nonproduction_release_and_creates_no_tokens(): void
    {
        config(['app.env' => 'staging', 'app.url' => 'https://dev.mskba.ru']);
        $this->artisan('project-updates:publish-task-329', ['--confirm-release' => true])
            ->assertExitCode(1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('content_items', 0);
    }
}
