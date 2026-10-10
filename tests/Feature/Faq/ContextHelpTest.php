<?php

namespace Tests\Feature\Faq;

use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Infrastructure\Mail\SupportQuestionMail;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
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
            ->assertSee('data-help-toggle', false)
            ->assertSee('data-help-clear', false)
            ->assertSee('aria-label="Выбрать раздел FAQ"', false)
            ->assertSee('aria-label="Тема вопроса"', false)
            ->assertSee('aria-label="Ваш вопрос"', false)
            ->assertDontSee('data-help-options></datalist>', false)
            ->assertSee('support@mskba.ru');
    }

    public function test_only_authenticated_users_can_email_valid_support_questions_without_persisting_them(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('support.email', 'support@example.test');
        Mail::fake();
        $this->postJson(route('faq.questions.store'), [
            'topic' => 'venues', 'source_path' => '/venues/my-court', 'message' => 'Подскажите, как можно забронировать площадку?',
        ])->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('faq.questions.store'), [
            'topic' => 'unknown', 'source_path' => '//evil.example', 'message' => 'Коротко',
        ])->assertUnprocessable();
        Mail::assertNothingOutgoing();

        $this->postJson(route('faq.questions.store'), [
            'topic' => 'venues', 'source_path' => '/venues/my-court', 'message' => 'Подскажите, как можно забронировать площадку?',
        ])->assertOk()->assertJsonPath('status', 'sent');

        Mail::assertSent(SupportQuestionMail::class, fn (SupportQuestionMail $mail) =>
            $mail->hasTo('support@example.test')
            && $mail->userId === $user->id
            && $mail->topicLabel === 'Площадки и бронирование'
            && $mail->sourcePath === '/venues/my-court'
            && $mail->questionBody === 'Подскажите, как можно забронировать площадку?'
        );
        // Historical table remains from the already-applied migration, but no
        // question gets stored in it (or elsewhere in the application DB).
        $this->assertFalse(Schema::hasTable('support_questions'));
    }

    public function test_log_mailer_is_rejected_rather_than_claiming_success(): void
    {
        config()->set('mail.default', 'log');
        Mail::fake();
        $this->actingAs(User::factory()->create())->postJson(route('faq.questions.store'), [
            'topic' => 'other', 'source_path' => '/venues', 'message' => 'Уточните доступные условия бронирования.',
        ])->assertStatus(503)->assertJsonPath('message', 'Отправка писем на этом сервере пока не настроена. Напишите на почту поддержки.');
        Mail::assertNothingOutgoing();
        $this->assertFalse(Schema::hasTable('support_questions'));
    }

    public function test_transport_failure_is_reported_without_database_fallback(): void
    {
        config()->set('mail.default', 'smtp');
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Transport failed'));
        $this->actingAs(User::factory()->create())->postJson(route('faq.questions.store'), [
            'topic' => 'other', 'source_path' => '/venues', 'message' => 'Уточните доступные условия бронирования.',
        ])->assertStatus(503)->assertJsonPath('message', 'Не удалось отправить вопрос. Попробуйте позже или напишите на почту поддержки.');
        $this->assertFalse(Schema::hasTable('support_questions'));
    }
}
