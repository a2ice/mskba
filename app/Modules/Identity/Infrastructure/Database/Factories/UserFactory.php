<?php

namespace App\Modules\Identity\Infrastructure\Database\Factories;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $userName = rand(0, 2) ? fake()->unique()->userName() : null;
        $password = rand(0, 1) ? Hash::make('password') : null;
        $temporaryPassword = $password ? rand(0, 1) : null;

        return [
            'username' => $userName,
            'password' => $password,
            'is_temporary_password' => $temporaryPassword,
            'registration_channel' => UserRegistrationChannelEnum::SEED->value,
            'system_role' => UserSystemRoleEnum::USER->value,
            'status' => UserStatusEnum::UNCONFIRMED->value,

            /* 'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(), */

            'remember_token' => rand(0, 1) ? Str::random(10) : null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
