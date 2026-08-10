<?php

namespace Database\Seeders;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\UseCases\StoreProfileAvatarHandler;
use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\Participation\PlayerProfile;
use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Team\Application\Services\TeamLogoManager;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class TournamentLabSeeder extends Seeder
{
    public const ORGANIZER_USERNAME = 'tournament-organizer';

    public const PASSWORD = 'demo-password';

    private const ASSET_ROOT = 'seeders/assets/tournament-lab';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('TournamentLabSeeder разрешён только в local/testing окружении.');
        }

        $organizer = $this->user(self::ORGANIZER_USERNAME, 'Организатор', 'Турниров');
        $actor = app(CurrentActorResolver::class)->resolve($organizer, null);
        if (! $actor instanceof Actor) {
            throw new RuntimeException('Не удалось создать actor организатора турнирного стенда.');
        }

        foreach ($this->venues() as $venue) {
            $this->venue($venue);
        }

        $avatarNumber = 1;
        foreach ($this->teams() as $teamNumber => $teamData) {
            $players = [];
            foreach ($teamData['players'] as $playerNumber => $playerName) {
                $players[] = $this->player($playerName, $avatarNumber++, $teamNumber, $playerNumber);
            }

            $this->team($actor, $organizer, $teamData, $teamNumber + 1, $players);
        }
    }

    private function user(string $username, string $firstName, string $lastName): User
    {
        return DB::transaction(function () use ($username, $firstName, $lastName): User {
            $user = User::withTrashed()->firstOrNew(['username' => $username]);
            $user->forceFill([
                'password' => self::PASSWORD,
                'password_updated_at' => now(),
                'is_temporary_password' => false,
                'registration_channel' => UserRegistrationChannelEnum::SEED,
                'system_role' => UserSystemRoleEnum::USER,
                'status' => UserStatusEnum::CONFIRMED,
                'deleted_at' => null,
            ])->save();
            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                ['first_name' => $firstName, 'last_name' => $lastName],
            );

            return $user;
        });
    }

    private function player(string $fullName, int $avatarNumber, int $teamNumber, int $playerNumber): User
    {
        [$firstName, $lastName] = explode(' ', $fullName, 2);
        $username = 'nba-'.Str::slug($fullName);
        $user = $this->user($username, $firstName, $lastName);

        $profile = DB::transaction(function () use ($user, $teamNumber, $playerNumber): PlayerProfile {
            $profile = $user->playerProfile()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'height_cm' => 182 + (($teamNumber * 7 + $playerNumber * 5) % 35),
                    'weight_kg' => 78 + (($teamNumber * 9 + $playerNumber * 6) % 39),
                    'body_type' => PlayerBodyTypeEnum::cases()[($teamNumber + $playerNumber) % count(PlayerBodyTypeEnum::cases())],
                    'experience_started_year' => now()->year - 5 - (($teamNumber * 3 + $playerNumber) % 16),
                    'comment' => 'Учебный профиль для проверки формирования составов турнира.',
                ],
            );

            $profile->positions()->delete();
            $profile->positions()->create([
                'position' => PlayerPositionEnum::cases()[($teamNumber + $playerNumber) % count(PlayerPositionEnum::cases())],
            ]);
            $profile->selfAssessment()->updateOrCreate(
                ['player_profile_id' => $profile->id],
                collect(array_keys(PlayerSelfAssessment::SKILLS))
                    ->mapWithKeys(fn (string $skill, int $index): array => [
                        $skill => 4 + (($teamNumber * 2 + $playerNumber + $index) % 7),
                    ])->all(),
            );
            $user->participationRoles()->updateOrCreate(
                ['role' => UserParticipationRoleEnum::PLAYER],
                [
                    'status' => UserParticipationRoleStatusEnum::ACTIVE,
                    'assigned_at' => now(),
                    'expires_at' => null,
                    'assigned_by' => $user->id,
                    'assigner' => UserParticipationRoleAssignerEnum::SEEDER,
                    'comment' => 'Турнирный тестовый стенд.',
                ],
            );

            return $profile;
        });

        $publicProfile = $user->profile()->firstOrFail();
        $avatarPath = database_path(sprintf('%s/avatars/%02d.png', self::ASSET_ROOT, $avatarNumber));
        $sourceReference = sprintf('tournament-lab/avatars/%02d.png', $avatarNumber);
        if (! $publicProfile->media()->where('collection', 'avatar')->where('source_reference', $sourceReference)->exists()) {
            app(StoreProfileAvatarHandler::class)->handle(
                $publicProfile,
                $this->assetContents($avatarPath),
                'seed',
                $sourceReference,
            );
        }

        return $user;
    }

    /**
     * @param  array{alias: string, name: string, players: list<string>}  $data
     * @param  list<User>  $players
     */
    private function team(Actor $actor, User $organizer, array $data, int $teamNumber, array $players): Team
    {
        $team = Team::withTrashed()->updateOrCreate(
            ['alias' => $data['alias']],
            [
                'created_by_actor_id' => $actor->id,
                'name' => $data['name'],
                'description' => 'Учебная команда турнирного стенда. Название используется только для локального тестирования.',
                'status' => TeamStatusEnum::ACTIVE,
                'deleted_at' => null,
            ],
        );
        $sportProfile = $team->sportProfiles()->updateOrCreate(['sport_type' => TeamSportTypeEnum::BASKETBALL]);
        $this->membership($team, $organizer, TeamMembershipAccessLevelEnum::OWNER, TeamMemberTypeEnum::MANAGER, false, false);

        $memberships = collect($players)->values()->map(fn (User $player, int $index): ContractMembership => $this->membership(
            $team,
            $player,
            $index === 0 ? TeamMembershipAccessLevelEnum::CAPTAIN : TeamMembershipAccessLevelEnum::PLAYER,
            TeamMemberTypeEnum::PLAYER,
            $index === 0,
            $index < 5,
        ));

        $sportProfile->lineupMembers()->delete();
        foreach ($memberships as $position => $membership) {
            $sportProfile->lineupMembers()->create([
                'contract_membership_id' => $membership->id,
                'assignment' => $position < 5 ? TeamLineupAssignmentEnum::STARTER : TeamLineupAssignmentEnum::RESERVE,
                'position' => $position,
            ]);
        }

        if (! $team->media()->where('collection', TeamLogoManager::COLLECTION)->where('is_featured', true)->exists()) {
            app(TeamLogoManager::class)->store(
                $team,
                $actor,
                $this->assetContents(database_path(sprintf('%s/logos/%02d.png', self::ASSET_ROOT, $teamNumber))),
            );
        }

        return $team;
    }

    private function membership(
        Team $team,
        User $user,
        TeamMembershipAccessLevelEnum $accessLevel,
        TeamMemberTypeEnum $sportRole,
        bool $captain,
        bool $starter,
    ): ContractMembership {
        $contract = Contract::query()->updateOrCreate(
            ['number' => "tournament-lab-{$team->alias}-{$user->username}"],
            [
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => "Членство в {$team->name}",
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
                'access_level' => $accessLevel,
                'sport_roles' => [$sportRole->value],
                'is_captain' => $captain,
                'is_default_starter' => $starter,
                'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
            ],
        );
    }

    /** @param array{alias: string, name: string, address: string, street: string, building: string, latitude: float, longitude: float} $data */
    private function venue(array $data): Venue
    {
        $address = Address::query()->updateOrCreate(
            ['full_address' => $data['address']],
            [
                'city' => 'Москва',
                'street' => $data['street'],
                'building' => $data['building'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ],
        );
        $location = Location::query()->firstOrCreate(['address_id' => $address->id]);

        return Venue::withTrashed()->updateOrCreate(
            ['alias' => $data['alias']],
            [
                'name' => $data['name'],
                'location_id' => $location->id,
                'type' => VenueTypeEnum::ARENA,
                'status' => VenueStatusEnum::CONFIRMED,
                'operational_status' => VenueOperationalStatusEnum::ACTIVE,
                'raw_address' => $data['address'],
                'requires_payment' => false,
                'requires_booking_approval' => false,
                'deleted_at' => null,
            ],
        );
    }

    private function assetContents(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Не удалось прочитать fixture {$path}.");
        }

        return $contents;
    }

    /** @return list<array{alias: string, name: string, players: list<string>}> */
    private function teams(): array
    {
        return [
            ['alias' => 'lab-los-angeles-lakers', 'name' => 'Los Angeles Lakers', 'players' => ['LeBron James', 'Kobe Bryant', 'Magic Johnson', 'Kareem Abdul-Jabbar', 'Anthony Davis']],
            ['alias' => 'lab-boston-celtics', 'name' => 'Boston Celtics', 'players' => ['Larry Bird', 'Bill Russell', 'Jayson Tatum', 'Jaylen Brown', 'Paul Pierce', 'Kevin McHale']],
            ['alias' => 'lab-chicago-bulls', 'name' => 'Chicago Bulls', 'players' => ['Michael Jordan', 'Scottie Pippen', 'Derrick Rose', 'Dennis Rodman', 'Joakim Noah', 'Jimmy Butler', 'Toni Kukoc']],
            ['alias' => 'lab-new-york-knicks', 'name' => 'New York Knicks', 'players' => ['Patrick Ewing', 'Walt Frazier', 'Jalen Brunson', 'Carmelo Anthony', 'Willis Reed', 'Allan Houston', 'John Starks', "Amar'e Stoudemire"]],
            ['alias' => 'lab-miami-heat', 'name' => 'Miami Heat', 'players' => ['Dwyane Wade', 'Chris Bosh', 'Alonzo Mourning', 'Bam Adebayo', 'Tyler Herro', 'Tim Hardaway', 'Udonis Haslem', 'Glen Rice', "Shaquille O'Neal"]],
            ['alias' => 'lab-detroit-pistons', 'name' => 'Detroit Pistons', 'players' => ['Isiah Thomas', 'Joe Dumars', 'Ben Wallace', 'Chauncey Billups', 'Richard Hamilton', 'Grant Hill', 'Rasheed Wallace', 'Tayshaun Prince', 'Bill Laimbeer', 'Dave Bing']],
            ['alias' => 'lab-denver-nuggets', 'name' => 'Denver Nuggets', 'players' => ['Nikola Jokic', 'Jamal Murray', 'Alex English', 'Dikembe Mutombo', 'David Thompson', 'Fat Lever']],
            ['alias' => 'lab-philadelphia-76ers', 'name' => 'Philadelphia 76ers', 'players' => ['Allen Iverson', 'Julius Erving', 'Joel Embiid', 'Wilt Chamberlain', 'Moses Malone', 'Charles Barkley', 'Maurice Cheeks']],
            ['alias' => 'lab-san-antonio-spurs', 'name' => 'San Antonio Spurs', 'players' => ['Tim Duncan', 'David Robinson', 'Tony Parker', 'Manu Ginobili', 'Kawhi Leonard', 'George Gervin', 'LaMarcus Aldridge', 'Sean Elliott']],
            ['alias' => 'lab-toronto-raptors', 'name' => 'Toronto Raptors', 'players' => ['Vince Carter', 'Kyle Lowry', 'DeMar DeRozan', 'Pascal Siakam', 'Scottie Barnes', 'Fred VanVleet', 'Marc Gasol', 'Damon Stoudamire', 'Jose Calderon']],
        ];
    }

    /** @return list<array{alias: string, name: string, address: string, street: string, building: string, latitude: float, longitude: float}> */
    private function venues(): array
    {
        return [
            ['alias' => 'lab-megasport', 'name' => '[LAB] ДС «Мегаспорт»', 'address' => 'Москва, Ходынский бульвар, 3', 'street' => 'Ходынский бульвар', 'building' => '3', 'latitude' => 55.7868, 'longitude' => 37.5407],
            ['alias' => 'lab-cska', 'name' => '[LAB] УСК ЦСКА', 'address' => 'Москва, Ленинградский проспект, 39с3', 'street' => 'Ленинградский проспект', 'building' => '39с3', 'latitude' => 55.7926, 'longitude' => 37.5415],
            ['alias' => 'lab-luzhniki', 'name' => '[LAB] Дворец спорта «Лужники»', 'address' => 'Москва, улица Лужники, 24с2', 'street' => 'улица Лужники', 'building' => '24с2', 'latitude' => 55.7158, 'longitude' => 37.5537],
            ['alias' => 'lab-krylatskoe', 'name' => '[LAB] Баскет Холл Москва', 'address' => 'Москва, Островная улица, 7', 'street' => 'Островная улица', 'building' => '7', 'latitude' => 55.7597, 'longitude' => 37.4306],
        ];
    }
}
