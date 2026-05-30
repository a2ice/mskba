<?php

namespace Database\Seeders;

use App\Modules\Contract\Domain\Enums\ContractPartyRoleEnum;
use App\Modules\Contract\Domain\Enums\ContractPartyTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Application\UseCases\CreateUserAccountHandler;
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
use App\Modules\Venue\Domain\Models\VenueContract;
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

        foreach ($venues as $venue) {
            Venue::factory()->create([
                ...$venue,
                'status' => rand(0, 1) ? VenueStatusEnum::CONFIRMED->value : VenueStatusEnum::UNCONFIRMED->value,
            ]);
        }

        $contractUsers = User::query()
            ->whereIn('username', ['user_1', 'user_2', 'admin'])
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
                    $contract = Contract::query()->create([
                        'number' => 'SEED-'.$user->id.'-'.$venue->id,
                        'name' => 'Контракт '.$user->username.' - '.$venue->name,
                        'status' => ContractStatusEnum::ACTIVE->value,
                        'starts_at' => fake()->dateTimeBetween('-1 month', 'now'),
                        'expires_at' => fake()->dateTimeBetween('+1 month', '+1 year'),
                        'assigner' => UserParticipationRoleAssignerEnum::SEEDER->value,
                    ]);

                    $contract->parties()->create([
                        'party_type' => ContractPartyTypeEnum::USER->value,
                        'party_id' => $user->id,
                        'role' => ContractPartyRoleEnum::HOLDER->value,
                    ]);

                    $venueContract = VenueContract::query()->create([
                        'contract_id' => $contract->id,
                        'venue_id' => $venue->id,
                    ]);

                    $venueContract->permissions()->create([
                        'permission' => VenuePermissionEnum::VIEW->value,
                    ]);

                    if ($index === 0 || rand(0, 1)) {
                        $venueContract->permissions()->create([
                            'permission' => VenuePermissionEnum::EDIT->value,
                        ]);
                    }

                    if ($index === 0 || rand(0, 1)) {
                        $venueContract->permissions()->create([
                            'permission' => VenuePermissionEnum::EDIT_SCHEDULE->value,
                        ]);
                    }
                });
        });
    }
}
