<?php

namespace Tests\Feature\Coordination;

use App\Modules\Coordination\Domain\Enums\CoordinationSessionStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Telegram\Domain\Models\TelegramCoordinationPublication;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CoordinationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_create_button_opens_auth_flow_with_create_page_redirect(): void
    {
        $this->get(route('coordination.index'))
            ->assertOk()
            ->assertSee('Создать опрос')
            ->assertSee('data-modal-target="auth-entry-classic"', false)
            ->assertSee('data-auth-redirect-url="'.route('coordination.create', [], false).'"', false);
    }

    public function test_active_user_can_create_poll_and_blocked_user_cannot(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('coordination.store'), $this->payload());

        $session = CoordinationSession::query()->firstOrFail();
        $response->assertRedirect(route('coordination.show', $session));
        $this->assertSame(CoordinationSessionStatusEnum::OPEN, $session->status);
        $this->assertSame(PollStatusEnum::OPEN, $session->polls()->firstOrFail()->status);
        $this->assertFalse($session->polls()->firstOrFail()->allows_vote_changes);
        $this->assertFalse($session->polls()->firstOrFail()->is_anonymous);
        $this->assertDatabaseCount('coordination_poll_options', 3);
        $this->get(route('coordination.index'))->assertOk()->assertSee('Игра вечером');
        $this->get(route('coordination.show', $session))->assertOk()->assertSee('Во сколько играем?');
        $this->travelTo(CarbonImmutable::parse('2026-07-25 12:34:00'));
        $this->actingAs($user)
            ->get(route('coordination.create'))
            ->assertOk()
            ->assertSee('Разрешить менять голос')
            ->assertSee('Анонимный опрос')
            ->assertDontSee('name="allows_vote_changes" value="1" checked', false)
            ->assertDontSee('name="is_anonymous" value="1" checked', false)
            ->assertDontSee('Тип вариантов')
            ->assertSee('value="2026-07-25T13:34"', false);
        $this->travelBack();

        $blocked = User::factory()->create(['status' => UserStatusEnum::BLOCKED]);
        $this->actingAs($blocked)
            ->post(route('coordination.store'), $this->payload())
            ->assertForbidden();
    }

    public function test_first_web_slice_rejects_subject_types_without_typed_editor(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('coordination.store'), [
                ...$this->payload(),
                'subject_type' => 'venue',
            ])
            ->assertSessionHasErrors('subject_type');

        $this->assertDatabaseCount('coordination_sessions', 0);
    }

    public function test_user_changes_single_choice_ballot_without_duplicate(): void
    {
        $organizer = User::factory()->create();
        $voter = User::factory()->create();
        $this->actingAs($organizer)->post(route('coordination.store'), [
            ...$this->payload(),
            'allows_vote_changes' => '1',
        ])->assertRedirect();
        $session = CoordinationSession::query()->latest('id')->firstOrFail();
        $poll = $session->polls()->firstOrFail();
        $options = $poll->options()->pluck('id');

        $this->actingAs($voter)
            ->post(route('coordination.vote', $session), ['option_ids' => [$options[0]]])
            ->assertSessionHas('status');
        $this->actingAs($voter)
            ->post(route('coordination.vote', $session), ['option_ids' => [$options[1]]])
            ->assertSessionHas('status');

        $this->assertDatabaseCount('coordination_ballots', 1);
        $this->assertDatabaseCount('coordination_ballot_selections', 1);
        $this->assertDatabaseHas('coordination_ballot_selections', ['option_id' => $options[1]]);
        $this->assertDatabaseMissing('coordination_ballot_selections', ['option_id' => $options[0]]);
    }

    public function test_open_poll_shows_voters_and_anonymous_poll_hides_them(): void
    {
        $organizer = User::factory()->create(['username' => 'poll_owner']);
        $voter = User::factory()->create(['username' => 'visible_voter']);

        $this->actingAs($organizer)->post(route('coordination.store'), [
            ...$this->payload(),
            'results_visibility' => 'always',
            'is_anonymous' => '0',
        ])->assertRedirect();

        $openSession = CoordinationSession::query()->latest('id')->firstOrFail();
        $openOption = $openSession->polls()->firstOrFail()->options()->firstOrFail();
        $this->actingAs($voter)
            ->post(route('coordination.vote', $openSession), ['option_ids' => [$openOption->id]])
            ->assertSessionHas('status');
        $this->actingAs($organizer)
            ->get(route('coordination.show', $openSession))
            ->assertOk()
            ->assertSee('visible_voter');

        $this->actingAs($organizer)->post(route('coordination.store'), [
            ...$this->payload(),
            'results_visibility' => 'always',
            'is_anonymous' => '1',
        ])->assertRedirect();

        $anonymousSession = CoordinationSession::query()->latest('id')->firstOrFail();
        $anonymousOption = $anonymousSession->polls()->firstOrFail()->options()->firstOrFail();
        $this->actingAs($voter)
            ->post(route('coordination.vote', $anonymousSession), ['option_ids' => [$anonymousOption->id]])
            ->assertSessionHas('status');
        $this->actingAs($organizer)
            ->get(route('coordination.show', $anonymousSession))
            ->assertOk()
            ->assertDontSee('visible_voter');
    }

    public function test_user_cannot_change_ballot_when_poll_forbids_vote_changes(): void
    {
        $organizer = User::factory()->create();
        $voter = User::factory()->create();

        $this->actingAs($organizer)
            ->post(route('coordination.store'), [
                ...$this->payload(),
                'allows_vote_changes' => '0',
            ])
            ->assertRedirect();

        $session = CoordinationSession::query()->latest('id')->firstOrFail();
        $poll = $session->polls()->firstOrFail();
        $options = $poll->options()->pluck('id');

        $this->actingAs($voter)
            ->post(route('coordination.vote', $session), ['option_ids' => [$options[0]]])
            ->assertSessionHas('status');
        $this->actingAs($voter)
            ->post(route('coordination.vote', $session), ['option_ids' => [$options[1]]])
            ->assertSessionHas('error', 'В этом опросе нельзя изменить голос.');

        $this->assertFalse($poll->fresh()->allows_vote_changes);
        $this->assertDatabaseCount('coordination_ballots', 1);
        $this->assertDatabaseCount('coordination_ballot_selections', 1);
        $this->assertDatabaseHas('coordination_ballot_selections', ['option_id' => $options[0]]);
        $this->assertDatabaseMissing('coordination_ballot_selections', ['option_id' => $options[1]]);

        $this->actingAs($voter)
            ->get(route('coordination.show', $session))
            ->assertOk()
            ->assertSee('Ваш голос принят и не может быть изменён.')
            ->assertDontSee('Изменить голос');
    }

    public function test_expired_poll_rejects_vote_and_scheduler_closes_it(): void
    {
        $organizer = User::factory()->create();
        $voter = User::factory()->create();
        $session = $this->createSession($organizer);
        $poll = $session->polls()->firstOrFail();
        $poll->forceFill(['closes_at' => now()->subMinute()])->save();

        $this->actingAs($voter)
            ->post(route('coordination.vote', $session), ['option_ids' => [$poll->options()->firstOrFail()->id]])
            ->assertSessionHas('error', 'Голосование уже закрыто.');

        $this->artisan('coordination:close-expired')->assertSuccessful();

        $this->assertSame(PollStatusEnum::CLOSED, $poll->fresh()->status);
        $this->assertSame(CoordinationSessionStatusEnum::DECISION_PENDING, $session->fresh()->status);
        $this->assertDatabaseCount('coordination_ballots', 0);
    }

    public function test_only_creator_closes_and_accepts_explicit_result(): void
    {
        $organizer = User::factory()->create();
        $stranger = User::factory()->create();
        $session = $this->createSession($organizer);
        $poll = $session->polls()->firstOrFail();
        $optionIds = $poll->options()->pluck('id');

        $this->actingAs($stranger)
            ->post(route('coordination.close', $session))
            ->assertSessionHas('error', 'Закрыть опрос может только его создатель.');
        $this->assertSame(PollStatusEnum::OPEN, $poll->fresh()->status);

        $this->actingAs($organizer)
            ->post(route('coordination.close', $session))
            ->assertSessionHas('status');
        $this->assertSame(CoordinationSessionStatusEnum::DECISION_PENDING, $session->fresh()->status);
        $this->assertDatabaseCount('coordination_decisions', 0);

        $this->actingAs($organizer)
            ->post(route('coordination.decision', $session), ['option_id' => $optionIds[1]])
            ->assertSessionHas('status');
        $this->assertSame(CoordinationSessionStatusEnum::COMPLETED, $session->fresh()->status);
        $this->assertDatabaseHas('coordination_decisions', [
            'session_id' => $session->id,
            'option_id' => $optionIds[1],
        ]);

        $this->actingAs($organizer)
            ->post(route('coordination.decision', $session), ['option_id' => $optionIds[2]])
            ->assertSessionHas('status');
        $this->assertDatabaseCount('coordination_decisions', 1);
        $this->assertDatabaseMissing('coordination_decisions', [
            'session_id' => $session->id,
            'option_id' => $optionIds[2],
        ]);
        $this->assertDatabaseCount('events', 0);
    }

    public function test_one_poll_can_have_publications_in_several_chats(): void
    {
        $session = $this->createSession(User::factory()->create());
        $poll = $session->polls()->firstOrFail();
        $firstChat = TelegramChat::query()->create(['telegram_chat_id' => -1001, 'title' => 'Основной']);
        $secondChat = TelegramChat::query()->create(['telegram_chat_id' => -1002, 'title' => 'Север']);

        TelegramCoordinationPublication::query()->create(['poll_id' => $poll->id, 'chat_id' => $firstChat->id]);
        TelegramCoordinationPublication::query()->create(['poll_id' => $poll->id, 'chat_id' => $secondChat->id]);

        $this->assertDatabaseCount('telegram_coordination_publications', 2);
        $this->assertCount(1, $firstChat->coordinationPublications);
        $this->assertCount(1, $secondChat->coordinationPublications);
    }

    private function createSession(User $organizer): CoordinationSession
    {
        $this->actingAs($organizer)->post(route('coordination.store'), $this->payload())->assertRedirect();

        return CoordinationSession::query()->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => 'Игра вечером',
            'question' => 'Во сколько играем?',
            'description' => 'Сначала выберем удобное время.',
            'subject_type' => 'text',
            'selection_mode' => 'single',
            'results_visibility' => 'after_vote',
            'allows_vote_changes' => '0',
            'is_anonymous' => '0',
            'closes_at' => CarbonImmutable::now()->addDay()->format('Y-m-d H:i:s'),
            'options' => ['19:00', '20:00', '21:00'],
        ];
    }
}
