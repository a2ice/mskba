<?php

namespace Database\Seeders;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Application\UseCases\CreateUserAccountHandler;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $createUser = app(CreateUserAccountHandler::class);

        // create super admin user
        $createUser->handle(
            username: 'superadmin',
            password: 'Superadmin1!',
            registrationChannel: UserRegistrationChannelEnum::SEED,
            systemRole: UserSystemRoleEnum::SUPERADMIN,
            status: UserStatusEnum::CONFIRMED,
            profile: [
                'first_name' => 'Супер',
                'last_name' => 'Админ',
            ],
        );

        // create admin user
        $createUser->handle(
            username: 'admin',
            password: 'Adminuser1!',
            registrationChannel: UserRegistrationChannelEnum::SEED,
            systemRole: UserSystemRoleEnum::ADMIN,
            status: UserStatusEnum::CONFIRMED,
            profile: [
                'first_name' => 'Админ',
                'last_name' => 'Пользователь',
            ],
        );

        // create moderator user
        $createUser->handle(
            username: 'moderator',
            password: 'Moderator1!',
            registrationChannel: UserRegistrationChannelEnum::SEED,
            systemRole: UserSystemRoleEnum::MODERATOR,
            status: UserStatusEnum::CONFIRMED,
            profile: [
                'first_name' => 'Модератор',
                'last_name' => 'Пользователь',
            ],
        );

        // create editor user
        $createUser->handle(
            username: 'editor',
            password: 'Editoruser1!',
            registrationChannel: UserRegistrationChannelEnum::SEED,
            systemRole: UserSystemRoleEnum::EDITOR,
            status: UserStatusEnum::CONFIRMED,
            profile: [
                'first_name' => 'Редактор',
                'last_name' => 'Пользователь',
            ],
        );

        $count = 10;

        for ($i = 0; $i < $count; $i++) {
            $index = $i + 1;

            $is_temporary_password = (bool) rand(0, 1);
            $status = rand(0, 1) ? UserStatusEnum::CONFIRMED->value : UserStatusEnum::UNCONFIRMED->value;

            $gender = rand(0, 2) ? UserGenderEnum::MALE->value : UserGenderEnum::FEMALE->value;

            $user = $createUser->handle(
                username: 'user_'.$index,
                password: 'Asdqwe12#',
                registrationChannel: UserRegistrationChannelEnum::SEED,
                systemRole: UserSystemRoleEnum::USER,
                status: UserStatusEnum::from($status),
                isTemporaryPassword: $is_temporary_password,
                profile: [
                    'first_name' => fake()->firstName(),
                    'last_name' => fake()->lastName(),
                    'gender' => $gender,
                    'birth_date' => fake()->date(),
                ],
            );

            collect(UserParticipationRoleEnum::cases())
                ->shuffle()
                ->take(rand(0, 3))
                ->each(function (UserParticipationRoleEnum $role) use ($user): void {
                    $status = rand(0, 1) ? UserParticipationRoleStatusEnum::ACTIVE->value : UserParticipationRoleStatusEnum::INACTIVE->value;

                    $user->participationRoles()->create([
                        'role' => $role->value,
                        'status' => $status,
                        'assigned_at' => fake()->dateTimeBetween('-1 year', 'now'),
                        'expires_at' => fake()->dateTimeBetween('now', '+1 year'),
                        'assigned_by' => null,
                        'assigner' => UserParticipationRoleAssignerEnum::SEEDER->value,
                        'comment' => fake()->sentence(),
                    ]);
                });

            if ($index <= 6) {
                $user->participationRoles()->updateOrCreate(
                    ['role' => UserParticipationRoleEnum::PLAYER->value],
                    [
                        'status' => UserParticipationRoleStatusEnum::ACTIVE->value,
                        'assigned_at' => fake()->dateTimeBetween('-1 year', 'now'),
                        'expires_at' => null,
                        'assigned_by' => null,
                        'assigner' => UserParticipationRoleAssignerEnum::SEEDER->value,
                        'comment' => 'Демо-профиль игрока.',
                    ],
                );

                $user->playerProfile()->create([
                    'height_cm' => fake()->numberBetween(170, 205),
                    'weight_kg' => fake()->randomFloat(1, 65, 110),
                    'position' => fake()->randomElement(PlayerPositionEnum::cases())->value,
                    'experience_started_year' => fake()->numberBetween(2008, now()->year - 1),
                    'comment' => fake()->sentence(),
                    'extra' => [
                        'preferred_format' => fake()->randomElement(['3x3', '5x5', 'training']),
                    ],
                ]);
            }

        }

        // create regular users with profiles
        for ($i = 0; $i < 10; $i++) {
            $createUser->handle(
                username: fake()->unique()->userName(),
                password: 'Asdqwe12#',
                registrationChannel: UserRegistrationChannelEnum::SEED,
                systemRole: UserSystemRoleEnum::USER,
                status: UserStatusEnum::UNCONFIRMED,
                isTemporaryPassword: false,
                profile: [
                    'first_name' => fake()->firstName(),
                    'last_name' => fake()->lastName(),
                    'middle_name' => rand(0, 1) ? fake()->firstName() : null,
                    'gender' => fake()->optional()->randomElement(UserGenderEnum::cases())?->value,
                    'birth_date' => fake()->optional()->date(),
                ],
            );
        }

        $venues = [
            [
                'name' => 'Баскетбольный зал МСКБА',
                'alias' => 'mskba-basketball-hall',
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'description' => 'Основной зал для игр и тренировок.',
            ],
            [
                'name' => 'Школа N 2107',
                'alias' => 'school-2107',
                'type' => VenueTypeEnum::SCHOOL->value,
                'description' => 'Школьная площадка для матчей районного уровня.',
            ],
            [
                'name' => 'Спорткомплекс Олимп',
                'alias' => 'olimp-sports-complex',
                'type' => VenueTypeEnum::SPORTS_COMPLEX->value,
                'description' => 'Универсальный спорткомплекс с баскетбольной разметкой.',
            ],
            [
                'name' => 'Арена Север',
                'alias' => 'arena-sever',
                'type' => VenueTypeEnum::ARENA->value,
                'description' => 'Арена для турниров и финальных игр.',
            ],
            [
                'name' => 'Университетский зал МГУ',
                'alias' => 'msu-university-hall',
                'type' => VenueTypeEnum::UNIVERSITY->value,
                'description' => 'Зал для студенческих соревнований.',
            ],
            [
                'name' => 'Площадка Парк Победы',
                'alias' => 'park-pobedy-court',
                'type' => VenueTypeEnum::STREET_COURT->value,
                'description' => 'Открытая площадка для 3x3 и любительских игр.',
            ],
            [
                'name' => 'ФОК Динамо',
                'alias' => 'dinamo-sports-complex',
                'type' => VenueTypeEnum::SPORTS_COMPLEX->value,
                'description' => 'Физкультурно-оздоровительный комплекс для регулярных матчей.',
            ],
            [
                'name' => 'Школьный зал Лицей',
                'alias' => 'lyceum-school-hall',
                'type' => VenueTypeEnum::SCHOOL->value,
                'description' => 'Компактный школьный зал для тренировок.',
            ],
        ];

        $venueOwner = User::query()
            ->where('username', 'admin')
            ->firstOrFail();

        foreach ($venues as $venue) {
            $createdVenue = Venue::factory()->create([
                ...$venue,
                'created_by_user_id' => $venueOwner->id,
                'status' => rand(0, 1) ? VenueStatusEnum::CONFIRMED->value : VenueStatusEnum::UNCONFIRMED->value,
            ]);

            $this->createVenueMembership(
                user: $venueOwner,
                venue: $createdVenue,
                accessLevel: VenueMembershipAccessLevelEnum::OWNER,
                numberSuffix: 'OWNER-'.$createdVenue->id,
            );
        }

        $contractUsers = User::query()
            ->whereIn('username', ['user_1', 'user_2', 'user_3'])
            ->get();

        $contractVenues = Venue::query()
            ->orderBy('id')
            ->take(4)
            ->get();

        $contractUsers->each(function (User $user) use ($contractVenues): void {
            $contractVenues
                ->shuffle()
                ->take(rand(1, 3))
                ->values()
                ->each(function (Venue $venue, int $index) use ($user): void {
                    $accessLevel = match ($index) {
                        0 => VenueMembershipAccessLevelEnum::ADMIN,
                        1 => VenueMembershipAccessLevelEnum::MANAGER,
                        default => fake()->randomElement([
                            VenueMembershipAccessLevelEnum::STAFF,
                            VenueMembershipAccessLevelEnum::AGENT,
                        ]),
                    };

                    $permissions = collect($accessLevel->defaultPermissions());

                    if ($accessLevel === VenueMembershipAccessLevelEnum::ADMIN && rand(0, 1) === 1) {
                        $permissions = $permissions->reject(fn (VenuePermissionEnum $permission) => $permission === VenuePermissionEnum::EDIT_SCHEDULE);
                    }

                    $this->createVenueMembership(
                        user: $user,
                        venue: $venue,
                        accessLevel: $accessLevel,
                        numberSuffix: $user->id.'-'.$venue->id,
                        permissions: $permissions->all(),
                    );
                });
        });
    }

    /**
     * @param array<VenuePermissionEnum>|null $permissions
     */
    private function createVenueMembership(
        User $user,
        Venue $venue,
        VenueMembershipAccessLevelEnum $accessLevel,
        string $numberSuffix,
        ?array $permissions = null,
    ): Contract {
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP->value,
            'number' => 'SEED-VENUE-'.$numberSuffix,
            'name' => $accessLevel->label().' - '.$venue->name,
            'status' => ContractStatusEnum::ACTIVE->value,
            'starts_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'expires_at' => null,
            'assigner' => UserParticipationRoleAssignerEnum::SEEDER->value,
        ]);

        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE->value,
            'scope_id' => $venue->id,
            'user_id' => $user->id,
            'access_level' => $accessLevel->value,
        ]);

        collect($permissions ?? $accessLevel->defaultPermissions())
            ->each(fn (VenuePermissionEnum $permission) => $contract->permissions()->create([
                'permission' => $permission->value,
            ]));

        return $contract;
    }
}
