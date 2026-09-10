<?php

namespace Tests\Feature\Venue;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Application\UseCases\ReviewVenueOwnershipClaimHandler;
use App\Modules\Venue\Application\UseCases\SubmitVenueOwnershipClaimHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueOwnershipStatusHandler;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueOwnershipStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_contains_current_public_event_and_upcoming_event_and_tournament(): void
    {
        $venue = Venue::factory()->create();

        $currentEvent = Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Игра прямо сейчас',
            'type' => EventTypeEnum::GAME,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => now()->subMinutes(20),
            'ends_at' => now()->addHour(),
        ]);
        Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Завтрашняя тренировка',
            'type' => EventTypeEnum::TRAINING,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Приватная тренировка',
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PRIVATE,
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(3),
        ]);
        Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Неопубликованная тренировка',
            'status' => EventStatusEnum::DRAFT,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => now()->addHours(4),
            'ends_at' => now()->addHours(5),
        ]);
        Tournament::factory()->create([
            'default_venue_id' => $venue->id,
            'title' => 'Турнир выходного дня',
            'status' => TournamentStatusEnum::CONFIRMED,
            'starts_on' => today()->addDays(2),
            'ends_on' => today()->addDays(2),
        ]);

        $response = $this->getJson(route('venues.activities', $venue->routeIdentifier()));

        $response->assertOk()
            ->assertJsonPath('operational_status', VenueOperationalStatusEnum::ACTIVE->value)
            ->assertJsonPath('current.0.title', 'Игра прямо сейчас')
            ->assertJsonPath('current.0.is_current', true)
            ->assertJsonPath('current.0.url', route('events.show', $currentEvent->routeIdentifier()))
            ->assertJsonFragment(['title' => 'Завтрашняя тренировка'])
            ->assertJsonFragment(['title' => 'Турнир выходного дня'])
            ->assertJsonMissing(['title' => 'Приватная тренировка'])
            ->assertJsonMissing(['title' => 'Неопубликованная тренировка']);
    }

    public function test_feed_exposes_temporarily_closed_operational_status(): void
    {
        $venue = Venue::factory()->create([
            'operational_status' => VenueOperationalStatusEnum::TEMPORARILY_CLOSED,
        ]);

        $this->getJson(route('venues.activities', $venue->routeIdentifier()))
            ->assertOk()
            ->assertJsonPath('operational_status', VenueOperationalStatusEnum::TEMPORARILY_CLOSED->value);
    }

    public function test_information_is_untrusted_without_active_ownership(): void
    {
        $venue = Venue::factory()->create();

        $this->getJson(route('venues.activities', $venue->routeIdentifier()))
            ->assertOk()
            ->assertJsonPath('information_trusted', false)
            ->assertJsonPath('information_warning', true);
    }

    public function test_information_trust_uses_commitment_and_inclusive_score_threshold(): void
    {
        $venue = Venue::factory()->create();
        $ownership = $this->activeOwnership($venue);
        $cases = [
            [false, 100, false],
            [true, 0, false],
            [true, 60, false],
            [true, 70, true],
            [true, 100, true],
        ];

        foreach ($cases as [$commitment, $score, $expectedTrusted]) {
            $ownership->forceFill([
                'maintenance_commitment_accepted' => $commitment,
                'maintenance_score' => $score,
            ])->save();

            $this->getJson(route('venues.activities', $venue->routeIdentifier()))
                ->assertOk()
                ->assertJsonPath('information_trusted', $expectedTrusted)
                ->assertJsonPath('information_warning', ! $expectedTrusted);
        }
    }

    public function test_high_quality_inactive_ownership_is_not_trusted(): void
    {
        $venue = Venue::factory()->create();
        $ownership = $this->activeOwnership($venue);
        $ownership->forceFill([
            'maintenance_commitment_accepted' => true,
            'maintenance_score' => 100,
        ])->save();

        app(UpdateVenueOwnershipStatusHandler::class)->handle(
            $ownership,
            VenueOwnershipStatusEnum::UNDER_REVIEW,
            'Владение временно проверяется.',
            $this->confirmedUser(UserSystemRoleEnum::ADMIN),
        );

        $this->getJson(route('venues.activities', $venue->routeIdentifier()))
            ->assertOk()
            ->assertJsonPath('information_trusted', false)
            ->assertJsonPath('information_warning', true);
    }

    public function test_confirmed_tournament_does_not_expose_its_private_live_game(): void
    {
        $venue = Venue::factory()->create();
        $tournament = Tournament::factory()->create([
            'default_venue_id' => $venue->id,
            'title' => 'Публичный турнир',
            'status' => TournamentStatusEnum::CONFIRMED,
            'starts_on' => today(),
            'ends_on' => today()->addDay(),
        ]);
        $entries = collect(['Команда A', 'Команда B'])->map(
            fn (string $name, int $position) => $tournament->entries()->create([
                'source' => TournamentEntrySourceEnum::ASSEMBLED,
                'name' => $name,
                'status' => TournamentEntryStatusEnum::ACTIVE,
                'position' => $position + 1,
            ]),
        );
        $privateEvent = Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Закрытая турнирная игра',
            'type' => EventTypeEnum::GAME,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PRIVATE,
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addHour(),
        ]);
        $game = Game::query()->create([
            'event_id' => $privateEvent->id,
            'created_by_actor_id' => $privateEvent->organizer_actor_id,
            'title' => 'Секретная игра',
            'status' => GameStatusEnum::IN_PROGRESS,
            'actual_started_at' => now()->subMinutes(10),
        ]);
        $tournament->matches()->create([
            'entry_a_id' => $entries[0]->id,
            'entry_b_id' => $entries[1]->id,
            'game_id' => $game->id,
            'sequence' => 1,
        ]);

        $response = $this->getJson(route('venues.activities', $venue->routeIdentifier()))->assertOk();
        $activity = collect($response->json('current'))->firstWhere('title', 'Публичный турнир');

        $this->assertNotNull($activity);
        $this->assertFalse($activity['is_live']);
        $this->assertNull($activity['game_id']);
        $this->assertNull($activity['teams']);
        $this->assertSame(route('tournaments.show', $tournament->routeIdentifier()), $activity['url']);
        $response->assertJsonMissing(['title' => 'Закрытая турнирная игра']);
    }

    private function activeOwnership(Venue $venue): VenueOwnership
    {
        $claim = app(SubmitVenueOwnershipClaimHandler::class)->handle(
            $venue,
            $this->confirmedUser(),
            'Документы и рабочие контакты представителя площадки.',
        );
        app(ReviewVenueOwnershipClaimHandler::class)->approve(
            $claim,
            $this->confirmedUser(UserSystemRoleEnum::ADMIN),
            'Полномочия подтверждены.',
        );

        return VenueOwnership::query()->where('source_claim_id', $claim->id)->sole();
    }

    private function confirmedUser(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
    }
}
