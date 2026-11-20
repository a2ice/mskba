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

    public function test_author_deletes_by_striking_through_and_moderation_stays_separate(): void
    {
        $event = $this->event();
        $user = $this->confirmedUser();
        $other = $this->confirmedUser();
        $this->actingAs($user)->patch(route('events.participation', $event->routeIdentifier()), [
            'status' => 'tentative',
        ])->assertRedirect();

        $post = $this->postWall($event, 'В пятницу после семи')->assertCreated()->json('entry');
        $this->actingAs($other)->deleteJson($post['delete_url'])->assertNotFound();
        $this->actingAs($user)->deleteJson($post['delete_url'])
            ->assertOk()->assertJsonPath('entry.deleted', true)
            ->assertJsonPath('entry.text', 'В пятницу после семи');

        $this->actingAs($user)->getJson(route('events.wall.index', $event->routeIdentifier()))
            ->assertOk()->assertJsonPath('entries.1.deleted', true)
            ->assertJsonPath('entries.1.text', 'В пятницу после семи');

        $this->assertDatabaseHas('social_wall_entries', [
            'id' => $post['id'], 'body' => 'В пятницу после семи',
        ]);
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

    public function test_event_page_preserves_existing_content_and_adds_event_wall_tabs(): void
    {
        $event = $this->event();
        $this->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('data-event-social-wall', false)
            ->assertSee('event-wall-panel-chat', false)
            ->assertSee('event-wall-panel-history', false);
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
}
