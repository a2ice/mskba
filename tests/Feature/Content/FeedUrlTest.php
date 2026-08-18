<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FeedUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_news_route_names_generate_canonical_feed_urls(): void
    {
        $content = $this->publishedContent();

        $this->assertSame('/feed', route('news.index', [], false));
        $this->assertSame('/feed/'.$content->alias, route('news.show', $content->alias, false));
    }

    public function test_legacy_news_index_permanently_redirects_to_feed_and_preserves_query(): void
    {
        $this->get('/news?page=2')
            ->assertStatus(301)
            ->assertRedirect(route('news.index', ['page' => 2]));
    }

    public function test_legacy_news_article_permanently_redirects_to_matching_feed_article(): void
    {
        $content = $this->publishedContent();

        $this->get('/news/'.$content->alias.'?utm_source=legacy')
            ->assertStatus(301)
            ->assertRedirect(route('news.show', [
                'contentItem' => $content->alias,
                'utm_source' => 'legacy',
            ]));
    }

    public function test_feed_article_exposes_feed_url_as_canonical(): void
    {
        $content = $this->publishedContent();

        $this->get(route('news.show', $content->alias))
            ->assertOk()
            ->assertSee(
                '<link rel="canonical" href="'.route('news.show', $content->alias).'">',
                false,
            );
    }

    private function publishedContent(): ContentItem
    {
        $user = User::factory()->create();

        return ContentItem::query()->create([
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'type' => 'material',
            'title' => 'Материал для ленты',
            'alias' => 'material-dlya-lenty',
            'short_description' => 'Краткое описание.',
            'full_description' => 'Полное описание материала.',
            'publish_in_feed' => true,
            'publish_in_telegram' => false,
            'feed_published_at' => now(),
        ]);
    }
}
