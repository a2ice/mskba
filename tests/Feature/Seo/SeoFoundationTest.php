<?php

namespace Tests\Feature\Seo;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeoFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_feed_has_russian_language_and_core_metadata(): void
    {
        $this->get(route('news.index'))
            ->assertOk()
            ->assertSee('<html lang="ru">', false)
            ->assertSee('name="description"', false)
            ->assertSee('name="robots" content="index, follow, max-image-preview:large"', false)
            ->assertSee('property="og:site_name" content="MSKBA"', false)
            ->assertSee('name="twitter:card" content="summary_large_image"', false);
    }

    public function test_sitemap_contains_public_feed_article(): void
    {
        $user = User::factory()->create();
        $content = ContentItem::query()->create([
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'type' => 'material',
            'title' => 'SEO материал',
            'alias' => 'seo-material',
            'short_description' => 'Краткое описание.',
            'full_description' => 'Полный текст.',
            'publish_in_feed' => true,
            'publish_in_telegram' => false,
            'feed_published_at' => now(),
        ]);

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8')
            ->assertSee(route('news.show', $content->alias), false);
    }

    public function test_article_exposes_news_article_structured_data(): void
    {
        $user = User::factory()->create();
        $content = ContentItem::query()->create([
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'type' => 'material',
            'title' => 'Структурированные данные',
            'alias' => 'structured-data',
            'short_description' => 'Краткое описание.',
            'full_description' => 'Полный текст.',
            'publish_in_feed' => true,
            'publish_in_telegram' => false,
            'feed_published_at' => now(),
        ]);

        $this->get(route('news.show', $content->alias))
            ->assertOk()
            ->assertSee('application/ld+json', false)
            ->assertSee('"@type":"NewsArticle"', false)
            ->assertSee('"headline":"Структурированные данные"', false);
    }
}
