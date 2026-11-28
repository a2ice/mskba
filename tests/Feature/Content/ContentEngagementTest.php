<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Domain\Models\ContentComment;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ContentEngagementTest extends TestCase
{
    use RefreshDatabase;

    private function item(User $user, array $attributes = []): ContentItem
    {
        return ContentItem::query()->create(array_replace([
            'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id,
            'type' => 'material', 'title' => 'Материал для проверки', 'alias' => 'engagement-material',
            'short_description' => 'Описание', 'full_description' => 'Текст',
            'status' => 'published', 'publish_in_feed' => true, 'feed_published_at' => now()->subDay(),
        ], $attributes));
    }

    public function test_views_are_counted_only_on_public_detail_once_per_account_window(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();
        $user = User::factory()->create();
        $item = $this->item($user);
        $url = route('news.show', $item->alias);
        $updatedAt = $item->updated_at->toDateTimeString();
        $this->actingAs($user)->withHeader('User-Agent', 'Mozilla/5.0');
        $this->get(route('news.index'))->assertOk();
        $this->get($url.'?preview=1')->assertOk();
        $this->head($url)->assertOk();
        $this->assertSame(0, $item->fresh()->views_count);
        $this->get($url)->assertOk();
        $this->get($url)->assertOk();
        $this->assertSame(1, $item->fresh()->views_count);
        $this->travel(31)->minutes();
        $this->get($url)->assertOk();
        $this->assertSame(2, $item->fresh()->views_count);
        $this->assertSame($updatedAt, $item->fresh()->updated_at->toDateTimeString());
        $this->travelBack();
        $this->actingAs(User::factory()->create())->withHeader('User-Agent', 'Googlebot')->get($url)->assertOk();
        $this->assertSame(2, $item->fresh()->views_count);
    }

    public function test_comment_moderation_ownership_revision_and_public_count(): void
    {
        $author = User::factory()->create(['nickname' => 'Игрок']);
        $other = User::factory()->create();
        $admin = User::factory()->create(['system_role' => UserSystemRoleEnum::SUPERADMIN, 'status' => UserStatusEnum::CONFIRMED]);
        $item = $this->item($admin);
        $url = route('news.comments.index', $item->alias);
        $this->postJson($url, ['body' => 'Комментарий'])->assertUnauthorized();
        $this->actingAs($author)->postJson($url, ['body' => '  '])->assertUnprocessable();
        $this->postJson($url, ['body' => '<b>Текст</b>'])->assertCreated()->assertJsonPath('published', false)->assertJsonPath('count', 0);
        $comment = ContentComment::query()->sole();
        $this->postJson($url, ['body' => 'Повтор'])->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'comments');
        $this->getJson($url.'?mine=1')->assertOk()->assertJsonPath('comments.0.body', '<b>Текст</b>')
            ->assertJsonPath('comments.0.own', true)->assertJsonPath('comments.0.published', false);
        $this->actingAs($other)->getJson($url.'?mine=1')->assertOk()->assertJsonCount(0, 'comments');
        $this->patchJson($url.'/'.$comment->id, ['body' => 'Чужой текст', 'revision' => 1])->assertNotFound();
        $this->deleteJson($url.'/'.$comment->id)->assertNotFound();
        $moderate = route('admin.content.comments.moderate', [$item->alias, $comment->id]);
        $this->patchJson($moderate, ['action' => 'publish', 'revision' => 1])->assertForbidden();
        $this->actingAs($admin)->patch($moderate, ['action' => 'publish', 'revision' => 1])->assertRedirect();
        $this->actingAs($other)->getJson($url)->assertOk()->assertJsonPath('count', 1)->assertJsonPath('comments.0.own', false);
        $this->actingAs($author)->patchJson($url.'/'.$comment->id, ['body' => 'Исправлено', 'revision' => 2])
            ->assertOk()->assertJsonPath('count', 0);
        $this->actingAs($admin)->patchJson($moderate, ['action' => 'publish', 'revision' => 2])->assertUnprocessable();
        $this->patch($moderate, ['action' => 'publish', 'revision' => 3])->assertRedirect();
        $this->actingAs($author)->deleteJson($url.'/'.$comment->id)->assertOk()->assertJsonPath('count', 0);
        $this->assertSoftDeleted($comment);
        $this->getJson($url.'?mine=1')->assertOk()->assertJsonCount(0, 'comments');
        $item->update(['status' => 'draft']);
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, ['body' => 'Скрытый материал'])->assertNotFound();
    }

    public function test_comment_cursor_loads_ten_published_comments_without_hidden_rows(): void
    {
        $user = User::factory()->create();
        $item = $this->item($user);
        foreach (range(1, 13) as $n) {
            $item->comments()->create(['user_id' => $user->id, 'body' => 'Комментарий '.$n, 'is_published' => $n !== 13]);
        }
        $url = route('news.comments.index', $item->alias);
        $first = $this->getJson($url)->assertOk()->assertJsonCount(10, 'comments')->assertJsonPath('count', 12);
        $cursor = $first->json('next');
        $this->assertNotNull($cursor);
        $this->getJson($url.'?before='.$cursor)->assertOk()->assertJsonCount(2, 'comments')->assertJsonPath('next', null);
    }
}
