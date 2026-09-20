<?php

namespace Database\Seeders;

use App\Modules\Pricing\Domain\Models\PricingCategory;
use App\Modules\Pricing\Domain\Models\PricingPrice;
use App\Modules\Pricing\Domain\Models\PricingService;
use Illuminate\Database\Seeder;

final class PricingCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $category = PricingCategory::query()->firstOrCreate(
            ['code' => 'ai_generation'],
            [
                'name' => 'AI-генерация',
                'description' => 'Генерации изображений на основе профилей, команд и игровых событий.',
                'sort_order' => 10,
                'is_active' => true,
            ],
        );

        $services = [
            [
                'code' => 'avatar_generation',
                'name' => 'Генерация аватара',
                'description' => 'Персональная генерация изображения для модели игрока.',
                'sort_order' => 10,
            ],
            [
                'code' => 'team_generation',
                'name' => 'Групповая генерация команды',
                'description' => 'Командное изображение на основе существующих моделей участников.',
                'sort_order' => 20,
            ],
            [
                'code' => 'game_highlights_generation',
                'name' => 'Генерация хайлайтов игры',
                'description' => 'Изображение по ключевым игровым эпизодам и статистике матча.',
                'sort_order' => 30,
            ],
        ];

        foreach ($services as $attributes) {
            $service = PricingService::query()->firstOrCreate(
                ['code' => $attributes['code']],
                [
                    'category_id' => $category->id,
                    'name' => $attributes['name'],
                    'description' => $attributes['description'],
                    'sort_order' => $attributes['sort_order'],
                    'is_active' => true,
                ],
            );

            if (! PricingPrice::query()->where('service_id', $service->id)->whereNull('variant_id')->exists()) {
                PricingPrice::query()->create([
                    'service_id' => $service->id,
                    'variant_id' => null,
                    'amount_minor' => 10000,
                    'currency' => 'RUB',
                    'valid_from' => now(),
                    'valid_until' => null,
                    'is_active' => true,
                ]);
            }
        }
    }
}
