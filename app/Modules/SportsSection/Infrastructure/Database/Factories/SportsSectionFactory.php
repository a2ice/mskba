<?php

namespace App\Modules\SportsSection\Infrastructure\Database\Factories;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\SportsSection\Domain\Enums\SectionContactSourceEnum;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingModeEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SportsSection> */
final class SportsSectionFactory extends Factory
{
    protected $model = SportsSection::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'created_by_actor_id' => Actor::factory(),
            'name' => ucfirst($name),
            'alias' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => SportsSectionStatusEnum::DRAFT,
            'training_mode' => TrainingModeEnum::SMALL_GROUP,
            'game_format' => 'basketball_5x5',
            'pricing_type' => SectionPricingTypeEnum::FREE,
            'currency' => 'RUB',
            'contact_source' => SectionContactSourceEnum::HEAD_COACH,
        ];
    }
}
