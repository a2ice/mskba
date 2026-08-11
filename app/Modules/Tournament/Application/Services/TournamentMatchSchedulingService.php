<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GamePeriodStatusEnum;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentEntry;
use App\Modules\Tournament\Domain\Models\TournamentMatch;
use App\Modules\Venue\Domain\Models\Venue;
use App\Support\Text\CyrillicTransliterator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TournamentMatchSchedulingService
{
    public function __construct(
        private readonly TournamentAccess $access,
        private readonly VenueEventAvailability $availability,
        private readonly CyrillicTransliterator $transliterator,
        private readonly TournamentEntryRosterResolver $entryRosters,
    ) {}

    /** @param array<string, mixed> $data */
    public function schedule(Tournament $tournament, TournamentMatch $match, Actor $actor, array $data): Event
    {
        $event = DB::transaction(function () use ($tournament, $match, $actor, $data): Event {
            // Общий порядок блокировок бронирований: venue -> tournament -> match.
            $venue = Venue::query()->whereKey((int) $data['venue_id'])->lockForUpdate()->firstOrFail();
            $lockedTournament = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($lockedTournament, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            $lockedMatch = $lockedTournament->matches()->whereKey($match->id)->lockForUpdate()->firstOrFail();
            if ($lockedTournament->status === TournamentStatusEnum::CANCELLED || $lockedMatch->game_id !== null) {
                throw new InvalidArgumentException('Этот матч нельзя назначить или он уже назначен.');
            }

            $entries = TournamentEntry::query()->whereKey([$lockedMatch->entry_a_id, $lockedMatch->entry_b_id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $entryA = $entries[$lockedMatch->entry_a_id] ?? null;
            $entryB = $entries[$lockedMatch->entry_b_id] ?? null;
            if (! $entryA || ! $entryB) {
                throw new InvalidArgumentException('Обе стороны матча должны быть допущены к турниру.');
            }
            $entryARoster = $this->entryRosters->resolve($entryA);
            $entryBRoster = $this->entryRosters->resolve($entryB);
            if ($entryARoster->isEmpty() || $entryBRoster->isEmpty()) {
                throw new InvalidArgumentException('Обе стороны матча должны иметь доступный состав.');
            }

            $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
            $startsAt = CarbonImmutable::parse($data['starts_at'], $timezone);
            $duration = (int) $data['duration_minutes'];
            $endsAt = $startsAt->addMinutes($duration);
            if ($duration < 1 || $duration > 1440 || $startsAt->lessThan(CarbonImmutable::now($timezone)->subMinute()->startOfMinute())) {
                throw new InvalidArgumentException('Укажите будущее время и длительность не более суток.');
            }
            if ($startsAt->toDateString() < $lockedTournament->starts_on->toDateString()
                || ($lockedTournament->ends_on && $endsAt->toDateString() > $lockedTournament->ends_on->toDateString())) {
                throw new InvalidArgumentException('Игра должна целиком входить в даты проведения турнира.');
            }
            $this->availability->assertAvailable($venue, $startsAt, $endsAt);
            $userIds = $entryARoster->pluck('user_id')->merge($entryBRoster->pluck('user_id'))->map(fn ($id): int => (int) $id);
            if ($userIds->duplicates()->isNotEmpty()) {
                throw new InvalidArgumentException('Один игрок не может находиться на обеих сторонах матча.');
            }
            $hasPlayerConflict = Game::query()->whereIn('status', [GameStatusEnum::SCHEDULED->value, GameStatusEnum::IN_PROGRESS->value])
                ->whereHas('event', fn ($query) => $query->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt))
                ->whereHas('rosterEntries', fn ($query) => $query->whereIn('user_id', $userIds))->exists();
            if ($hasPlayerConflict) {
                throw new InvalidArgumentException('У одной из команд уже есть игра в выбранное время.');
            }

            $format = GameFormatEnum::from($data['game_format'] ?? $lockedTournament->format?->value ?? '');
            $sideSize = $format->sideSize() ?? throw new InvalidArgumentException('Для матча нужен установленный формат 1×1, 3×3 или 5×5.');
            if ($entryARoster->count() < $sideSize || $entryBRoster->count() < $sideSize) {
                throw new InvalidArgumentException("Для формата {$format->label()} на каждой стороне нужно минимум {$sideSize} игроков.");
            }
            $timingMode = GameTimingModeEnum::from($data['timing_mode'] ?? GameTimingModeEnum::WHOLE_GAME->value);
            $periodsCount = $timingMode === GameTimingModeEnum::PERIODS ? (int) ($data['periods_count'] ?? 4) : null;
            if ($timingMode === GameTimingModeEnum::PERIODS && ($format !== GameFormatEnum::BASKETBALL_5X5 || ! in_array($periodsCount, [2, 4], true))) {
                throw new InvalidArgumentException('Режим периодов доступен для баскетбола: 2 или 4 периода.');
            }

            $bookingStatus = $venue->hasFreeAccess() ? VenueBookingStatusEnum::CONFIRMED : VenueBookingStatusEnum::PENDING;
            $title = $entryA->name.' — '.$entryB->name;
            $event = Event::query()->create([
                'venue_id' => $venue->id, 'organizer_actor_id' => $actor->id, 'title' => $title,
                'alias' => Str::slug($this->transliterator->transliterate($title)), 'type' => EventTypeEnum::GAME,
                'status' => $bookingStatus === VenueBookingStatusEnum::CONFIRMED ? EventStatusEnum::PUBLISHED : EventStatusEnum::DRAFT,
                'visibility' => EventVisibilityEnum::PUBLIC, 'starts_at' => $startsAt, 'ends_at' => $endsAt,
            ]);
            $event->booking()->create(['venue_id' => $venue->id, 'created_by_actor_id' => $actor->id, 'status' => $bookingStatus, 'starts_at' => $startsAt, 'ends_at' => $endsAt]);
            $participants = $userIds->unique()->values()->map(fn (int $userId): array => [
                'user_id' => $userId,
                'role' => $userId === $actor->user_id ? EventParticipantRoleEnum::ORGANIZER : EventParticipantRoleEnum::PARTICIPANT,
                'status' => EventParticipantStatusEnum::CONFIRMED,
                'joined_at' => now(),
            ])->all();
            if ($actor->user_id !== null && ! $userIds->contains($actor->user_id)) {
                $participants[] = ['user_id' => $actor->user_id, 'role' => EventParticipantRoleEnum::ORGANIZER, 'status' => EventParticipantStatusEnum::CONFIRMED, 'joined_at' => now()];
            }
            $event->participants()->createMany($participants);
            $game = $event->games()->create([
                'created_by_actor_id' => $actor->id, 'status' => GameStatusEnum::SCHEDULED, 'format' => $format,
                'timing_mode' => $timingMode, 'side_a_size' => $sideSize, 'side_b_size' => $sideSize,
                'scoring_type' => $format->scoringType(), 'periods_count' => $periodsCount,
                'scheduled_starts_at' => $startsAt, 'scheduled_ends_at' => $endsAt,
            ]);
            foreach ([['entry' => $entryA, 'roster' => $entryARoster, 'slot' => 'A'], ['entry' => $entryB, 'roster' => $entryBRoster, 'slot' => 'B']] as $sideData) {
                $entry = $sideData['entry'];
                $entry->loadMissing(['team.logo', 'logo']);
                $side = $game->sides()->create([
                    'team_id' => $entry->team_id,
                    'slot' => $sideData['slot'],
                    'display_name' => $entry->name,
                    'logo_preset' => $entry->logo_preset,
                    'logo_disk' => $entry->logo?->disk,
                    'logo_path' => $entry->logo?->path,
                ]);
                $game->rosterEntries()->createMany($sideData['roster']->map(fn (array $member): array => [
                    'game_side_id' => $side->id, 'user_id' => $member['user_id'],
                    'source_contract_membership_id' => $member['source_contract_membership_id'],
                    'status' => GameRosterStatusEnum::SELECTED,
                ])->all());
            }
            if ($periodsCount !== null) {
                $game->periods()->createMany(collect(range(1, $periodsCount))->map(fn (int $number): array => ['number' => $number, 'status' => GamePeriodStatusEnum::SCHEDULED])->all());
            }
            $event->forceFill(['primary_game_id' => $game->id])->save();
            $lockedMatch->forceFill(['game_id' => $game->id])->save();

            return $event->load(['booking', 'primaryGame.sides', 'primaryGame.rosterEntries']);
        });

        event(new EventChanged($event->id));

        return $event;
    }

    /** @param array<string, mixed> $data */
    public function reschedule(Tournament $tournament, TournamentMatch $match, Actor $actor, array $data): Event
    {
        $reference = $match->game?->event ?? throw new InvalidArgumentException('Матч ещё не назначен.');
        $venueIds = collect([(int) $reference->venue_id, (int) $data['venue_id']])->unique()->sort()->values();
        $event = DB::transaction(function () use ($tournament, $match, $actor, $data, $reference, $venueIds): Event {
            $venues = Venue::query()->whereKey($venueIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lockedTournament = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($lockedTournament, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            $lockedMatch = $lockedTournament->matches()->whereKey($match->id)->lockForUpdate()->firstOrFail();
            $game = Game::query()->whereKey($lockedMatch->game_id)->lockForUpdate()->firstOrFail();
            $event = Event::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
            $booking = $event->booking()->lockForUpdate()->firstOrFail();
            if ($game->status !== GameStatusEnum::SCHEDULED || $game->actual_started_at !== null) {
                throw new InvalidArgumentException('Переносить можно только ещё не начатую игру.');
            }
            $venue = $venues->get((int) $data['venue_id']);
            if (! $venue instanceof Venue) {
                throw new InvalidArgumentException('Выбранная площадка недоступна.');
            }
            $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
            $startsAt = CarbonImmutable::parse($data['starts_at'], $timezone);
            $duration = (int) $data['duration_minutes'];
            $endsAt = $startsAt->addMinutes($duration);
            if ($duration < 1 || $duration > 1440 || $startsAt->lessThan(CarbonImmutable::now($timezone)->subMinute()->startOfMinute())) {
                throw new InvalidArgumentException('Укажите будущее время и длительность не более суток.');
            }
            if ($startsAt->toDateString() < $lockedTournament->starts_on->toDateString()
                || ($lockedTournament->ends_on && $endsAt->toDateString() > $lockedTournament->ends_on->toDateString())) {
                throw new InvalidArgumentException('Игра должна целиком входить в даты проведения турнира.');
            }
            $this->availability->assertAvailable($venue, $startsAt, $endsAt, $booking->id);
            $userIds = $game->rosterEntries()->pluck('user_id');
            $hasPlayerConflict = Game::query()->whereKeyNot($game->id)
                ->whereIn('status', [GameStatusEnum::SCHEDULED->value, GameStatusEnum::IN_PROGRESS->value])
                ->whereHas('event', fn ($query) => $query->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt))
                ->whereHas('rosterEntries', fn ($query) => $query->whereIn('user_id', $userIds))->exists();
            if ($hasPlayerConflict) {
                throw new InvalidArgumentException('У одной из команд уже есть игра в выбранное время.');
            }
            $bookingStatus = $venue->hasFreeAccess() ? VenueBookingStatusEnum::CONFIRMED : VenueBookingStatusEnum::PENDING;
            $event->forceFill([
                'venue_id' => $venue->id, 'starts_at' => $startsAt, 'ends_at' => $endsAt,
                'status' => $bookingStatus === VenueBookingStatusEnum::CONFIRMED ? EventStatusEnum::PUBLISHED : EventStatusEnum::DRAFT,
                'participation_confirmation_version' => $event->participation_confirmation_version + 1,
            ])->save();
            $booking->forceFill(['venue_id' => $venue->id, 'status' => $bookingStatus, 'starts_at' => $startsAt, 'ends_at' => $endsAt])->save();
            $game->forceFill(['scheduled_starts_at' => $startsAt, 'scheduled_ends_at' => $endsAt])->save();

            return $event->refresh()->load(['booking', 'primaryGame']);
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
