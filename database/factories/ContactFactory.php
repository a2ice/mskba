<?php

namespace Database\Factories;

use App\Modules\Contact\Domain\Enums\ContactStatusEnum;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        $contactType = fake()->randomElement(ContactTypeEnum::cases());

        $value = match ($contactType) {
            ContactTypeEnum::EMAIL => fake()->unique()->safeEmail(),
            ContactTypeEnum::PHONE => '+79' . fake()->numerify('#########'),
            ContactTypeEnum::TELEGRAM => '@' . fake()->unique()->userName(),
            ContactTypeEnum::VK => 'vk.com/' . fake()->unique()->userName(),
            ContactTypeEnum::OTHER => 'contact:' . fake()->unique()->userName(),
        };

        return [
            'entity_type' => 'user',
            'entity_id' => 1,
            'contact_type' => $contactType->value,
            'value' => $value,
            'status' => ContactStatusEnum::UNVERIFIED->value,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => [
            'status' => ContactStatusEnum::VERIFIED->value,
        ]);
    }
}
