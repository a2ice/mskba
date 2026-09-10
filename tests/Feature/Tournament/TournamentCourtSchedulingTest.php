<?php

namespace Tests\Feature\Tournament;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TournamentCourtSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tournament_match_persists_selected_court_on_event_and_booking(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), [
            'title' => 'Кубок залов',
            'alias' => 'court-cup',
            'starts_on' => today()->addWeek()->format('Y-m-d'),
            'ends_on' => today()->addWeeks(2)->format('Y-m-d'),
            'format' => GameFormatEnum::STREETBALL_3X3->value,
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT->value,
        ])->assertSessionHasNoErrors();

        $tournament = Tournament::query()->firstOrFail();
        $entries = collect(['Красные', 'Синие'])->map(function (string $name, int $position) use ($tournament) {
            $entry = $tournament->entries()->create([
                'source' => TournamentEntrySourceEnum::ASSEMBLED,
                'name' => $name,
                'status' => TournamentEntryStatusEnum::ACTIVE,
                'position' => $position + 1,
            ]);
            $entry->members()->createMany(
                User::factory()->count(4)->create()->values()->map(
                    fn (User $user, int $index): array => ['user_id' => $user->id, 'position' => $index],
                )->all(),
            );

            return $entry;
        });
        $tournament->forceFill(['participant_pool_locked_at' => now()])->save();
        $match = $tournament->matches()->create([
            'entry_a_id' => $entries[0]->id,
            'entry_b_id' => $entries[1]->id,
            'round' => 1,
            'sequence' => 1,
        ]);

        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $secondCourt = VenueCourt::query()->create([
            'venue_id' => $venue->id,
            'name' => 'Зал 2',
            'alias' => 'zal-2',
            'sort_order' => 20,
            'is_primary' => false,
            'supports_halves' => false,
        ]);
        $startsAt = CarbonImmutable::now('Europe/Moscow')->addDays(8)->startOfDay()->addHours(12);

        $this->actingAs($owner)->post(
            route('tournaments.matches.schedule', [$tournament->routeIdentifier(), $match]),
            [
                'venue_id' => $venue->id,
                'venue_court_id' => $secondCourt->id,
                'starts_at' => $startsAt->format('Y-m-d\TH:i'),
                'duration_minutes' => 90,
                'game_format' => GameFormatEnum::STREETBALL_3X3->value,
                'timing_mode' => 'whole_game',
            ],
        )->assertSessionHasNoErrors();

        $event = Event::query()->with('booking')->firstOrFail();
        $this->assertSame($secondCourt->id, $event->venue_court_id);
        $this->assertSame($secondCourt->id, $event->booking->venue_court_id);
    }
}
