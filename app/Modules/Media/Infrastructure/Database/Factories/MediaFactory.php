<?php

namespace App\Modules\Media\Infrastructure\Database\Factories;

use App\Modules\Media\Domain\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'collection' => 'gallery',
            'disk' => 'public',
            'path' => 'venues/'.fake()->uuid().'.jpg',
            'title' => fake()->optional()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'mime' => 'image/jpeg',
            'size' => fake()->numberBetween(120_000, 1_800_000),
            'is_featured' => fake()->boolean(35),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
