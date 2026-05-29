<?php

namespace Database\Seeders;

use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Models\User;
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
        // create super admin user
        $superadmin = User::factory()->create([
            'username' => 'superadmin',
            'password' => 'superadmin_password',
            'is_temporary_password' => false,
            'registration_channel' => UserRegistrationChannelEnum::SEED->value,
            'system_role' => UserSystemRoleEnum::SUPERADMIN->value,
            'status' => UserStatusEnum::CONFIRMED->value,
        ]);
        $superadmin->profile()->create([
            'first_name' => 'Супер',
            'last_name' => 'Админ',
        ]);

        // create admin user
        $admin = User::factory()->create([
            'username' => 'admin',
            'password' => 'admin_password',
            'is_temporary_password' => false,
            'registration_channel' => UserRegistrationChannelEnum::SEED->value,
            'system_role' => UserSystemRoleEnum::ADMIN->value,
            'status' => UserStatusEnum::CONFIRMED->value,
        ]);
        $admin->profile()->create([
            'first_name' => 'Админ',
            'last_name' => 'Пользователь',
        ]);

        // create moderator user
        $moderator = User::factory()->create([
            'username' => 'moderator',
            'password' => 'moderator_password',
            'is_temporary_password' => false,
            'registration_channel' => UserRegistrationChannelEnum::SEED->value,
            'system_role' => UserSystemRoleEnum::MODERATOR->value,
            'status' => UserStatusEnum::CONFIRMED->value,
        ]);
        $moderator->profile()->create([
            'first_name' => 'Модератор',
            'last_name' => 'Пользователь',
        ]);

        // create editor user
        $editor = User::factory()->create([
            'username' => 'editor',
            'password' => 'editor_password',
            'is_temporary_password' => false,
            'registration_channel' => UserRegistrationChannelEnum::SEED->value,
            'system_role' => UserSystemRoleEnum::EDITOR->value,
            'status' => UserStatusEnum::CONFIRMED->value,
        ]);
        $editor->profile()->create([
            'first_name' => 'Редактор',
            'last_name' => 'Пользователь',
        ]);

        $count = 10;

        for ($i = 0; $i < $count; $i++) {
            $index = $i + 1;

            $is_temporary_password = (bool) rand(0, 1);
            $status = rand(0, 1) ? UserStatusEnum::CONFIRMED->value : UserStatusEnum::UNCONFIRMED->value;

            $user = User::factory()->create([
                'username' => 'user_'.$index,
                'password' => 'Asdqwe12#',
                'is_temporary_password' => $is_temporary_password,
                'registration_channel' => UserRegistrationChannelEnum::SEED->value,
                'system_role' => UserSystemRoleEnum::USER->value,
                'status' => $status,
            ]);

            $gender = rand(0, 2) ? UserGenderEnum::MALE->value : UserGenderEnum::FEMALE->value;

            $user->profile()->create([
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'gender' => $gender,
                'birth_date' => fake()->date(),
            ]);

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
        User::factory(10)
            ->has(Profile::factory())
            ->create();

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

    }
}
