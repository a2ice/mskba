<?php

namespace Database\Seeders;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tournament\Application\Services\RoundRobinGenerator;
use App\Modules\Tournament\Application\Services\TournamentMatchSchedulingService;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TournamentAcceptanceSeeder extends Seeder
{
    public const ALIAS = 'demo-round-robin';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('TournamentAcceptanceSeeder разрешён только в local/testing окружении.');
        }

        $this->call(GameLifecycleDemoSeeder::class);
        $organizer = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $actor = app(CurrentActorResolver::class)->resolve($organizer, null);
        if (! $actor instanceof Actor) {
            throw new RuntimeException('Не удалось создать actor организатора demo-турнира.');
        }
        $players = User::query()->where('username', 'like', 'demo-%-%')->orderBy('username')->get();
        $venue = Venue::query()->where('alias', 'demo-basketball-arena')->firstOrFail();

        $tournament = DB::transaction(function () use ($actor, $players): Tournament {
            $tournament = Tournament::withTrashed()->firstOrNew(['alias' => self::ALIAS]);
            $tournament->forceFill([
                'created_by_actor_id' => $actor->id,
                'title' => '[DEMO] Круговой турнир 3×3',
                'status' => TournamentStatusEnum::CONFIRMED,
                'starts_on' => today()->subDay(),
                'ends_on' => today()->addDays(20),
                'short_description' => 'Четыре команды, круговая схема и матчи для финальной браузерной проверки.',
                'full_description' => 'Локальный acceptance fixture: команды собраны из подтверждённых игроков, одна игра завершена, одна запланирована.',
                'format' => GameFormatEnum::STREETBALL_3X3,
                'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT,
                'deleted_at' => null,
            ])->save();

            foreach (range(1, 4) as $number) {
                $entry = $tournament->entries()->firstOrCreate(
                    ['position' => $number],
                    ['source' => TournamentEntrySourceEnum::ASSEMBLED, 'name' => "[DEMO] Команда {$number}", 'status' => TournamentEntryStatusEnum::ACTIVE],
                );
                $entry->forceFill(['name' => "[DEMO] Команда {$number}", 'status' => TournamentEntryStatusEnum::ACTIVE])->save();
                $entry->members()->delete();
                $entry->members()->createMany($players->slice(($number - 1) * 3, 3)->values()->map(fn (User $user, int $position): array => ['user_id' => $user->id, 'position' => $position])->all());
            }

            if ($tournament->matches()->doesntExist()) {
                $rounds = app(RoundRobinGenerator::class)->generate($tournament->entries()->pluck('id')->all(), 1);
                $sequence = 1;
                foreach ($rounds as $round) {
                    foreach ($round['matches'] as $match) {
                        $tournament->matches()->create([...$match, 'round' => $round['round'], 'sequence' => $sequence++]);
                    }
                }
            }

            return $tournament;
        });

        $scheduler = app(TournamentMatchSchedulingService::class);
        $missingScheduledGames = max(0, 2 - $tournament->matches()->whereNotNull('game_id')->count());
        foreach ($tournament->matches()->whereNull('game_id')->take($missingScheduledGames)->get()->values() as $index => $match) {
            $scheduler->schedule($tournament, $match, $actor, [
                'venue_id' => $venue->id,
                'starts_at' => now()->addDays(11 + $index)->setTime(19, 0)->format('Y-m-d\TH:i'),
                'duration_minutes' => 90,
                'game_format' => GameFormatEnum::STREETBALL_3X3->value,
                'timing_mode' => 'whole_game',
            ]);
        }

        $completed = $tournament->matches()->whereNotNull('game_id')->with('game.sides')->first();
        if ($completed?->game !== null) {
            $completedStartsAt = now()->subHours(3)->startOfMinute();
            $completedEndsAt = now()->subMinutes(90)->startOfMinute();
            $completed->game->event->forceFill([
                'starts_at' => $completedStartsAt,
                'ends_at' => $completedEndsAt,
            ])->save();
            $completed->game->event->booking?->forceFill([
                'starts_at' => $completedStartsAt,
                'ends_at' => $completedEndsAt,
            ])->save();
            $sides = $completed->game->sides->keyBy('slot');
            $sides['A']->forceFill(['score' => 12])->save();
            $sides['B']->forceFill(['score' => 8])->save();
            $completed->game->forceFill([
                'status' => GameStatusEnum::COMPLETED,
                'statistics_status' => GameStatisticsStatusEnum::CONFIRMED,
                'scheduled_starts_at' => $completedStartsAt,
                'scheduled_ends_at' => $completedEndsAt,
                'actual_started_at' => $completedStartsAt,
                'actual_ended_at' => $completedEndsAt,
                'statistics_confirmed_at' => now()->subMinutes(20),
                'statistics_confirmed_by_actor_id' => $actor->id,
                'completed_at' => now()->subMinutes(20),
                'completed_by_actor_id' => $actor->id,
            ])->save();
            $completed->game->rosterEntries()->update(['status' => GameRosterStatusEnum::PLAYED]);
            $completed->game->event->forceFill([
                'status' => 'completed',
                'completed_at' => now()->subMinutes(20),
                'completed_by_actor_id' => $actor->id,
            ])->save();
        }
    }
}
