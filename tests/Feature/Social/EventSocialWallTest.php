<?php

namespace Tests\Feature\Social;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EventSocialWallTest extends TestCase
{
    use RefreshDatabase;

    private function event(): Event
    {
        $organizer = $this->confirmedUser();
        $actor = Actor::factory()->create(['type' => ActorTypeEnum::USER->value, 'user_id' => $organizer->id]);
        return Event::factory()->create([
            'organizer_actor_id' => $actor->id,
            'status' => EventStatusEnum::PUBLISHED,
            // Keep the generic social-wall fixtures independent of the
            // optional nested Game model.
            'type' => EventTypeEnum::TRAINING,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => now()->addDays(4),
            'ends_at' => now()->addDays(4)->addHours(2),
        ]);
    }

    private function confirmedUser(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'username' => 'court_user_'.Str::lower(Str::random(9)),
        ]);
    }

    private function postWall(Event $event, string $text, ?string $requestId = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('events.wall.store', $event->routeIdentifier()), [
            'body' => $text,
            'client_request_id' => $requestId ?? (string) Str::uuid(),
        ]);
    }

    public function test_rsvp_changes_are_atomic_wall_messages_and_current_counts_not_history_counts(): void
    {
        $event = $this->event();
        $user = $this->confirmedUser();
        $url = route('events.participation', $event->routeIdentifier());

        $this->actingAs($user)->patch($url, ['status' => 'confirmed'])->assertRedirect();
        $this->actingAs($user)->patch($url, ['status' => 'left'])->assertRedirect();
        $this->actingAs($user)->getJson(route('events.wall.index', $event->routeIdentifier()))
            ->assertOk()->assertJsonPath('can_post', false);
        // Public event discussion is readable for discovery; posting still
        // requires an active participant or organizer and private walls stay closed.
        $organizer = $event->organizerActor->user;
        $this->actingAs($organizer)->getJson(route('events.wall.index', $event->routeIdentifier()))
            ->assertOk()->assertJsonPath('counts.confirmed', 0)
            ->assertJsonPath('counts.left', 1)
            ->assertJsonCount(2, 'entries')
            ->assertJsonPath('entries.0.kind', 'event_rsvp_changed')
            ->assertJsonPath('entries.0.rsvp_status', 'confirmed')
            ->assertJsonPath('entries.1.rsvp_status', 'left')
            ->assertJsonPath('entries.1.text', $user->username.' не пойдёт на мероприятие.');

        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id, 'user_id' => $user->id, 'status' => 'left',
        ]);
        $this->assertSame(2, DB::table('social_action_logs')->where('user_id', $user->id)->count());

        // Repeated no-op response does not consume a token or add an entry.
        $this->actingAs($user)->patch($url, ['status' => 'left'])->assertRedirect();
        $this->assertSame(2, DB::table('social_action_logs')->where('user_id', $user->id)->count());
        $this->assertSame(2, DB::table('social_wall_entries')->where('scope_type', 'event')->count());
    }

    public function test_event_wall_prefers_nickname_and_exposes_historical_rsvp_status(): void
    {
        $event = $this->event();
        $user = $this->confirmedUser();
        $user->forceFill(['nickname' => 'court_nick'])->save();
        $url = route('events.participation', $event->routeIdentifier());

        $this->actingAs($user)->patch($url, ['status' => 'confirmed'])->assertRedirect();
        $this->actingAs($user)->patch($url, ['status' => 'tentative'])->assertRedirect();
        $this->actingAs($user)->patch($url, ['status' => 'left'])->assertRedirect();
        $this->getJson(route('events.wall.index', $event->routeIdentifier()))
            ->assertOk()
            ->assertJsonCount(3, 'entries')
            ->assertJsonPath('entries.0.actor', 'court_nick')
            ->assertJsonPath('entries.0.text', 'court_nick пойдёт на мероприятие.')
            ->assertJsonPath('entries.0.rsvp_status', 'confirmed')
            ->assertJsonPath('entries.1.text', 'court_nick пока думает, идти ли на мероприятие.')
            ->assertJsonPath('entries.1.rsvp_status', 'tentative')
            ->assertJsonPath('entries.2.text', 'court_nick не пойдёт на мероприятие.')
            ->assertJsonPath('entries.2.rsvp_status', 'left');
    }

    public function test_edit_own_comment_keeps_only_three_latest_versions_and_original_position(): void
    {
        $event = $this->event();
        $author = $event->organizerActor->user;
        $this->actingAs($author);
        $entry = $this->postWall($event, 'Первая версия')->assertCreated()->json('entry');
        $id = $entry['id'];
        $url = $entry['edit_url'];
        $this->assertTrue($entry['can_edit']);
        $this->assertSame(1, $entry['version']);
        $this->assertFalse($entry['edited']);

        $this->patchJson($url, ['body' => '  Первая версия  ', 'version' => 1])
            ->assertOk()->assertJsonPath('entry.version', 1)->assertJsonPath('entry.edited', false);
        $this->assertSame(1, DB::table('social_action_logs')->where('user_id', $author->id)->count());

        $this->patchJson($url, ['body' => 'Вторая версия', 'version' => 1])
            ->assertOk()->assertJsonPath('entry.version', 2)->assertJsonPath('entry.id', $id);
        // An identical retry is a no-op, even with the original version number.
        $this->patchJson($url, ['body' => 'Вторая версия', 'version' => 1])
            ->assertOk()->assertJsonPath('entry.version', 2);
        $this->patchJson($url, ['body' => 'Конфликт', 'version' => 1])->assertConflict();
        $this->patchJson($url, ['body' => 'Третья версия', 'version' => 2])
            ->assertOk()->assertJsonPath('entry.version', 3);
        $this->patchJson($url, ['body' => 'Четвёртая версия', 'version' => 3])->assertStatus(429);

        $this->travel(61)->seconds();
        $this->patchJson($url, ['body' => 'Четвёртая версия', 'version' => 3])
            ->assertOk()->assertJsonPath('entry.version', 4)
            ->assertJsonPath('entry.edited', true)->assertJsonPath('entry.date', now()->format('d.m.Y H:i'));
        $this->getJson(route('events.wall.index', $event->routeIdentifier()))
            ->assertOk()->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.id', $id)
            ->assertJsonPath('entries.0.text', 'Четвёртая версия')
            ->assertJsonPath('entries.0.edited', true)
            ->assertJsonPath('entries.0.date', now()->format('d.m.Y H:i'))
            ->assertJsonMissingPath('entries.0.previous_versions');

        $stored = \App\Modules\Social\Domain\Models\SocialWallEntry::findOrFail($id);
        $this->assertSame('Четвёртая версия', $stored->body);
        $this->assertSame([2, 3], array_column($stored->metadata['previous_versions'], 'version'));
        $this->assertSame(['Вторая версия', 'Третья версия'], array_column($stored->metadata['previous_versions'], 'body'));
    }

    public function test_event_wall_loads_latest_ten_then_older_visible_entries_without_gaps(): void
    {
        $event = $this->event();
        $otherEvent = $this->event();
        $author = $event->organizerActor->user;
        $this->actingAs($author);
        $model = \App\Modules\Social\Domain\Models\SocialWallEntry::class;
        $ids = [];
        for ($i = 1; $i <= 24; $i++) {
            $row = $model::query()->create([
                'scope_type' => 'event', 'scope_id' => $event->id,
                'actor_user_id' => $author->id, 'kind' => 'comment',
                'body' => 'Сообщение '.$i,
                'deleted_at' => in_array($i, [5, 15], true) ? now() : null,
            ]);
            if ($row->deleted_at === null) {
                $ids[] = $row->id;
            }
            $model::query()->create([
                'scope_type' => 'event', 'scope_id' => $otherEvent->id,
                'kind' => 'comment', 'body' => 'Другое мероприятие',
            ]);
        }
        $url = route('events.wall.index', $event->routeIdentifier());
        $latest = $this->getJson($url)->assertOk()->assertJsonCount(10, 'entries')
            ->assertJsonPath('has_older', true);
        $this->assertSame(array_slice($ids, 12), array_column($latest->json('entries'), 'id'));

        $middle = $this->getJson($url.'?before_id='.$ids[12])->assertOk()
            ->assertJsonCount(10, 'entries')->assertJsonPath('has_older', true);
        $this->assertSame(array_slice($ids, 2, 10), array_column($middle->json('entries'), 'id'));

        $first = $this->getJson($url.'?before_id='.$ids[2])->assertOk()
            ->assertJsonCount(2, 'entries')->assertJsonPath('has_older', false);
        $this->assertSame(array_slice($ids, 0, 2), array_column($first->json('entries'), 'id'));
        $this->getJson($url.'?before_id='.$ids[0])->assertOk()
            ->assertJsonCount(0, 'entries')->assertJsonPath('has_older', false);

        $forward = $this->getJson($url.'?after_id='.$ids[19])->assertOk()
            ->assertJsonCount(2, 'entries');
        $this->assertSame(array_slice($ids, 20), array_column($forward->json('entries'), 'id'));
        $this->getJson($url.'?after_id=0&before_id='.$ids[12])->assertUnprocessable();
    }

    public function test_edit_comment_rejects_other_authors_wrong_scope_hidden_deleted_and_system_entries(): void
    {
        $event = $this->event();
        $otherEvent = $this->event();
        $author = $event->organizerActor->user;
        $this->actingAs($author);
        $entry = $this->postWall($event, 'Исходное сообщение')->assertCreated()->json('entry');
        $url = $entry['edit_url'];
        $payload = ['body' => 'Изменение', 'version' => 1];

        $other = $this->confirmedUser();
        $this->actingAs($other)->patch(route('events.participation', $event->routeIdentifier()), ['status' => 'confirmed'])->assertRedirect();
        $this->patchJson($url, $payload)->assertNotFound();
        $wall = $this->getJson(route('events.wall.index', $event->routeIdentifier()))->assertOk();
        $wall->assertJsonPath('entries.0.can_edit', false)->assertJsonPath('entries.1.can_edit', false);

        $this->actingAs($author);
        $this->patchJson(route('events.wall.update', [$otherEvent->routeIdentifier(), $entry['id']]), $payload)->assertForbidden();
        $this->patchJson(route('events.wall.update', [$event->routeIdentifier(), $wall->json('entries.1.id')]), $payload)->assertNotFound();
        $this->patchJson($url, ['body' => str_repeat('Я', 201), 'version' => 1])->assertUnprocessable();

        DB::table('social_wall_entries')->where('id', $entry['id'])->update(['hidden_at' => now()]);
        $this->patchJson($url, $payload)->assertNotFound();
        $this->getJson(route('events.wall.index', $event->routeIdentifier()))->assertJsonPath('entries.0.can_edit', false);
        DB::table('social_wall_entries')->where('id', $entry['id'])->update(['hidden_at' => null, 'deleted_at' => now()]);
        $this->patchJson($url, $payload)->assertNotFound();
        $this->assertDatabaseHas('social_wall_entries', ['id' => $entry['id'], 'body' => 'Исходное сообщение']);
    }

    public function test_comment_and_rsvp_share_three_per_sixty_seconds_globally(): void
    {
        $event = $this->event();
        $other = $this->event();
        $user = $this->confirmedUser();

        $this->actingAs($user)->patch(route('events.participation', $event->routeIdentifier()), [
            'status' => 'confirmed',
        ])->assertRedirect();

        $id = (string) Str::uuid();
        $this->postWall($event, 'Встречаемся у входа', $id)->assertCreated();
        $this->postWall($event, 'Встречаемся у входа', $id)->assertCreated(); // idempotent
        $this->postWall($event, 'Беру с собой мяч')->assertCreated();

        $this->actingAs($user)->patchJson(route('events.participation', $other->routeIdentifier()), [
            'status' => 'tentative',
        ])->assertStatus(429)->assertJsonStructure(['retry_after']);

        $this->assertSame(3, DB::table('social_action_logs')->where('user_id', $user->id)->count());
        $this->assertDatabaseMissing('event_participants', [
            'event_id' => $other->id, 'user_id' => $user->id,
        ]);
        $this->assertSame(3, DB::table('social_wall_entries')->count());
    }

    public function test_author_soft_deletes_message_and_all_versions_from_public_wall(): void
    {
        $event = $this->event();
        $user = $this->confirmedUser();
        $other = $this->confirmedUser();
        $this->actingAs($user)->patch(route('events.participation', $event->routeIdentifier()), [
            'status' => 'tentative',
        ])->assertRedirect();

        $post = $this->postWall($event, 'В пятницу после семи')->assertCreated()->json('entry');
        $this->patchJson($post['edit_url'], ['body' => 'В пятницу после восьми', 'version' => 1])->assertOk();
        $this->travel(61)->seconds();
        $this->actingAs($other)->deleteJson($post['delete_url'])->assertNotFound();
        $this->actingAs($user)->deleteJson($post['delete_url'])
            ->assertOk()->assertJsonPath('entry.deleted', true)
            ->assertJsonPath('entry.text', null);

        $this->actingAs($user)->getJson(route('events.wall.index', $event->routeIdentifier()))
            ->assertOk()->assertJsonCount(1, 'entries')
            ->assertJsonMissing(['id' => $post['id']]);
        $this->getJson(route('events.wall.index', [$event->routeIdentifier(), 'after_id' => 0]))->assertOk()->assertJsonCount(1, 'entries');

        $this->assertDatabaseHas('social_wall_entries', [
            'id' => $post['id'], 'body' => 'В пятницу после восьми', 'deleted_by_user_id' => $user->id,
        ]);
        $stored = \App\Modules\Social\Domain\Models\SocialWallEntry::findOrFail($post['id']);
        $this->assertNotNull($stored->deleted_at);
        $this->assertSame('В пятницу после семи', $stored->metadata['previous_versions'][0]['body']);
        $this->patchJson($post['edit_url'], ['body' => 'Возврат', 'version' => 2])->assertNotFound();
    }

    public function test_comment_validation_and_private_wall_access(): void
    {
        $event = $this->event();
        $user = $this->confirmedUser();
        $this->actingAs($user)->postJson(route('events.wall.store', $event->routeIdentifier()), [
            'body' => str_repeat('Я', 201), 'client_request_id' => (string) Str::uuid(),
        ])->assertUnprocessable();

        $this->postWall($event, 'Без участия нельзя')->assertForbidden();

        $event->update(['visibility' => EventVisibilityEnum::PRIVATE]);
        $this->actingAs($user)->getJson(route('events.wall.index', $event->routeIdentifier()))
            ->assertNotFound();
    }

    public function test_legacy_event_page_reuses_shared_discussion_and_composer(): void
    {
        $event = $this->event();
        $this->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('data-event-discussion-legacy', false)
            ->assertSee('data-venue-play-messages', false)
            ->assertSee('data-chat-composer', false)
            ->assertSee('data-venue-play-delete-dialog', false)
            ->assertDontSee('data-event-wall-tab', false);
    }

    public function test_organizer_can_write_without_attendance_or_confirmed_account_but_blocked_users_cannot(): void
    {
        $event = $this->event();
        $organizer = $event->organizerActor->user;
        $organizer->update(['status' => UserStatusEnum::UNCONFIRMED]);
        $wall = route('events.wall.index', [$event->routeIdentifier(), 'include_roster' => 1]);

        $this->actingAs($organizer)->getJson($wall)->assertOk()
            ->assertJsonPath('can_post', true)
            ->assertJsonPath('roster.viewer_is_organizer', true);
        $this->postWall($event, 'Сообщение организатора')->assertCreated();

        $other = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $this->actingAs($other)->getJson($wall)->assertOk()->assertJsonPath('can_post', false);
        $this->postWall($event, 'Нет доступа')->assertForbidden();

        $organizer->update(['status' => UserStatusEnum::BLOCKED]);
        // Global blocked-account middleware rejects the request before the wall controller.
        $this->actingAs($organizer)->getJson($wall)->assertForbidden();
    }

    public function test_preview_roster_updates_atomically_with_rsvp_and_keeps_organizer_first(): void
    {
        $event = $this->event();
        $event->update(['max_participants' => 15, 'participation_confirmation_version' => 1]);
        $organizer = $event->organizerActor->user;
        $user = $this->confirmedUser();
        $event->participants()->create([
            'user_id' => $organizer->id,
            'role' => 'organizer',
            'status' => 'confirmed',
            'joined_at' => now(),
            'confirmation_version' => $event->participation_confirmation_version,
        ]);
        $url = route('events.participation', $event->routeIdentifier());
        $wall = route('events.wall.index', [$event->routeIdentifier(), 'include_roster' => 1]);

        $this->getJson($wall)->assertOk()
            ->assertJsonCount(1, 'roster.members')
            ->assertJsonPath('roster.members.0.organizer', true)
            ->assertJsonPath('roster.max_participants', 15)
            ->assertJsonPath('roster.viewer_status', null);

        $this->actingAs($user)->patchJson($url, ['status' => 'confirmed', 'include_roster' => true])->assertOk()
            ->assertJsonCount(2, 'roster.members')
            ->assertJsonPath('roster.members.0.organizer', true)
            ->assertJsonPath('roster.members.1.is_viewer', true)
            ->assertJsonPath('roster.members.1.status', 'confirmed')
            ->assertJsonPath('roster.viewer_status', 'confirmed');

        $response = $this->patchJson($url, ['status' => 'tentative', 'include_roster' => true])->assertOk()
            ->assertJsonCount(2, 'roster.members')
            ->assertJsonPath('roster.members.1.status', 'tentative')
            ->assertJsonPath('roster.viewer_status', 'tentative');
        $this->assertNotNull($response->json('roster.members.1.decision_at'));

        $this->patchJson($url, ['status' => 'left', 'include_roster' => true])->assertOk()
            ->assertJsonCount(1, 'roster.members')
            ->assertJsonPath('roster.viewer_status', 'left');

        $this->getJson($wall)->assertOk()->assertJsonCount(1, 'roster.members');
        $event->update(['visibility' => EventVisibilityEnum::PRIVATE]);
        $this->getJson($wall)->assertNotFound();
    }

    public function test_report_feedback_is_separate_and_likes_count_current_votes(): void
    {
        $event = $this->event();
        $author = $event->organizerActor->user;
        $this->actingAs($author);
        $this->postWall($event, 'До игры')->assertCreated();
        $reaction = route('events.report.reaction', $event->routeIdentifier());
        $report = route('events.wall.index', ['event' => $event->routeIdentifier(), 'report' => 1]);
        $postReport = route('events.wall.store', ['event' => $event->routeIdentifier(), 'report' => 1]);
        $this->putJson($reaction, ['reaction'=>'like'])->assertForbidden();
        $event->forceFill(['starts_at'=>now()->subHours(3), 'ends_at'=>now()->subHour(), 'status'=>EventStatusEnum::COMPLETED])->save();
        $this->postWall($event, 'Закрыто')->assertForbidden();
        $this->putJson($reaction, ['reaction'=>'like'])->assertOk()->assertJsonPath('likes', 1);
        $this->putJson($reaction, ['reaction'=>'like'])->assertOk()->assertJsonPath('likes', 1);
        $this->putJson($reaction, ['reaction'=>'dislike'])->assertOk()->assertJsonPath('likes', 0);
        $this->travel(61)->seconds();
        $this->postJson($postReport, ['body'=>'Спасибо!', 'client_request_id'=>(string)Str::uuid()])->assertCreated();
        $this->getJson($report)->assertOk()->assertJsonCount(3, 'entries')->assertJsonPath('can_post', true);
        $this->getJson(route('events.wall.index', $event->routeIdentifier()))->assertOk()->assertJsonCount(1, 'entries')->assertJsonPath('can_post', false);
        $event->forceFill(['report_comments_enabled'=>false])->save();
        $this->postJson($postReport, ['body'=>'Закрыто', 'client_request_id'=>(string)Str::uuid()])->assertForbidden();
        $this->putJson($reaction, ['reaction'=>null])->assertOk()->assertJsonPath('likes', 0);
        $this->actingAs($this->confirmedUser())->putJson($reaction, ['reaction'=>'like'])->assertForbidden();
        $this->getJson($report)->assertOk()->assertJsonPath('can_react', false)->assertJsonPath('can_post', false);
    }

    public function test_report_reaction_and_optional_comment_are_atomic_and_retry_safe(): void
    {
        $event = $this->event();
        $event->forceFill(['starts_at'=>now()->subHours(3), 'ends_at'=>now()->subHour(), 'status'=>EventStatusEnum::COMPLETED])->save();
        $user = $event->organizerActor->user;
        $this->actingAs($user);
        $reaction = route('events.report.reaction', $event->routeIdentifier());
        $report = route('events.wall.index', ['event' => $event->routeIdentifier(), 'report' => 1]);
        $payload = ['reaction'=>'dislike', 'body'=>'Можно улучшить.', 'client_request_id'=>(string) Str::uuid()];
        $first = $this->putJson($reaction, $payload)->assertOk()
            ->assertJsonPath('likes', 0)->assertJsonPath('dislikes', 1)
            ->assertJsonPath('entry.text', 'Можно улучшить.')->json('entry.id');
        $this->putJson($reaction, $payload)->assertOk()->assertJsonPath('entry.id', $first);
        $this->getJson($report)->assertOk()->assertJsonCount(2, 'entries')->assertJsonPath('dislikes', 1);
        $this->putJson($reaction, ['reaction'=>'like'])->assertOk()->assertJsonPath('likes', 1)->assertJsonPath('dislikes', 0);
        $event->forceFill(['report_comments_enabled'=>false])->save();
        $this->putJson($reaction, ['reaction'=>'dislike','body'=>'Закрыто','client_request_id'=>(string) Str::uuid()])->assertForbidden();
        $this->getJson($report)->assertOk()->assertJsonPath('likes', 1)->assertJsonPath('dislikes', 0);
        $this->putJson($reaction, ['reaction'=>'dislike'])->assertOk()->assertJsonPath('dislikes', 1);
        $this->getJson($report)->assertOk()->assertJsonCount(4, 'entries')->assertJsonPath('can_post', false);
    }
}
