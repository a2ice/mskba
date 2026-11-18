<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Domain\Models\ProjectUpdateDetail;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private function token(
        UserSystemRoleEnum $role = UserSystemRoleEnum::SYSTEM,
        string $username = 'dev',
        string $ability = 'project-updates:write',
        string $name = 'mskba-project-updates:test',
    ): string {
        $user = User::factory()->create([
            'username' => $username,
            'system_role' => $role,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        return $user->createToken($name, [$ability], now()->addDay())->plainTextToken;
    }

    private function payload(): array
    {
        return [
            'source_key' => 'mskba-merged-pr-818',
            'title' => 'Обновлены возможности платформы',
            'summary' => 'Стало удобнее отслеживать развитие платформы.',
            'content' => 'Команда внедрила новый способ следить за обновлениями проекта.',
            'github_pr' => 'https://github.com/a2ice/mskba/pull/818',
            'task_reference' => '327',
            'documentation' => ['docs/tasks/327/description.md'],
        ];
    }

    public function test_only_scoped_system_dev_token_can_create_drafts(): void
    {
        $uri = '/api/v1/internal/project-updates';
        $this->postJson($uri, $this->payload())->assertUnauthorized();
        app('auth')->forgetGuards();

        $this->withToken($this->token(UserSystemRoleEnum::USER, 'ordinary'))
            ->postJson($uri, $this->payload())->assertForbidden();
        app('auth')->forgetGuards();

        $this->withToken($this->token(ability: 'profile:read'))
            ->postJson($uri, $this->payload())->assertForbidden();
        app('auth')->forgetGuards();

        // Unique usernames in a test DB: the proper identity is another account.
        $this->withToken($this->token(username: 'another-system-account'))
            ->postJson($uri, $this->payload())->assertForbidden();
    }


    public function test_unconfirmed_system_dev_can_use_scoped_api_but_explicitly_blocked_dev_cannot(): void
    {
        $dev = User::factory()->create([
            'username' => 'dev',
            'system_role' => UserSystemRoleEnum::SYSTEM,
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);
        $token = $dev->createToken('mskba-project-updates:unconfirmed-test', ['project-updates:write'], now()->addDay());
        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/internal/project-updates', $this->payload())
            ->assertOk();

        $dev->forceFill(['status' => UserStatusEnum::BLOCKED])->save();
        app('auth')->forgetGuards();
        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/internal/project-updates/'.ContentItem::query()->sole()->id)
            ->assertForbidden();
    }

    public function test_ingest_is_idempotent_and_private_metadata_does_not_leak_into_feed(): void
    {
        $uri = '/api/v1/internal/project-updates';
        $this->withToken($this->token());

        $this->postJson($uri, $this->payload())->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.url', null);

        $this->postJson($uri, [
            ...$this->payload(),
            'summary' => 'Описание отредактировано.',
        ])->assertOk();

        $this->assertSame(1, ContentItem::query()->count());
        $this->assertSame(1, ProjectUpdateDetail::query()->count());
        $item = ContentItem::query()->sole();
        $this->assertSame(ContentTypeEnum::SYSTEM_UPDATE, $item->type);
        $this->assertSame(ContentStatusEnum::DRAFT, $item->status);
        $this->assertFalse($item->publish_in_feed);
        $this->assertSame('Описание отредактировано.', $item->short_description);

        $this->get(route('news.index'))->assertOk()
            ->assertDontSee('Обновлены возможности платформы')
            ->assertDontSee('github.com/a2ice/mskba/pull/818');
        $this->get(route('news.show', $item->alias))->assertNotFound();
    }

    public function test_public_text_cannot_contain_internal_github_or_documentation_links(): void
    {
        $this->withToken($this->token());
        $this->postJson('/api/v1/internal/project-updates', [
            ...$this->payload(),
            'content' => 'Внутренняя документация: docs/tasks/327/description.md',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/internal/project-updates', [
            ...$this->payload(),
            'documentation' => ['docs/../../private.md'],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('content_items', 0);
    }

    public function test_backoffice_cannot_publish_without_production_verification(): void
    {
        $this->withToken($this->token());
        $this->postJson('/api/v1/internal/project-updates', $this->payload())->assertOk();
        $item = ContentItem::query()->sole();

        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        app('auth')->forgetGuards();
        $this->actingAs($admin)
            ->put(route('admin.content.update', $item->alias), [
                'type' => 'system_update',
                'status' => 'published',
                'title' => $item->title,
                'short_description' => $item->short_description,
                'full_description' => $item->full_description,
                'project_update_pr_url' => $this->payload()['github_pr'],
                'project_update_task_reference' => '327',
                'project_update_documentation' => 'docs/tasks/327/description.md',
                'publish_in_feed' => '1',
                'publish_in_telegram' => '0',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(ContentStatusEnum::DRAFT, $item->fresh()->status);
    }
}
