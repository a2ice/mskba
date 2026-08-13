<?php

namespace Tests\Feature\Tournament;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TournamentMatchSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_match_scheduling_atomically_creates_event_booking_game_sides_and_roster_snapshot(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), [
            'title' => 'Кубок', 'alias' => 'cup',
            'starts_on' => today()->addWeek()->format('Y-m-d'),
            'ends_on' => today()->addWeeks(2)->format('Y-m-d'),
            'format' => GameFormatEnum::STREETBALL_3X3->value,
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT->value,
        ]);
        $tournament = Tournament::query()->firstOrFail();
        $entries = collect(['Красные', 'Синие'])->map(function (string $name, int $position) use ($tournament) {
            $entry = $tournament->entries()->create([
                'source' => TournamentEntrySourceEnum::ASSEMBLED,
                'name' => $name,
                'logo_preset' => sprintf('crest-%02d', $position),
                'status' => TournamentEntryStatusEnum::ACTIVE,
                'position' => $position + 1,
            ]);
            $entry->members()->createMany(User::factory()->count(4)->create()->values()->map(fn (User $user, int $index): array => ['user_id' => $user->id, 'position' => $index])->all());

            return $entry;
        });
        $tournament->forceFill(['participant_pool_locked_at' => now()])->save();
        $match = $tournament->matches()->create(['entry_a_id' => $entries[0]->id, 'entry_b_id' => $entries[1]->id, 'round' => 1, 'sequence' => 1]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'requires_payment' => false, 'requires_booking_approval' => false]);
        $startsAt = CarbonImmutable::now('Europe/Moscow')->addDays(8)->startOfDay()->addHours(12);

        $response = $this->actingAs($owner)->post(route('tournaments.matches.schedule', [$tournament->routeIdentifier(), $match]), [
            'venue_id' => $venue->id,
            'starts_at' => $startsAt->format('Y-m-d\TH:i'),
            'duration_minutes' => 90,
            'game_format' => GameFormatEnum::STREETBALL_3X3->value,
            'timing_mode' => 'whole_game',
        ]);

        $event = Event::query()->with(['booking', 'primaryGame.sides', 'primaryGame.rosterEntries'])->firstOrFail();
        $response->assertRedirect(route('events.show', $event->routeIdentifier()));
        $this->assertSame($event->primary_game_id, $match->fresh()->game_id);
        $this->assertSame($venue->id, $event->booking->venue_id);
        $this->assertSame(['Красные', 'Синие'], $event->primaryGame->sides->sortBy('slot')->pluck('display_name')->all());
        $this->assertSame(['crest-00', 'crest-01'], $event->primaryGame->sides->sortBy('slot')->pluck('logo_preset')->all());
        $this->assertCount(8, $event->primaryGame->rosterEntries);
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('venue_bookings', 1);
        $this->assertDatabaseCount('games', 1);
        $this->actingAs($owner)->get(route('tournaments.manage', $tournament->routeIdentifier()))->assertOk()->assertSee('Расписание готово');
        $updatePayload = [
            'title' => 'Кубок обновлён',
            'starts_on' => $tournament->starts_on->format('Y-m-d'),
            'ends_on' => $tournament->ends_on->format('Y-m-d'),
            'format' => $tournament->format->value,
            'recruitment_mode' => $tournament->recruitment_mode->value,
        ];
        $this->actingAs($owner)->put(route('tournaments.update', $tournament->routeIdentifier()), [
            ...$updatePayload,
            'starts_on' => $startsAt->addDay()->format('Y-m-d'),
        ])->assertSessionHas('error', 'Новый диапазон дат должен включать все назначенные матчи.');
        $this->actingAs($owner)->put(route('tournaments.update', $tournament->routeIdentifier()), $updatePayload)->assertSessionHasNoErrors();
        $this->assertSame('Кубок обновлён', $tournament->fresh()->title);
        $this->actingAs($owner)->postJson(route('tournaments.formation.preview', $tournament->routeIdentifier()), [
            'assessment_source' => 'self_assessment',
            'team_count' => 2,
            'seed' => 42,
        ])->assertUnprocessable()->assertJsonPath('message', 'После назначения хотя бы одного матча переформировывать команды нельзя.');

        $routeParameters = [$event->routeIdentifier(), $event->primaryGame->id];
        $this->actingAs($owner)
            ->get(route('events.games.manage', $routeParameters))
            ->assertOk()
            ->assertSee('data-game-composition-save', false)
            ->assertSee('В старте')
            ->assertDontSee('Стартовый состав и капитаны')
            ->assertSee($entries[0]->members()->firstOrFail()->user->username)
            ->assertSee($entries[1]->members()->firstOrFail()->user->username);
        $sideAUserIds = $entries[0]->members()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $sideBUserIds = $entries[1]->members()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $this->actingAs($owner)
            ->patchJson(route('events.games.roster', $routeParameters), [
                'side_a_user_ids' => $sideAUserIds,
                'side_b_user_ids' => $sideBUserIds,
                'starters' => ['A' => array_slice($sideAUserIds, 0, 3), 'B' => array_slice($sideBUserIds, 0, 3)],
                'captains' => ['A' => $sideAUserIds[0], 'B' => $sideBUserIds[0]],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Состав игры, стартовые игроки и капитаны сохранены.');
        $this->assertSame(6, $event->primaryGame->rosterEntries()->where('lineup_role', 'starter')->count());
        $this->assertSame(2, $event->primaryGame->rosterEntries()->where('is_captain', true)->count());
        $this->actingAs($owner)
            ->get(route('tournaments.manage', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('Схему нельзя изменить: один или несколько матчей уже назначены.')
            ->assertDontSee('Изменить схему');

        $targetVenue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'requires_payment' => false, 'requires_booking_approval' => false]);
        $newStart = $startsAt->addDay();
        $this->actingAs($owner)->put(route('tournaments.matches.reschedule', [$tournament->routeIdentifier(), $match]), [
            'venue_id' => $targetVenue->id,
            'starts_at' => $newStart->format('Y-m-d\TH:i'),
            'duration_minutes' => 120,
        ])->assertRedirect(route('events.show', $event->routeIdentifier()));
        $event->refresh();
        $this->assertSame($targetVenue->id, $event->venue_id);
        $this->assertSame($targetVenue->id, $event->booking->fresh()->venue_id);
        $this->assertSame(120, (int) $event->starts_at->diffInMinutes($event->ends_at));
        $this->assertTrue($event->primaryGame->fresh()->scheduled_starts_at->equalTo($event->starts_at));

        $event->primaryGame->forceFill(['status' => GameStatusEnum::IN_PROGRESS, 'actual_started_at' => now()])->save();
        $this->actingAs($owner)->post(route('tournaments.matches.store', $tournament->routeIdentifier()), [
            'entry_a_id' => $entries[0]->id,
            'entry_b_id' => $entries[1]->id,
        ])->assertSessionHas('error', 'После начала турнира добавлять новые матчи нельзя.');
        $this->actingAs($owner)->get(route('tournaments.manage', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertDontSee('Добавить отдельный матч')
            ->assertSee('Сейчас играют:')
            ->assertDontSee('Перенести игру и бронь')
            ->assertSee('Добавление новых матчей закрыто: турнир уже начался.');

        $event->primaryGame->sides()->where('slot', 'A')->update(['score' => 21]);
        $event->primaryGame->sides()->where('slot', 'B')->update(['score' => 17]);
        $event->primaryGame->forceFill(['status' => GameStatusEnum::COMPLETED])->save();
        $event->forceFill(['status' => EventStatusEnum::COMPLETED, 'completed_at' => now()])->save();
        $otherMatch = $tournament->matches()->create([
            'entry_a_id' => $entries[1]->id,
            'entry_b_id' => $entries[0]->id,
            'sequence' => 2,
        ]);
        $this->actingAs($owner)->get(route('tournaments.manage', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('Завершена:')
            ->assertSee('21:17', false, false)
            ->assertSee(route('events.show', $event->routeIdentifier()), false)
            ->assertSee('Открыть игру')
            ->assertSee('Начатые и завершённые игры зафиксированы.')
            ->assertSee('data-match-order-fixed="1"', false)
            ->assertDontSee('Сохранить порядок')
            ->assertDontSee('Перенести игру и бронь');

        $this->actingAs($owner)->patch(route('tournaments.matches.reorder', $tournament->routeIdentifier()), [
            'positions' => [$otherMatch->id => 1, $match->id => 2],
        ])->assertSessionHas('error', 'Уже начатые и завершённые игры должны оставаться на своих позициях.');

        $staff = User::factory()->create(['username' => 'match-result-editor', 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.staff.invite', $tournament->routeIdentifier()), [
            'user_id' => $staff->id,
            'permissions' => [TournamentPermissionEnum::MANAGE_GAMES->value],
        ]);
        $membership = $tournament->staffMemberships()->where('user_id', $staff->id)->firstOrFail();
        $this->actingAs($staff)->post(route('tournaments.staff.respond', [$tournament->routeIdentifier(), $membership]), [
            'decision' => 'accepted',
        ]);
        $this->actingAs($staff)->get(route('events.games.manage', $routeParameters))
            ->assertOk()
            ->assertSee('Как это было')
            ->assertSee('Фотографии результата');
        $this->actingAs($staff)->put(route('events.result.update', $event->routeIdentifier()), [
            'result_description' => 'Решающая игра турнира.',
        ])->assertSessionHas('status');
        $this->assertSame('Решающая игра турнира.', $event->fresh()->result_description);
        $this->actingAs($staff)->post(route('events.result.photos.store', $event->routeIdentifier()), [
            'photo' => UploadedFile::fake()->image('tournament-game.jpg', 1200, 800),
        ])->assertSessionHas('photo_status');
        $photo = $event->media()->firstOrFail();
        Storage::disk('public')->assertExists($photo->path);
        $this->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('Решающая игра турнира.')
            ->assertSee($photo->publicUrl(), false);
    }

    public function test_unavailable_venue_rolls_back_entire_tournament_game_aggregate(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $actor = Actor::factory()->create(['user_id' => $owner->id, 'type' => 'user']);
        $tournament = Tournament::factory()->create(['created_by_actor_id' => $actor->id, 'format' => GameFormatEnum::STREETBALL_1X1]);
        $players = User::factory()->count(2)->create();
        $entries = $players->map(function (User $player, int $position) use ($tournament) {
            $entry = $tournament->entries()->create(['source' => TournamentEntrySourceEnum::INDIVIDUAL, 'name' => 'Игрок '.($position + 1), 'status' => TournamentEntryStatusEnum::ACTIVE]);
            $entry->members()->create(['user_id' => $player->id]);

            return $entry;
        });
        $match = $tournament->matches()->create(['entry_a_id' => $entries[0]->id, 'entry_b_id' => $entries[1]->id, 'sequence' => 1]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'requires_payment' => false, 'requires_booking_approval' => false]);
        $startsAt = CarbonImmutable::now('Europe/Moscow')->addDays(8)->startOfHour();
        Event::factory()->for($venue)->create(['organizer_actor_id' => $actor->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->addHours(2)])
            ->booking()->create(['venue_id' => $venue->id, 'created_by_actor_id' => $actor->id, 'status' => 'confirmed', 'starts_at' => $startsAt, 'ends_at' => $startsAt->addHours(2)]);

        $response = $this->actingAs($owner)->post(route('tournaments.matches.schedule', [$tournament->routeIdentifier(), $match]), [
            'venue_id' => $venue->id, 'starts_at' => $startsAt->format('Y-m-d\TH:i'), 'duration_minutes' => 90,
            'game_format' => GameFormatEnum::STREETBALL_1X1->value, 'timing_mode' => 'whole_game',
        ]);
        $response->assertSessionHas('error');

        $this->assertNull($match->fresh()->game_id);
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('games', 0);
        $this->assertDatabaseCount('venue_bookings', 1);
    }
}
