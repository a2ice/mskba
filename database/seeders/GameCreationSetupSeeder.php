<?php

namespace Database\Seeders;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
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
use RuntimeException;

final class GameCreationSetupSeeder extends Seeder
{
    public const PASSWORD = 'demo-password';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('GameCreationSetupSeeder разрешён только в local/testing окружении.');
        }

        DB::transaction(function (): void {
            $players = collect(range(1, 13))
                ->map(fn (int $number): User => $this->player($number));
            $owner = $players->first();
            $actor = app(CurrentActorResolver::class)->resolve($owner, null);
            if (! $actor instanceof Actor) {
                throw new RuntimeException('Не удалось создать actor владельца команд.');
            }

            $this->venue();
            $this->team($actor, 'acceptance-orange', '[TEST] Оранжевые', $players->take(7)->all());
            $this->team($actor, 'acceptance-black', '[TEST] Чёрные', $players->skip(7)->take(6)->all());
        });
    }

    private function player(int $number): User
    {
        $user = User::withTrashed()->firstOrNew(['username' => "game-player-{$number}"]);
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
            ['first_name' => "Игрок {$number}", 'last_name' => 'Тестовый'],
        );

        return $user;
    }

    private function venue(): Venue
    {
        $address = Address::query()->updateOrCreate(
            ['full_address' => 'Москва, Тестовая улица, 13'],
            [
                'city' => 'Москва',
                'street' => 'Тестовая улица',
                'building' => '13',
                'latitude' => 55.760186,
                'longitude' => 37.618711,
            ],
        );
        $location = Location::query()->firstOrCreate(['address_id' => $address->id]);

        return Venue::withTrashed()->updateOrCreate(
            ['alias' => 'game-creation-arena'],
            [
                'name' => '[TEST] Площадка для новой игры',
                'location_id' => $location->id,
                'type' => VenueTypeEnum::ARENA,
                'status' => VenueStatusEnum::CONFIRMED,
                'operational_status' => VenueOperationalStatusEnum::ACTIVE,
                'raw_address' => 'Москва, Тестовая улица, 13',
                'requires_payment' => false,
                'requires_booking_approval' => false,
                'deleted_at' => null,
            ],
        );
    }

    /** @param list<User> $players */
    private function team(Actor $actor, string $alias, string $name, array $players): Team
    {
        $team = Team::withTrashed()->updateOrCreate(
            ['alias' => $alias],
            [
                'created_by_actor_id' => $actor->id,
                'name' => $name,
                'description' => 'Команда для ручной проверки создания и проведения игры.',
                'status' => TeamStatusEnum::ACTIVE,
                'deleted_at' => null,
            ],
        );
        $profile = $team->sportProfiles()->updateOrCreate([
            'sport_type' => TeamSportTypeEnum::BASKETBALL,
        ]);

        $memberships = collect($players)->values()->map(function (User $player, int $index) use ($team): ContractMembership {
            return $this->membership(
                $team,
                $player,
                $index === 0 ? TeamMembershipAccessLevelEnum::OWNER : TeamMembershipAccessLevelEnum::PLAYER,
                $index === 0,
                $index < 5,
            );
        });

        $profile->lineupMembers()->delete();
        foreach ($memberships as $position => $membership) {
            $profile->lineupMembers()->create([
                'contract_membership_id' => $membership->id,
                'assignment' => $position < 5
                    ? TeamLineupAssignmentEnum::STARTER
                    : TeamLineupAssignmentEnum::RESERVE,
                'position' => $position,
            ]);
        }

        return $team;
    }

    private function membership(
        Team $team,
        User $user,
        TeamMembershipAccessLevelEnum $accessLevel,
        bool $captain,
        bool $starter,
    ): ContractMembership {
        $contract = Contract::query()->updateOrCreate(
            ['number' => "game-setup-{$team->alias}-{$user->username}"],
            [
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => "Тестовое членство {$team->name}",
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
                'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
                'is_captain' => $captain,
                'is_default_starter' => $starter,
                'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
            ],
        );
    }
}
