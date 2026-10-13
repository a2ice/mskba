<?php

namespace Tests\Feature\Notification;

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\DirectConversation;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Team\Application\Services\TeamNotificationService;
use App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use App\Presentation\Theming\ThemeResolver;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
