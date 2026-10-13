<?php

namespace Tests\Feature\Notification;

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\DirectConversation;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChangedBroadcast;
use App\Modules\Team\Application\Services\TeamNotificationService;
use App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use App\Presentation\Theming\ThemeResolver;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class AccountUnifiedMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->createProfile([]);

        return $user;
    }

    private function teamRequest(User $applicant): TeamJoinRequest
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();

        return TeamJoinRequest::query()->create([
            'team_id' => $team->id,
            'user_id' => $applicant->id,
            'status' => TeamJoinRequestStatusEnum::PENDING,
        ]);
    }

    public function test_application_thread_opens_with_a_system_notification_for_both_sides(): void
    {
        $applicant = $this->user();
        $request = $this->teamRequest($applicant);
        $owner = User::query()->findOrFail($request->team->createdByActor->user_id);
        app(TeamNotificationService::class)->joinRequestSubmitted($request->team, $request);

        $this->actingAs($applicant)->get(route('account.notifications'))->assertOk()
            ->assertSee('Заявка в команду')
            ->assertSee(route('account.notifications.threads.team', $request->id), false);
        $this->get(route('account.notifications.threads.team', $request->id))->assertOk()
            ->assertSee('data-conversation-event="application-'.$request->id.'"', false)
            ->assertSee('Заявка отправлена');

        $this->actingAs($owner)->get(route('account.notifications.threads.team', $request->id))
            ->assertOk()->assertSee('Новая заявка в команду');
    }

    public function test_two_team_applications_are_two_threads_and_system_notifications_are_not_duplicates(): void
    {
        $applicant = $this->user();
        $redRequest = $this->teamRequest($applicant);
        $blue = Team::query()->where('alias', 'demo-blue')->firstOrFail();
        $blueRequest = TeamJoinRequest::query()->create([
            'team_id' => $blue->id, 'user_id' => $applicant->id,
            'status' => TeamJoinRequestStatusEnum::PENDING,
        ]);
        app(TeamNotificationService::class)->joinRequestSubmitted($redRequest->team, $redRequest);
        app(TeamNotificationService::class)->joinRequestSubmitted($blue, $blueRequest);

        $response = $this->actingAs($applicant)->get(route('account.notifications'))->assertOk()
            ->assertSee(route('account.notifications.threads.team', $redRequest->id), false)
            ->assertSee(route('account.notifications.threads.team', $blueRequest->id), false);
        $this->assertSame(2, substr_count($response->getContent(), 'data-conversation="'.$redRequest->id.'"')
            + substr_count($response->getContent(), 'data-conversation="'.$blueRequest->id.'"'));
        $this->assertStringNotContainsString('data-conversation="system"', $response->getContent());

        $this->get(route('account.notifications.threads.team', $redRequest->id))
            ->assertOk()->assertSee($redRequest->team->name)->assertDontSee($blue->name);
        $this->get(route('account.notifications.threads.team', $blueRequest->id))
            ->assertOk()->assertSee($blue->name)->assertDontSee($redRequest->team->name);
    }

    public function test_opening_system_chat_does_not_mark_application_notifications_read(): void
    {
        $applicant = $this->user();
        $request = $this->teamRequest($applicant);
        app(TeamNotificationService::class)->joinRequestSubmitted($request->team, $request);
        $system = UserNotification::query()->create([
            'user_id' => $applicant->id, 'type' => 'system', 'status' => 'new',
            'title' => 'Добро пожаловать', 'body' => 'Рады видеть',
        ]);

        $this->actingAs($applicant)->patchJson(route('account.notifications.threads.system.read'))
            ->assertOk()->assertJsonPath('unread_count', 1);

        $this->assertDatabaseHas('user_notifications', ['id' => $system->id, 'status' => 'read']);
        $applicationNotification = UserNotification::query()
            ->where('payload->join_request_id', $request->id)
            ->where('user_id', $applicant->id)->firstOrFail();
        $this->assertSame('new', $applicationNotification->fresh()->status->value);
        $this->patchJson(route('account.notifications.threads.team.read', $request->id))
            ->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_application_chat_accepts_a_message_before_a_question_and_after_rejection_but_not_after_acceptance(): void
    {
        $applicant = $this->user();
        $request = $this->teamRequest($applicant);
        $owner = User::query()->findOrFail($request->team->createdByActor->user_id);
        $route = route('account.notifications.threads.team.messages', $request->id);

        $this->actingAs($applicant)->postJson($route, ['body' => 'Хочу уточнить условия'])
            ->assertOk();
        $this->actingAs($owner)->postJson($route, ['body' => 'Конечно, спрашивайте'])
            ->assertOk();
        $request->update(['status' => TeamJoinRequestStatusEnum::REJECTED]);
        $this->actingAs($applicant)->postJson($route, ['body' => 'Можно уточнить причину?'])
            ->assertOk();
        $this->assertSame(TeamJoinRequestStatusEnum::REJECTED, $request->fresh()->status);
        $this->assertSame(3, $request->messages()->count());

        $request->update(['status' => TeamJoinRequestStatusEnum::ACCEPTED]);
        $this->postJson($route, ['body' => 'Ещё вопрос'])->assertUnprocessable();
        $request->update(['status' => TeamJoinRequestStatusEnum::BLOCKED]);
        $this->postJson($route, ['body' => 'Попытка'])->assertUnprocessable();
        $this->assertSame(3, $request->messages()->count());
    }

    public function test_outsider_cannot_read_or_write_an_application_thread(): void
    {
        $applicant = $this->user();
        $request = $this->teamRequest($applicant);
        $outsider = $this->user();
        $this->actingAs($outsider)
            ->get(route('account.notifications.threads.team', $request->id))->assertForbidden();
        $this->postJson(route('account.notifications.threads.team.messages', $request->id), [
            'body' => 'Посторонний',
        ])->assertForbidden();
    }

    public function test_team_thread_stays_newest_first_and_direct_chat_displays_chronological_messages(): void
    {
        $applicant = $this->user();
        $request = $this->teamRequest($applicant);

        $this->actingAs($applicant)
            ->post(route('account.notifications.threads.team.messages', $request->id), ['body' => 'Первый вопрос по заявке'])
            ->assertRedirect();
        $this->post(route('account.notifications.threads.team.messages', $request->id), ['body' => 'Второй вопрос по заявке'])
            ->assertRedirect();

        $this->get(route('account.notifications.threads.team', $request->id))->assertOk()
            ->assertSeeTextInOrder(['Второй вопрос по заявке', 'Первый вопрос по заявке']);

        $receiver = $this->user();
        $receiver->privacySettings()->create([
            'type' => UserPrivacySettingTypeEnum::MESSAGES,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);
        $this->postJson(route('account.notifications.direct.send', $receiver), ['body' => 'Первое личное сообщение'])
            ->assertCreated();
        $this->postJson(route('account.notifications.direct.send', $receiver), ['body' => 'Второе личное сообщение'])
            ->assertCreated();

        $conversation = DirectConversation::query()->firstOrFail();
        $this->get(route('account.notifications.threads.direct', $conversation->id))->assertOk()
            ->assertSeeTextInOrder(['Первое личное сообщение', 'Второе личное сообщение']);
    }

    public function test_direct_message_composer_respects_privacy_and_creates_separate_conversation(): void
    {
        $sender = $this->user();
        $receiver = $this->user();
        $access = route('account.notifications.direct.access', $receiver);
        $send = route('account.notifications.direct.send', $receiver);
        $this->actingAs($sender)->getJson($access)->assertOk()->assertJsonPath('can_message', false);
        $this->postJson($send, ['body' => 'Привет'])->assertForbidden();
        $receiver->privacySettings()->create([
            'type' => UserPrivacySettingTypeEnum::MESSAGES,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);
        $this->getJson($access)->assertOk()->assertJsonPath('can_message', true);
        $result = $this->postJson($send, ['body' => 'Привет!'])->assertCreated()
            ->assertJsonPath('message', 'Сообщение отправлено.');
        $conversation = DirectConversation::query()->firstOrFail();
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $receiver->id,
            'title' => 'Личное сообщение от '.($sender->username ?: 'Участник'),
        ]);
        $this->actingAs($receiver)->get(route('account.notifications'))->assertOk()
            ->assertSee('Привет!')
            ->assertSee($result->json('conversation_url'), false);
        $this->get($result->json('conversation_url'))->assertOk()->assertSee('Привет!')
            ->assertSee('app-context-quick app-context-actions__trigger app-messages__actions-trigger', false)
            ->assertSee('app-context-quick__label', false)
            ->assertSee('app-messages__compose-footer', false)
            ->assertSee('data-chat-send', false);
        $this->patchJson(route('account.notifications.threads.direct.read', $conversation->id))
            ->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $receiver->id, 'status' => 'read',
        ]);
    }

    public function test_direct_chat_acks_notifications_arriving_after_initial_page_load(): void
    {
        $sender = $this->user();
        $receiver = $this->user();
        $outsider = $this->user();
        $receiver->privacySettings()->create([
            'type' => UserPrivacySettingTypeEnum::MESSAGES,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);
        $this->actingAs($sender)->postJson(route('account.notifications.direct.send', $receiver), [
            'body' => 'Первая реплика',
        ])->assertCreated();
        $thread = DirectConversation::query()->firstOrFail();
        $url = route('account.notifications.threads.direct.read', $thread);
        $this->actingAs($receiver)->get(route('account.notifications.threads.direct', $thread))
            ->assertOk()->assertSee('data-chat-read-url="'.$url.'"', false);
        $this->patchJson($url)->assertOk()->assertJsonPath('unread_count', 0);
        $this->actingAs($sender)->postJson(route('account.notifications.direct.send', $receiver), [
            'body' => 'Реплика, доставленная в уже открытый чат',
        ])->assertCreated();
        $this->actingAs($outsider)->patchJson($url)->assertForbidden();
        $this->actingAs($receiver)->patchJson($url)->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertSame(0, UserNotification::query()->where('user_id', $receiver->id)
            ->where('status', 'new')->where('payload->direct_conversation_id', $thread->id)->count());
        $this->patchJson($url)->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_temporary_direct_entry_is_feature_gated_and_does_not_create_empty_conversations(): void
    {
        $sender = $this->user();
        $this->actingAs($sender);
        config()->set('features.direct_messages.test_entry', false);
        $this->get(route('account.notifications'))->assertOk()
            ->assertDontSee('data-direct-test-open', false)
            ->assertDontSee('data-direct-test-search', false);
        $this->getJson(route('account.notifications.direct.test.users', ['q' => 'pla']))
            ->assertNotFound();

        config()->set('features.direct_messages.test_entry', true);
        $this->get(route('account.notifications'))->assertOk()
            ->assertSee('Новое сообщение · тест')
            ->assertSee('data-direct-test-open', false)
            ->assertSee('data-direct-test-search', false)
            ->assertSee(route('account.notifications.direct.test.users'), false);
        $this->getJson(route('account.notifications.direct.test.users', ['q' => 'a']))
            ->assertOk()->assertJsonPath('users', []);
        $this->assertSame(0, DirectConversation::query()->count());
    }

    public function test_temporary_direct_search_respects_discoverability_privacy_and_blacklist(): void
    {
        config()->set('features.direct_messages.test_entry', true);
        $sender = $this->user();
        $receiver = $this->user();
        $receiver->update(['username' => 'qa_direct_target', 'status' => 'unconfirmed']);
        $sender->update(['status' => 'unconfirmed']);
        foreach ([UserPrivacySettingTypeEnum::PROFILE, UserPrivacySettingTypeEnum::MESSAGES] as $type) {
            $receiver->privacySettings()->create([
                'type' => $type,
                'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
            ]);
        }
        $search = route('account.notifications.direct.test.users', ['q' => 'qa_direct_target']);
        // Neither participant has a confirmed account: this is intentionally
        // allowed for a private message when the recipient consents.
        $this->assertSame('unconfirmed', $sender->fresh()->status->value);
        $this->assertSame('unconfirmed', $receiver->fresh()->status->value);
        $this->actingAs($sender)->getJson($search)->assertOk()
            ->assertJsonCount(1, 'users')->assertJsonPath('users.0.id', $receiver->id);
        $this->getJson(route('account.notifications.direct.access', $receiver))
            ->assertOk()->assertJsonPath('can_message', true);
        $this->assertSame(0, DirectConversation::query()->count());
        $this->postJson(route('account.notifications.direct.send', $receiver), ['body' => 'QA первое сообщение'])
            ->assertCreated();
        $this->post(route('account.notifications.direct.block', $receiver))->assertRedirect();
        $this->getJson($search)->assertOk()->assertJsonPath('users', []);
        $this->actingAs($receiver)->postJson(route('account.notifications.direct.send', $sender), [
            'body' => 'Запрет должен остаться двусторонним',
        ])->assertForbidden();
        $this->actingAs($sender)->delete(route('account.notifications.direct.unblock', $receiver))
            ->assertRedirect();
        $this->getJson($search)->assertOk()->assertJsonCount(1, 'users');
        $receiver->privacySettings()->where('type', UserPrivacySettingTypeEnum::MESSAGES->value)
            ->update(['visibility' => UserPrivacyVisibilityEnum::NOBODY->value]);
        $this->getJson($search)->assertOk()->assertJsonPath('users', []);
    }

    public function test_outgoing_message_grants_the_peer_a_reply_even_when_incoming_is_set_to_nobody(): void
    {
        $olsen = $this->user();
        $simple = $this->user();
        $simple->privacySettings()->create([
            'type' => UserPrivacySettingTypeEnum::MESSAGES,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);
        // Olsen still has NOBODY by default: others cannot initiate a chat with Olsen.
        $this->actingAs($simple)->getJson(route('account.notifications.direct.access', $olsen))
            ->assertOk()->assertJsonPath('can_message', false);
        $this->postJson(route('account.notifications.direct.send', $olsen), ['body' => 'До приглашения'])
            ->assertForbidden();

        $this->actingAs($olsen)->postJson(route('account.notifications.direct.send', $simple), [
            'body' => 'Привет, simple!',
        ])->assertCreated();
        $thread = DirectConversation::query()->firstOrFail();
        $this->actingAs($simple)->getJson(route('account.notifications.direct.access', $olsen))
            ->assertOk()->assertJsonPath('can_message', true);
        $this->getJson(route('account.notifications.threads.direct.state', $thread))
            ->assertOk()->assertJsonPath('can_send', true);
        $this->get(route('account.notifications.threads.direct', $thread))->assertOk()
            ->assertDontSee('hidden placeholder="Введите сообщение…"', false);
        $this->postJson(route('account.notifications.direct.send', $olsen), [
            'body' => 'Привет! Теперь я могу ответить.',
        ])->assertCreated();

        // After simple has replied, Olsen is likewise explicitly allowed to reply,
        // even if simple subsequently closes messages to everyone.
        $simple->privacySettings()->where('type', UserPrivacySettingTypeEnum::MESSAGES->value)
            ->update(['visibility' => UserPrivacyVisibilityEnum::NOBODY->value]);
        $this->actingAs($olsen)->postJson(route('account.notifications.direct.send', $simple), [
            'body' => 'Спасибо!',
        ])->assertCreated();
        $this->assertSame(3, $thread->messages()->count());
        $this->assertDatabaseMissing('user_privacy_settings', [
            'user_id' => $olsen->id, 'type' => UserPrivacySettingTypeEnum::MESSAGES->value,
        ]);
        $this->assertDatabaseHas('user_privacy_settings', [
            'user_id' => $simple->id, 'type' => UserPrivacySettingTypeEnum::MESSAGES->value,
            'visibility' => UserPrivacyVisibilityEnum::NOBODY->value,
        ]);
    }

    public function test_qa_search_shows_only_discoverable_recipients_who_permit_first_contact_or_issued_reply(): void
    {
        config()->set('features.direct_messages.test_entry', true);
        $olsen = $this->user();
        $simple = $this->user();
        $outsider = $this->user();
        $olsen->update(['username' => 'qa_reply_olsen']);
        foreach ([$olsen, $simple] as $user) {
            $user->privacySettings()->create([
                'type' => UserPrivacySettingTypeEnum::PROFILE,
                'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
            ]);
        }
        $simple->privacySettings()->create([
            'type' => UserPrivacySettingTypeEnum::MESSAGES,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);
        $query = route('account.notifications.direct.test.users', ['q' => 'qa_reply_olsen']);
        $this->actingAs($simple)->getJson($query)->assertOk()->assertJsonPath('users', []);
        $this->actingAs($olsen)->postJson(route('account.notifications.direct.send', $simple), [
            'body' => 'Теперь можешь отвечать',
        ])->assertCreated();
        $this->actingAs($simple)->getJson($query)->assertOk()
            ->assertJsonPath('users.0.id', $olsen->id);
        $this->actingAs($outsider)->getJson($query)->assertOk()->assertJsonPath('users', []);
        $this->actingAs($simple)->postJson(route('account.notifications.direct.block', $olsen))
            ->assertOk();
        $this->getJson($query)->assertOk()->assertJsonPath('users', []);
    }

    public function test_existing_conversation_without_recipient_outbound_message_is_not_an_invitation(): void
    {
        $olsen = $this->user();
        $simple = $this->user();
        $simple->privacySettings()->create([
            'type' => UserPrivacySettingTypeEnum::MESSAGES,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);
        $this->actingAs($olsen)->postJson(route('account.notifications.direct.send', $simple), [
            'body' => 'Первое сообщение',
        ])->assertCreated();
        $simple->privacySettings()->where('type', UserPrivacySettingTypeEnum::MESSAGES->value)
            ->update(['visibility' => UserPrivacyVisibilityEnum::NOBODY->value]);
        $this->postJson(route('account.notifications.direct.send', $simple), [
            'body' => 'Попытка продолжить без приглашения от simple',
        ])->assertForbidden();
        $this->actingAs($simple)->getJson(route('account.notifications.direct.access', $olsen))
            ->assertOk()->assertJsonPath('can_message', true);
        $this->actingAs($olsen)->postJson(route('account.notifications.direct.block', $simple))
            ->assertOk();
        $this->actingAs($simple)->postJson(route('account.notifications.direct.send', $olsen), [
            'body' => 'Нельзя обойти блокировку',
        ])->assertForbidden();
        $this->assertDatabaseHas('direct_messages', [
            'kind' => 'blocked', 'sender_user_id' => $olsen->id,
        ]);
        $this->actingAs($olsen)->deleteJson(route('account.notifications.direct.unblock', $simple))
            ->assertOk();
        $this->actingAs($simple)->postJson(route('account.notifications.direct.send', $olsen), [
            'body' => 'Теперь можно ответить',
        ])->assertCreated();
    }

    public function test_block_and_unblock_emit_persisted_ordered_events_and_realtime_state_for_both_sides(): void
    {
        $olsen = $this->user();
        $simple = $this->user();
        foreach ([$olsen, $simple] as $user) {
            $user->privacySettings()->create([
                'type' => UserPrivacySettingTypeEnum::MESSAGES,
                'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
            ]);
        }
        $this->actingAs($olsen)->postJson(route('account.notifications.direct.send', $simple), [
            'body' => 'hi',
        ])->assertCreated();
        $thread = DirectConversation::query()->firstOrFail();
        $state = route('account.notifications.threads.direct.state', $thread);
        Event::fake([ConversationChangedBroadcast::class]);
        $this->actingAs($simple)->postJson(route('account.notifications.direct.block', $olsen))
            ->assertOk();
        // Repeating an already-applied action must not create a duplicate timeline event.
        $this->postJson(route('account.notifications.direct.block', $olsen))->assertOk();
        $this->assertDatabaseHas('direct_messages', [
            'direct_conversation_id' => $thread->id, 'kind' => 'blocked', 'sender_user_id' => $simple->id,
        ]);
        $this->assertSame(1, $thread->messages()->where('kind', 'blocked')->count());
        $this->getJson($state)->assertOk()
            ->assertJsonPath('can_send', false)->assertJsonPath('blocked_by_me', true);
        $this->get(route('account.notifications.threads.direct', $thread))->assertOk()
            ->assertSee('Вы заблокировали пользователя.')
            ->assertSee('data-direct-unblock-action', false);
        $this->actingAs($olsen)->getJson($state)->assertOk()
            ->assertJsonPath('can_send', false)->assertJsonPath('blocked_by_me', false);
        $this->get(route('account.notifications.threads.direct', $thread))->assertOk()
            ->assertSee('Пользователь заблокировал вас.')
            ->assertSee('data-chat-direct-state-url', false)
            ->assertSee('hidden placeholder="Введите сообщение…"', false)
            ->assertSeeInOrder(['data-chat-direct-status', 'app-messages__compose-footer'], false)
            ->assertDontSee('placeholder="Отправка сообщений недоступна"', false);
        $this->postJson(route('account.notifications.direct.send', $simple), ['body' => 'forbidden'])
            ->assertForbidden();
        $this->actingAs($simple)->deleteJson(route('account.notifications.direct.unblock', $olsen))
            ->assertOk();
        $this->deleteJson(route('account.notifications.direct.unblock', $olsen))->assertOk();
        $this->assertSame(1, $thread->messages()->where('kind', 'unblocked')->count());
        Event::assertDispatchedTimes(ConversationChangedBroadcast::class, 2);
        Event::assertDispatched(ConversationChangedBroadcast::class,
            fn (ConversationChangedBroadcast $event): bool => $event->type === 'direct'
                && $event->id === $thread->id && $event->accessChanged);
        $this->getJson($state)->assertOk()
            ->assertJsonPath('can_send', true)->assertJsonPath('blocked_by_me', false);
        $this->actingAs($olsen)->getJson($state)->assertOk()
            ->assertJsonPath('can_send', true);
        $this->get(route('account.notifications.threads.direct', $thread))->assertOk()
            ->assertSee('data-chat-direct-status', false)
            ->assertSeeInOrder(['data-chat-direct-status', 'app-messages__compose-footer'], false);
        $history = $this->getJson(route('account.notifications.threads.direct.history', $thread))->assertOk();
        $this->assertStringContainsString('Пользователь заблокировал вас.', $history->json('html'));
        $this->assertStringContainsString('Пользователь снял блокировку.', $history->json('html'));
        $this->assertStringContainsString('app-messages__direct-event--blocked', $history->json('html'));
        $this->assertStringContainsString('app-messages__direct-event--unblocked', $history->json('html'));
        $this->postJson(route('account.notifications.direct.send', $simple), ['body' => 'После разблокировки'])
            ->assertCreated();
        $outsider = $this->user();
        $this->actingAs($outsider)->getJson($state)->assertForbidden();
        $this->getJson(route('account.notifications.threads.direct.history', $thread))->assertForbidden();
    }

    public function test_direct_messages_have_200_character_limit_for_first_and_followup_messages(): void
    {
        $sender = $this->user();
        $receiver = $this->user();
        $receiver->privacySettings()->create([
            'type' => UserPrivacySettingTypeEnum::MESSAGES,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);
        $this->actingAs($sender);
        $endpoint = route('account.notifications.direct.send', $receiver);
        $this->postJson($endpoint, ['body' => str_repeat('я', 201)])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertSame(0, DirectConversation::query()->count());
        $this->postJson($endpoint, ['body' => str_repeat('я', 200)])
            ->assertCreated();
        $this->postJson($endpoint, ['body' => str_repeat('я', 201)])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertDatabaseCount('direct_messages', 1);
        $page = $this->get(route('account.notifications.threads.direct', DirectConversation::query()->firstOrFail()))
            ->assertOk()->assertSee('maxlength="200"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'maxlength="200"'));
    }

    public function test_direct_rate_limit_allows_three_messages_per_ten_seconds_per_sender_across_recipients(): void
    {
        $sender = $this->user();
        $first = $this->user();
        $second = $this->user();
        foreach ([$first, $second] as $recipient) {
            $recipient->privacySettings()->create([
                'type' => UserPrivacySettingTypeEnum::MESSAGES,
                'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
            ]);
        }
        $this->actingAs($sender);
        $firstEndpoint = route('account.notifications.direct.send', $first);
        $secondEndpoint = route('account.notifications.direct.send', $second);
        $this->postJson($firstEndpoint, ['body' => 'раз'])->assertCreated();
        $this->postJson($firstEndpoint, ['body' => 'два'])->assertCreated();
        $this->postJson($secondEndpoint, ['body' => 'три'])->assertCreated();
        $limitResponse = $this->postJson($secondEndpoint, ['body' => 'четыре'])
            ->assertStatus(429)->assertJsonPath('message', 'Можно отправить не более 3 личных сообщений за 10 секунд. Повторите попытку через 10 сек.');
        $this->assertGreaterThan(0, (int) $limitResponse->headers->get('Retry-After'));
        $this->assertDatabaseCount('direct_messages', 3);
        // A different sender has its own quota, even while the first is throttled.
        $otherSender = $this->user();
        $this->actingAs($otherSender)->postJson($secondEndpoint, ['body' => 'другой участник'])
            ->assertCreated();
        $this->actingAs($sender);
        $this->travel(11)->seconds();
        $this->postJson($secondEndpoint, ['body' => 'после ожидания'])->assertCreated();
        $this->assertDatabaseCount('direct_messages', 5);
    }

    public function test_direct_message_blacklist_is_bilateral_and_reversible(): void
    {
        $sender = $this->user();
        $receiver = $this->user();
        foreach ([$sender, $receiver] as $user) {
            $user->privacySettings()->create([
                'type' => UserPrivacySettingTypeEnum::MESSAGES,
                'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
            ]);
        }
        $this->actingAs($sender)->postJson(route('account.notifications.direct.send', $receiver), [
            'body' => 'До блокировки',
        ])->assertCreated();
        $conversation = DirectConversation::query()->firstOrFail();
        $this->post(route('account.notifications.direct.block', $receiver))->assertRedirect();
        $this->assertDatabaseHas('direct_message_blocks', [
            'blocker_user_id' => $sender->id, 'blocked_user_id' => $receiver->id,
        ]);
        $this->postJson(route('account.notifications.direct.send', $receiver), ['body' => 'Заблокировано'])
            ->assertForbidden();
        $this->actingAs($receiver)->postJson(route('account.notifications.direct.send', $sender), [
            'body' => 'Ответ заблокирован',
        ])->assertForbidden();
        $this->get(route('account.notifications.threads.direct', $conversation))->assertOk()
            ->assertSee('До блокировки'); // Old correspondence is still accessible.
        $this->actingAs($sender)->delete(route('account.notifications.direct.unblock', $receiver))
            ->assertRedirect();
        $this->postJson(route('account.notifications.direct.send', $receiver), ['body' => 'После разблокировки'])
            ->assertCreated();
    }

    public function test_compact_direct_chat_shows_only_authorized_conversations_in_side_rail(): void
    {
        $viewer = $this->user();
        $peer = $this->user();
        $other = $this->user();
        $stranger = $this->user();
        $ownThread = DirectConversation::query()->create([
            'user_low_id' => min($viewer->id, $peer->id),
            'user_high_id' => max($viewer->id, $peer->id),
        ]);
        $ownThread->messages()->create(['sender_user_id' => $peer->id, 'body' => 'Публично только участникам']);
        $otherThread = DirectConversation::query()->create([
            'user_low_id' => min($other->id, $stranger->id),
            'user_high_id' => max($other->id, $stranger->id),
        ]);
        $otherThread->messages()->create(['sender_user_id' => $stranger->id, 'body' => 'Секрет третьего лица']);

        $url = route('account.notifications.rail');
        $response = $this->actingAs($viewer)->get(route('account.notifications.threads.direct', $ownThread))->assertOk()
            ->assertSee('app-account-layout--compact-sidebar', false)
            ->assertSee('app-account-nav__icon', false)
            ->assertSee('data-conversation-rail', false)
            ->assertSee('data-chat-date=', false)
            ->assertSee('data-rail-conversation="direct-'.$ownThread->id.'"', false)
            ->assertDontSee('Секрет третьего лица');
        $this->assertSame(1, substr_count($response->getContent(), 'data-rail-conversation="direct-'.$ownThread->id.'"'));
        $markup = $response->getContent();
        $this->assertLessThan(strpos($markup, '<section class="app-messages app-messages--thread"'),
            strpos($markup, '<aside class="panel app-messages__rail"'),
            'The conversation rail must precede the chat in DOM order.');
        $list = $this->getJson($url.'?active=direct-'.$ownThread->id)->assertOk();
        $this->assertStringContainsString('Публично только участникам', $list->json('html'));
        $this->assertStringNotContainsString('Секрет третьего лица', $list->json('html'));
        $this->getJson($url.'?active=<img>')->assertUnprocessable();
        $this->actingAs($stranger)->getJson($url)->assertOk()
            ->assertDontSee('Публично только участникам');
    }

    public function test_direct_history_uses_private_cursor_pagination_by_tens(): void
    {
        $first = $this->user();
        $second = $this->user();
        $outsider = $this->user();
        $conversation = DirectConversation::query()->create([
            'user_low_id' => min($first->id, $second->id),
            'user_high_id' => max($first->id, $second->id),
        ]);
        foreach (range(1, 24) as $i) {
            $conversation->messages()->create(['sender_user_id' => $first->id, 'body' => 'Message '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }
        $url = route('account.notifications.threads.direct.history', $conversation);
        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $response = $this->actingAs($first)->getJson($url)->assertOk();
        $this->assertSame(10, substr_count($response->json('html'), 'data-chat-entry='));
        $this->assertStringContainsString('Message 24', $response->json('html'));
        $this->assertStringNotContainsString('Message 14', $response->json('html'));
        $older = $this->getJson($url.'?before='.$response->json('next_before'))->assertOk();
        $this->assertSame(10, substr_count($older->json('html'), 'data-chat-entry='));
        $this->assertStringContainsString('Message 05', $older->json('html'));
        $this->assertStringNotContainsString('Message 15', $older->json('html'));
    }

    public function test_direct_message_is_private_and_locks_when_receiver_disables_messages(): void
    {
        $sender = $this->user();
        $receiver = $this->user();
        $outsider = $this->user();
        $privacy = $receiver->privacySettings()->create([
            'type' => UserPrivacySettingTypeEnum::MESSAGES,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);
        $this->actingAs($sender)->postJson(route('account.notifications.direct.send', $receiver), [
            'body' => 'Закрытый текст',
        ])->assertCreated();
        $conversation = DirectConversation::query()->firstOrFail();
        $this->actingAs($outsider)
            ->get(route('account.notifications.threads.direct', $conversation))->assertForbidden();
        $privacy->update(['visibility' => UserPrivacyVisibilityEnum::NOBODY]);
        $this->actingAs($sender)->postJson(route('account.notifications.direct.send', $receiver), [
            'body' => 'Новая попытка',
        ])->assertForbidden();
        $this->assertSame(1, $conversation->messages()->count());
    }
}
