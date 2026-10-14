<?php

namespace Tests\Feature\Faq;

use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Notification\Domain\Models\SupportTicket;
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

        // The root has just FAQ in its breadcrumb data. The UI hides this
        // redundant trail and the section selector, showing section cards.
        $this->getJson(route('faq.help-content'))
            ->assertOk()
            ->assertJsonPath('section', null)
            ->assertJsonCount(1, 'breadcrumbs')
            ->assertJsonPath('breadcrumbs.0.label', 'FAQ');

        $this->getJson(route('faq.help-content', ['context' => 'venues']))
            ->assertOk()
            ->assertJsonCount(2, 'breadcrumbs')
            ->assertJsonPath('breadcrumbs.1.label', 'Площадки');

        $this->getJson(route('faq.help-content', ['context' => 'venues']))
            ->assertOk()->assertJsonPath('section', 'venues')
            ->assertJsonPath('sections.1.key', 'venues')
            ->assertJsonPath('sections.1.articles.0.alias', 'venues-public')
            ->assertJsonMissing(['alias' => 'venues-private']);

        $this->getJson(route('faq.help-content', ['context' => 'venues', 'section' => 'venues', 'article' => 'venues-public']))
            ->assertOk()->assertJsonPath('article.title', 'Помощь по площадкам')
            ->assertJsonCount(3, 'breadcrumbs')
            ->assertJsonPath('breadcrumbs.2.label', 'Помощь по площадкам')
            ->assertJsonPath('article.html', '<p>Открытый материал</p>');

        $this->getJson(route('faq.help-content', ['context' => 'venues', 'section' => 'events', 'article' => 'venues-public']))
            ->assertOk()->assertJsonPath('article', null);
    }

    public function test_context_bar_offers_page_actions_and_contextual_help(): void
    {
        $this->get(route('venues'))->assertOk()
            ->assertSee('data-context-placeholder="Создание площадки"', false)
            ->assertSee('data-context-placeholder="Поиск площадки"', false)
            ->assertSee('data-app-context-back', false)
            ->assertSee('class="app-context-quick app-context-back"', false)
            ->assertSee('aria-label="Назад"', false)
            ->assertSee('class="app-context-quick app-context-actions__trigger"', false)
            ->assertSee('data-help-context="venues"', false)
            ->assertSee('data-context-help-dialog', false)
            ->assertSee('data-help-toggle', false)
            ->assertSee('data-help-clear', false)
            ->assertSee('aria-label="Выбрать раздел FAQ"', false)
            ->assertSee('class="select-control app-context-help__topic-control"', false)
            ->assertSee('aria-label="Тема вопроса"', false)
            ->assertSee('aria-label="Ваш вопрос"', false)
            ->assertDontSee('data-help-options></datalist>', false)
            ->assertSee('support@mskba.ru');
    }

    public function test_faq_creates_a_support_conversation_with_topic_and_source_page(): void
    {
        config()->set('mail.default', 'log');
        Mail::fake();
        $customer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $lead = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->postJson(route('faq.questions.store'), [
            'topic' => 'venues',
            'source_path' => '/venues/my-court',
            'message' => 'Подскажите, как можно забронировать площадку?',
        ])->assertUnauthorized();

        $this->actingAs($customer)->postJson(route('faq.questions.store'), [
            'topic' => 'unknown',
            'source_path' => '//evil.example',
            'message' => 'Коротко',
        ])->assertUnprocessable();
        $this->assertSame(0, SupportTicket::query()->count());

        $response = $this->postJson(route('faq.questions.store'), [
            'topic' => 'venues',
            'source_path' => '/venues/my-court',
            'message' => 'Подскажите, как можно забронировать площадку?',
        ])->assertCreated()->assertJsonPath('status', 'created');

        $ticket = SupportTicket::query()->latest('id')->firstOrFail();
        $thread = $ticket->thread()->firstOrFail();
        $this->assertSame($customer->id, (int) $ticket->user_id);
        $this->assertSame('FAQ — Площадки и бронирование', $ticket->subject);
        $this->assertSame('open', $ticket->status);
        $this->assertSame('support', $thread->type);
        $this->assertSame($lead->id, (int) $thread->lead_user_id);
        $this->assertSame(1, $thread->messages()->count());
        $this->assertDatabaseHas('request_thread_messages', [
            'request_thread_id' => $thread->id,
            'sender_user_id' => $customer->id,
            'body' => "Страница обращения: /venues/my-court\n\nПодскажите, как можно забронировать площадку?",
        ]);
        $response->assertJsonPath('redirect_url', route('account.notifications.threads.support', $ticket));
        $this->get(route('account.notifications.threads.support', $ticket))
            ->assertOk()->assertSee('Подскажите, как можно забронировать площадку?');
        $this->actingAs($lead)->get(route('account.notifications'))
            ->assertOk()->assertSee('Техподдержка №'.$ticket->id);
        Mail::assertNothingOutgoing();
    }

    public function test_faq_support_requests_do_not_depend_on_mail_delivery_and_respect_account_blocking(): void
    {
        config()->set('mail.default', 'log');
        Mail::fake();

        $customer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($customer)->postJson(route('faq.questions.store'), [
            'topic' => 'other',
            'source_path' => '/venues',
            'message' => 'Уточните доступные условия бронирования.',
        ])->assertCreated();
        $this->assertSame(1, SupportTicket::query()->count());

        $customer->update(['status' => UserStatusEnum::BLOCKED]);
        $this->postJson(route('faq.questions.store'), [
            'topic' => 'other',
            'source_path' => '/venues',
            'message' => 'Уточните доступные условия бронирования.',
        ])->assertForbidden();
        $this->assertSame(1, SupportTicket::query()->count());
        Mail::assertNothingOutgoing();
    }
}
