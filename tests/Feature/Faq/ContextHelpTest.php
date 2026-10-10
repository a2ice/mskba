<?php

namespace Tests\Feature\Faq;

use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Domain\Models\SupportQuestion;
use App\Modules\Content\Infrastructure\Mail\SupportQuestionMail;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class ContextHelpTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->previousTheme);
        View::replaceNamespace('theme', resource_path('themes/'.$this->previousTheme.'/views'));
        app()->forgetInstance(ThemeResolver::class);
        parent::tearDown();
    }

    private function faq(string $alias, ContentStatusEnum $status, string $systemKey): ContentItem
    {
        $author = User::factory()->create();
        return ContentItem::query()->create([
            'created_by_user_id' => $author->id,
            'type' => ContentTypeEnum::FAQ,
            'status' => $status,
            'alias' => $alias,
            'system_key' => $systemKey,
            'title' => 'Помощь по площадкам',
            'short_description' => 'Подсказки площадок',
            'full_description' => '<p>Открытый материал</p><script>alert(1)</script>',
            'content_format' => ContentFormatEnum::SAFE_HTML,
        ]);
    }

    public function test_help_catalog_uses_context_and_never_exposes_draft_articles(): void
    {
        $this->faq('venues-public', ContentStatusEnum::PUBLISHED, 'faq.creation.venues');
        $this->faq('venues-private', ContentStatusEnum::DRAFT, 'faq.creation.events');

        $this->getJson(route('faq.help-content', ['context' => 'venues']))
            ->assertOk()->assertJsonPath('section', 'venues')
            ->assertJsonPath('sections.1.key', 'venues')
            ->assertJsonPath('sections.1.articles.0.alias', 'venues-public')
            ->assertJsonMissing(['alias' => 'venues-private']);

        $this->getJson(route('faq.help-content', ['context' => 'venues', 'section' => 'venues', 'article' => 'venues-public']))
            ->assertOk()->assertJsonPath('article.title', 'Помощь по площадкам')
            ->assertJsonPath('article.html', '<p>Открытый материал</p>');

        $this->getJson(route('faq.help-content', ['context' => 'venues', 'section' => 'events', 'article' => 'venues-public']))
            ->assertOk()->assertJsonPath('article', null);
    }

    public function test_context_bar_offers_page_actions_and_contextual_help(): void
    {
        $this->get(route('venues'))->assertOk()
            ->assertSee('data-context-placeholder="Создание площадки"', false)
            ->assertSee('data-context-placeholder="Поиск площадки"', false)
            ->assertSee('data-help-context="venues"', false)
            ->assertSee('data-context-help-dialog', false)
            ->assertSee('support@mskba.ru');
    }

    public function test_only_authenticated_users_can_submit_valid_support_questions(): void
    {
        Mail::fake();
        $this->postJson(route('faq.questions.store'), [
            'topic' => 'venues', 'source_path' => '/venues/my-court', 'message' => 'Подскажите, как можно забронировать площадку?',
        ])->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('faq.questions.store'), [
            'topic' => 'unknown', 'source_path' => '//evil.example', 'message' => 'Коротко',
        ])->assertUnprocessable();
        $this->assertSame(0, SupportQuestion::count());

        $this->postJson(route('faq.questions.store'), [
            'topic' => 'venues', 'source_path' => '/venues/my-court', 'message' => 'Подскажите, как можно забронировать площадку?',
        ])->assertCreated()->assertJsonPath('status', 'saved')
            ->assertJsonPath('message', 'Тестовый вопрос сохранён в Dev. Отправка почты здесь отключена.');

        $question = SupportQuestion::firstOrFail();
        $this->assertSame($user->id, $question->user_id);
        $this->assertSame('venues', $question->topic);
        $this->assertSame('/venues/my-court', $question->source_path);
        Mail::assertNothingOutgoing();
    }

    public function test_approved_email_delivery_uses_configured_recipient(): void
    {
        config()->set('support.deliver_email', true);
        config()->set('support.email', 'support@example.test');
        Mail::fake();
        $this->actingAs(User::factory()->create())->postJson(route('faq.questions.store'), [
            'topic' => 'other', 'source_path' => '/account', 'message' => 'Нужна консультация по вопросу работы сайта.',
        ])->assertCreated();
        $this->assertNotNull(SupportQuestion::firstOrFail()->emailed_at);
        Mail::assertSent(SupportQuestionMail::class, fn (SupportQuestionMail $mail) =>
            $mail->hasTo('support@example.test') && $mail->topicLabel === 'Другой вопрос'
        );
    }
}
