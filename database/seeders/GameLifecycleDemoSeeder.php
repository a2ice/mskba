<?php

namespace Database\Seeders;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameLineupRoleEnum;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsModeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Event\Domain\Models\GameDetail;
use App\Modules\Event\Domain\Models\GamePlayerStatistic;
use App\Modules\Event\Domain\Models\GameRosterEntry;
use App\Modules\Event\Domain\Models\GameSide;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GameLifecycleDemoSeeder extends Seeder
{
    public const ORGANIZER_USERNAME = 'demo-organizer';

    public const PASSWORD = 'demo-password';

    /** @var array<string, ContractMembership> */
    private array $memberships = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('GameLifecycleDemoSeeder разрешён только в local/testing окружении.');
        }

        DB::transaction(function (): void {
            $organizer = $this->user(self::ORGANIZER_USERNAME, 'Демо', 'Организатор');
            $actor = app(CurrentActorResolver::class)->resolve($organizer, null);
            if ($actor === null) {
                throw new RuntimeException('Не удалось создать actor демонстрационного организатора.');
            }

            $playersA = $this->players('a', 'Красные');
            $playersB = $this->players('b', 'Синие');
            $venue = $this->venue();
            $teamA = $this->team($actor, $organizer, 'demo-red', '[DEMO] Красные', $playersA);
            $teamB = $this->team($actor, $organizer, 'demo-blue', '[DEMO] Синие', $playersB);

            $this->standalonePlannedGame($actor, $venue, $teamA, $teamB, $playersA, $playersB);
            $this->trainingWithMiniGames($actor, $venue, $playersA, $playersB);
            $this->completedGame($actor, $venue, $teamA, $teamB, $playersA, $playersB);
        });
    }

    /** @return array<int, User> */
    private function players(string $prefix, string $lastName): array
    {
        $players = [];
        foreach (range(1, 6) as $number) {
            $players[] = $this->user(
                "demo-{$prefix}-{$number}",
                "Игрок {$number}",
                $lastName,
            );
        }

        return $players;
    }

    private function user(string $username, string $firstName, string $lastName): User
    {
        $user = User::withTrashed()->firstOrNew(['username' => $username]);
        $user->fill([
            'password' => self::PASSWORD,
            'password_updated_at' => now(),
            'is_temporary_password' => false,
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->deleted_at = null;
        $user->save();
        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            ['first_name' => $firstName, 'last_name' => $lastName],
        );

        return $user;
    }

    private function venue(): Venue
    {
        return Venue::query()->updateOrCreate(
            ['alias' => 'demo-basketball-arena'],
            [
                'name' => '[DEMO] Баскетбольная арена',
                'type' => VenueTypeEnum::ARENA,
                'status' => VenueStatusEnum::CONFIRMED,
                'raw_address' => 'Москва, Демо-проезд, 1',
                'requires_payment' => false,
                'requires_booking_approval' => false,
            ],
        );
    }

    /** @param array<int, User> $players */
    private function team(Actor $actor, User $organizer, string $alias, string $name, array $players): Team
    {
        $sportTypes = $alias === 'demo-red'
            ? [TeamSportTypeEnum::STREETBALL, TeamSportTypeEnum::BASKETBALL]
            : [TeamSportTypeEnum::BASKETBALL];
        $team = Team::withTrashed()->updateOrCreate(
            ['alias' => $alias],
            [
                'created_by_actor_id' => $actor->id,
                'name' => $name,
                'description' => 'Демонстрационная команда для проверки составов и игровых ролей.',
                'status' => TeamStatusEnum::ACTIVE,
                'deleted_at' => null,
            ],
        );

        foreach ($sportTypes as $sportType) {
            $team->sportProfiles()->updateOrCreate(['sport_type' => $sportType]);
        }
        $team->sportProfiles()->whereNotIn('sport_type', array_column($sportTypes, 'value'))->delete();

        $this->membership($team, $organizer, TeamMembershipAccessLevelEnum::OWNER, TeamMemberTypeEnum::COACH);
        foreach ($players as $index => $player) {
            $this->memberships[$alias.':'.$player->id] = $this->membership(
                $team,
                $player,
                TeamMembershipAccessLevelEnum::PLAYER,
                TeamMemberTypeEnum::PLAYER,
                captain: $index === 0,
                starter: $index < 3,
            );
        }

        $team->load('sportProfiles.lineupMembers');
        $playerMemberships = collect($players)->map(fn (User $player) => $this->memberships[$alias.':'.$player->id])->values();
        foreach ($team->sportProfiles as $profile) {
            $limit = $profile->sport_type === TeamSportTypeEnum::STREETBALL ? 3 : 5;
            $profile->lineupMembers()->delete();
            foreach ($playerMemberships as $position => $membership) {
                $profile->lineupMembers()->create([
                    'contract_membership_id' => $membership->id,
                    'assignment' => $position < $limit ? TeamLineupAssignmentEnum::STARTER : TeamLineupAssignmentEnum::RESERVE,
                    'position' => $position,
                ]);
            }
        }

        return $team;
    }

    private function membership(
        Team $team,
        User $user,
        TeamMembershipAccessLevelEnum $access,
        TeamMemberTypeEnum $type,
        bool $captain = false,
        bool $starter = false,
    ): ContractMembership {
        $number = "demo-team-{$team->alias}-user-{$user->id}";
        $contract = Contract::query()->updateOrCreate(
            ['number' => $number],
            [
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => "Демо-членство {$team->name}",
                'status' => ContractStatusEnum::ACTIVE,
                'assigned_by' => $user->id,
                'assigned_at' => now(),
                'assigner' => UserParticipationRoleAssignerEnum::SEEDER,
            ],
        );

        return ContractMembership::query()->updateOrCreate(
            ['contract_id' => $contract->id],
            [
                'scope_type' => ContractMembershipScopeTypeEnum::TEAM,
                'scope_id' => $team->id,
                'user_id' => $user->id,
                'access_level' => $access,
                'sport_roles' => [$type->value],
                'is_captain' => $captain,
                'is_default_starter' => $starter,
                'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
            ],
        );
    }

    /** @param array<int, User> $playersA @param array<int, User> $playersB */
    private function standalonePlannedGame(
        Actor $actor,
        Venue $venue,
        Team $teamA,
        Team $teamB,
        array $playersA,
        array $playersB,
    ): void {
        $start = now()->addDay()->setTime(19, 0);
        $game = $this->event($actor, $venue, 'demo-game-planned', '[DEMO] Игра до начала', EventTypeEnum::GAME, $start, $start->copy()->addMinutes(90));
        $this->game($game, $teamA, $teamB, $playersA, $playersB);
    }

    /** @param array<int, User> $playersA @param array<int, User> $playersB */
    private function trainingWithMiniGames(Actor $actor, Venue $venue, array $playersA, array $playersB): void
    {
        $participants = [...$playersA, ...$playersB];
        $start = now()->subHour();
        $training = $this->event(
            $actor,
            $venue,
            'demo-game-training',
            '[DEMO] Игровая тренировка с мини-играми',
            EventTypeEnum::GAME_TRAINING,
            $start,
            $start->copy()->addHours(3),
        );
        $participantModels = [];
        foreach ($participants as $player) {
            $participantModels[$player->id] = EventParticipant::query()->updateOrCreate(
                ['event_id' => $training->id, 'user_id' => $player->id],
                [
                    'role' => EventParticipantRoleEnum::PARTICIPANT,
                    'status' => EventParticipantStatusEnum::CONFIRMED,
                    'joined_at' => now()->subDays(2),
                    'left_at' => null,
                ],
            );
        }

        $liveStart = now()->subMinutes(20);
        $live = $this->event($actor, $venue, 'demo-mini-game-live', '[DEMO] Мини-игра — идёт', EventTypeEnum::GAME, $liveStart, now()->addMinutes(40), $training);
        $live->update(['actual_started_at' => $liveStart, 'actual_started_by_actor_id' => $actor->id]);
        $this->game($live, null, null, array_slice($playersA, 0, 4), array_slice($playersB, 0, 4), $participantModels, locked: true);

        $endedStart = now()->subHours(2);
        $ended = $this->event($actor, $venue, 'demo-mini-game-review', '[DEMO] Мини-игра — проверить результат', EventTypeEnum::GAME, $endedStart, now()->subHour(), $training);
        $ended->update([
            'actual_started_at' => $endedStart,
            'actual_started_by_actor_id' => $actor->id,
            'actual_ended_at' => now()->subHour(),
            'actual_ended_by_actor_id' => $actor->id,
        ]);
        $this->game($ended, null, null, array_slice($playersA, 0, 4), array_slice($playersB, 0, 4), $participantModels, locked: true, ready: true);
    }

    /** @param array<int, User> $playersA @param array<int, User> $playersB */
    private function completedGame(Actor $actor, Venue $venue, Team $teamA, Team $teamB, array $playersA, array $playersB): void
    {
        $start = now()->subDays(2)->setTime(19, 0);
        $game = $this->event($actor, $venue, 'demo-game-completed', '[DEMO] Завершённая игра', EventTypeEnum::GAME, $start, $start->copy()->addMinutes(90));
        $game->update([
            'status' => EventStatusEnum::COMPLETED,
            'actual_started_at' => $start,
            'actual_started_by_actor_id' => $actor->id,
            'actual_ended_at' => $start->copy()->addMinutes(90),
            'actual_ended_by_actor_id' => $actor->id,
            'completed_at' => $start->copy()->addMinutes(100),
            'completed_by_actor_id' => $actor->id,
        ]);
        $this->game($game, $teamA, $teamB, $playersA, $playersB, locked: true, confirmed: true);
    }

    private function event(
        Actor $actor,
        Venue $venue,
        string $alias,
        string $title,
        EventTypeEnum $type,
        Carbon $startsAt,
        Carbon $endsAt,
        ?Event $parent = null,
    ): Event {
        return Event::withTrashed()->updateOrCreate(
            ['alias' => $alias],
            [
                'parent_event_id' => $parent?->id,
                'venue_id' => $venue->id,
                'organizer_actor_id' => $actor->id,
                'title' => $title,
                'type' => $type,
                'status' => EventStatusEnum::PUBLISHED,
                'visibility' => EventVisibilityEnum::PUBLIC,
                'description' => 'Демонстрационные данные для ручной проверки игрового workflow.',
                'result_description' => null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'actual_started_at' => null,
                'actual_started_by_actor_id' => null,
                'actual_ended_at' => null,
                'actual_ended_by_actor_id' => null,
                'max_participants' => 20,
                'completed_at' => null,
                'completed_by_actor_id' => null,
                'cancelled_at' => null,
                'cancelled_by_actor_id' => null,
                'cancellation_reason' => null,
                'deleted_at' => null,
            ],
        );
    }

    /**
     * @param  array<int, User>  $playersA
     * @param  array<int, User>  $playersB
     * @param  array<int, EventParticipant>  $participants
     */
    private function game(
        Event $event,
        ?Team $teamA,
        ?Team $teamB,
        array $playersA,
        array $playersB,
        array $participants = [],
        bool $locked = false,
        bool $ready = false,
        bool $confirmed = false,
    ): void {
        GamePlayerStatistic::query()->where('event_id', $event->id)->delete();

        GameDetail::query()->updateOrCreate(
            ['event_id' => $event->id],
            [
                'side_a_size' => 3,
                'side_b_size' => 3,
                'scoring_type' => GameScoringTypeEnum::BASKETBALL,
                'is_time_scheduled' => true,
                'statistics_mode' => GameStatisticsModeEnum::FULL,
                'statistics_status' => $confirmed
                    ? GameStatisticsStatusEnum::CONFIRMED
                    : ($ready ? GameStatisticsStatusEnum::READY : GameStatisticsStatusEnum::NOT_STARTED),
                'statistics_version' => 1,
                'statistics_confirmed_at' => $confirmed ? $event->completed_at : null,
                'statistics_confirmed_by_actor_id' => $confirmed ? $event->completed_by_actor_id : null,
            ],
        );

        $sideA = GameSide::query()->updateOrCreate(
            ['event_id' => $event->id, 'slot' => 'A'],
            ['team_id' => $teamA?->id, 'display_name' => $teamA?->name ?? 'Красные', 'score' => $ready || $confirmed ? 21 : 0],
        );
        $sideB = GameSide::query()->updateOrCreate(
            ['event_id' => $event->id, 'slot' => 'B'],
            ['team_id' => $teamB?->id, 'display_name' => $teamB?->name ?? 'Синие', 'score' => $ready || $confirmed ? 18 : 0],
        );

        $this->roster($event, $sideA, $teamA, $playersA, $participants, $locked, $confirmed);
        $this->roster($event, $sideB, $teamB, $playersB, $participants, $locked, $confirmed);
    }

    /** @param array<int, User> $players @param array<int, EventParticipant> $participants */
    private function roster(
        Event $event,
        GameSide $side,
        ?Team $team,
        array $players,
        array $participants,
        bool $locked,
        bool $confirmed,
    ): void {
        foreach ($players as $index => $player) {
            $entry = GameRosterEntry::query()->updateOrCreate(
                ['event_id' => $event->id, 'user_id' => $player->id],
                [
                    'game_side_id' => $side->id,
                    'source_contract_membership_id' => $team ? $this->memberships[$team->alias.':'.$player->id]->id : null,
                    'source_event_participant_id' => ($participants[$player->id] ?? null)?->id,
                    'status' => $confirmed ? GameRosterStatusEnum::PLAYED : GameRosterStatusEnum::SELECTED,
                    'lineup_role' => $index < 3 ? GameLineupRoleEnum::STARTER : GameLineupRoleEnum::BENCH,
                    'is_captain' => $index === 0,
                    'locked_at' => $locked ? ($event->actual_started_at ?? now()) : null,
                ],
            );

            if ($confirmed) {
                GamePlayerStatistic::query()->updateOrCreate(
                    ['event_id' => $event->id, 'user_id' => $player->id],
                    [
                        'game_side_id' => $side->id,
                        'minutes' => $index < 3 ? 30 : 12,
                        'close_made' => $index === 0 ? 3 : 0,
                        'close_attempted' => $index === 0 ? 5 : 0,
                        'assists' => $index,
                        'defensive_rebounds' => 2 + $index,
                    ],
                );
            }
        }
    }
}
