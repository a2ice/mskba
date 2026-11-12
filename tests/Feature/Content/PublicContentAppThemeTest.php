<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class PublicContentAppThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'themes.active' => 'mskba_app',
            'seo.indexable_hosts' => ['mskba.ru', 'www.mskba.ru'],
        ]);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->withoutVite();
    }

    public function test_existing_news_slug_renders_database_article_and_original_seo(): void
    {
        $item = $this->content([
            'type' => 'material',
            'title' => 'Новость про площадки',
            'alias' => 'novost-pro-ploshchadki',
            'meta_title' => 'Индивидуальный заголовок новости',
            'meta_description' => 'Индивидуальное описание для поисковых систем',
            'meta_keywords' => 'баскетбол, площадки',
            'full_description' => 'Содержимое новости из существующей базы.',
            'publish_in_feed' => true,
            'feed_published_at' => now()->subDay(),
        ]);

        $url = 'https://mskba.ru/feed/'.$item->alias;

        $this->get($url)
            ->assertOk()
            ->assertSee('Новость про площадки')
            ->assertSee('Содержимое новости из существующей базы.')
            ->assertSee('<title>Индивидуальный заголовок новости</title>', false)
            ->assertSee('name="description" content="Индивидуальное описание для поисковых систем"', false)
            ->assertSee('name="keywords" content="баскетбол, площадки"', false)
            ->assertSee('<link rel="canonical" href="'.$url.'">', false)
            ->assertSee('"@type":"NewsArticle"', false)
            ->assertDontSee('MSKBA App theme');

        $this->get('https://mskba.ru/feed')
            ->assertOk()
            ->assertSee('Новость про площадки')
            ->assertSee('href="'.$url.'"', false)
            ->assertDontSee('MSKBA App theme');
    }

    public function test_unpublished_news_stays_not_found_instead_of_soft_404(): void
    {
        $item = $this->content([
            'type' => 'material',
            'title' => 'Скрытая публикация',
            'alias' => 'hidden-news',
            'status' => 'draft',
            'publish_in_feed' => true,
            'feed_published_at' => now()->subDay(),
        ]);

        $this->get('https://mskba.ru/feed/'.$item->alias)->assertNotFound();
        $this->get('https://mskba.ru/feed/absent-article')->assertNotFound();
    }

    public function test_faq_article_renders_original_alias_body_and_seo(): void
    {
        $item = $this->content([
            'type' => 'faq',
            'title' => 'Как создать команду',
            'alias' => 'faq-kak-sozdat-komandu',
            'meta_title' => 'Как создать команду — MSKBA',
            'meta_description' => 'Подробная инструкция для участников',
            'full_description' => 'Шаги создания команды из базы данных.',
            'publish_in_feed' => false,
        ]);

        $url = 'https://mskba.ru/faq/'.$item->alias;

        $this->get($url)
            ->assertOk()
            ->assertSee('Как создать команду')
            ->assertSee('Шаги создания команды из базы данных.')
            ->assertSee('<title>Как создать команду — MSKBA</title>', false)
            ->assertSee('<link rel="canonical" href="'.$url.'">', false)
            ->assertDontSee('MSKBA App theme');

        $this->get('https://mskba.ru/faq')
            ->assertOk()
            ->assertSee('Как создать команду')
            ->assertSee('href="'.$url.'"', false);
    }

    public function test_legacy_faq_creation_and_welcome_routes_are_real_pages(): void
    {
        foreach (config('creation-guides') as $topic => $guide) {
            $this->get('https://mskba.ru/faq/creation/'.$topic)
                ->assertOk()
                ->assertSee($guide['heading'])
                ->assertSee($guide['intro'])
                ->assertSee('Условия создания')
                ->assertDontSee('MSKBA App theme');
        }

        $this->get('https://mskba.ru/faq/welcome')
            ->assertOk()
            ->assertSee('Первые шаги')
            ->assertSee('id="contact-confirmation"', false)
            ->assertSee('id="account-confirmation"', false)
            ->assertSee('id="creation-permissions"', false)
            ->assertDontSee('MSKBA App theme');
    }

    public function test_non_production_host_keeps_noindex_on_real_article(): void
    {
        $item = $this->content([
            'type' => 'material',
            'title' => 'Тестовая новость',
            'alias' => 'test-news',
            'publish_in_feed' => true,
            'feed_published_at' => now()->subDay(),
        ]);

        $this->get('https://dev.mskba.ru/feed/'.$item->alias)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('name="robots" content="noindex, nofollow, noarchive"', false);
    }

    private function content(array $attributes): ContentItem
    {
        $user = User::factory()->create();

        return ContentItem::query()->create(array_merge([
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'type' => 'material',
            'title' => 'Публикация',
            'alias' => 'publikatsiya',
            'status' => 'published',
            'short_description' => 'Краткое описание материала.',
            'full_description' => 'Подробный текст публикации.',
            'publish_in_feed' => false,
            'publish_in_telegram' => false,
            'feed_published_at' => null,
        ], $attributes));
    }
}
