<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $categoryId = DB::table('pricing_categories')->insertGetId([
            'code' => 'ai_generation',
            'name' => 'AI-генерация',
            'description' => 'Генерации изображений на основе профилей, команд и игровых событий.',
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $services = [
            ['code' => 'avatar_generation', 'name' => 'Генерация аватара', 'description' => 'Персональная генерация изображения для модели игрока.', 'sort_order' => 10],
            ['code' => 'team_generation', 'name' => 'Групповая генерация команды', 'description' => 'Командное изображение на основе существующих моделей участников.', 'sort_order' => 20],
            ['code' => 'game_highlights_generation', 'name' => 'Генерация хайлайтов игры', 'description' => 'Изображение по ключевым игровым эпизодам и статистике матча.', 'sort_order' => 30],
        ];

        foreach ($services as $service) {
            $serviceId = DB::table('pricing_services')->insertGetId([
                'category_id' => $categoryId,
                'code' => $service['code'],
                'name' => $service['name'],
                'description' => $service['description'],
                'sort_order' => $service['sort_order'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('pricing_prices')->insert([
                'service_id' => $serviceId,
                'variant_id' => null,
                'amount_minor' => 10000,
                'currency' => 'RUB',
                'valid_from' => $now,
                'valid_until' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $serviceIds = DB::table('pricing_services')
            ->whereIn('code', ['avatar_generation', 'team_generation', 'game_highlights_generation'])
            ->pluck('id');

        DB::table('pricing_prices')->whereIn('service_id', $serviceIds)->delete();
        DB::table('pricing_services')->whereIn('id', $serviceIds)->delete();
        DB::table('pricing_categories')->where('code', 'ai_generation')->delete();
    }
};
