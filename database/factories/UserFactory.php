<?php

namespace Database\Factories;

use App\Modules\Contact\Domain\Enums\ContactStatusEnum;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

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
        return [
            'login' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            Contact::factory()->create([
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'contact_type' => ContactTypeEnum::EMAIL->value,
                'value' => $user->email,
                'status' => ContactStatusEnum::VERIFIED->value,
            ]);
        });
    }
}
