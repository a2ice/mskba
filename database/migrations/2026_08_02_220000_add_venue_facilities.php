<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table): void {
            $table->string('applies_to', 32)->default('all')->after('icon')->index();
        });

        Schema::create('venue_characteristics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->unique()->constrained('venues')->cascadeOnDelete();
            $table->unsignedTinyInteger('hoops_count')->nullable();
            $table->unsignedTinyInteger('hoops_condition')->nullable();
            $table->unsignedTinyInteger('surface_condition')->nullable();
            $table->string('first_hoop_marking', 16)->nullable();
            $table->string('second_hoop_marking', 16)->nullable();
            $table->timestamps();
        });

        $now = now();
        $amenities = [
            ['name' => 'Трибуны / зрительские места', 'alias' => 'stands', 'icon' => 'ti-armchair', 'applies_to' => 'all', 'sort_order' => 10],
            ['name' => 'Парковка', 'alias' => 'parking', 'icon' => 'ti-parking', 'applies_to' => 'all', 'sort_order' => 20],
            ['name' => 'Раздевалка', 'alias' => 'locker-room', 'icon' => 'ti-hanger', 'applies_to' => 'indoor', 'sort_order' => 30],
            ['name' => 'Душевая', 'alias' => 'shower', 'icon' => 'ti-shower', 'applies_to' => 'indoor', 'sort_order' => 40],
            ['name' => 'Туалет', 'alias' => 'toilet', 'icon' => 'ti-toilet-paper', 'applies_to' => 'indoor', 'sort_order' => 50],
            ['name' => 'Табло', 'alias' => 'scoreboard', 'icon' => 'ti-scoreboard', 'applies_to' => 'indoor', 'sort_order' => 60],
            ['name' => 'Инвентарь', 'alias' => 'equipment', 'icon' => 'ti-ball-basketball', 'applies_to' => 'indoor', 'sort_order' => 70],
            ['name' => 'Скамейки команд', 'alias' => 'team-benches', 'icon' => 'ti-armchair-2', 'applies_to' => 'indoor', 'sort_order' => 80],
            ['name' => 'Вентиляция', 'alias' => 'ventilation', 'icon' => 'ti-wind', 'applies_to' => 'indoor', 'sort_order' => 90],
            ['name' => 'Отопление', 'alias' => 'heating', 'icon' => 'ti-flame', 'applies_to' => 'indoor', 'sort_order' => 100],
            ['name' => 'Освещение', 'alias' => 'lighting', 'icon' => 'ti-bulb', 'applies_to' => 'outdoor', 'sort_order' => 110],
            ['name' => 'Ограждение', 'alias' => 'fence', 'icon' => 'ti-barrier-block', 'applies_to' => 'outdoor', 'sort_order' => 120],
            ['name' => 'Сетки на кольцах', 'alias' => 'hoop-nets', 'icon' => 'ti-basket', 'applies_to' => 'outdoor', 'sort_order' => 130],
            ['name' => 'Скамейки', 'alias' => 'benches', 'icon' => 'ti-armchair', 'applies_to' => 'outdoor', 'sort_order' => 140],
            ['name' => 'Навес', 'alias' => 'canopy', 'icon' => 'ti-umbrella', 'applies_to' => 'outdoor', 'sort_order' => 150],
            ['name' => 'Зимняя эксплуатация', 'alias' => 'winter-operation', 'icon' => 'ti-snowflake', 'applies_to' => 'outdoor', 'sort_order' => 160],
            ['name' => 'Уборка снега', 'alias' => 'snow-removal', 'icon' => 'ti-shovel', 'applies_to' => 'outdoor', 'sort_order' => 170],
            ['name' => 'Круглосуточный доступ', 'alias' => 'round-the-clock-access', 'icon' => 'ti-clock-24', 'applies_to' => 'outdoor', 'sort_order' => 180],
        ];

        foreach ($amenities as $amenity) {
            DB::table('amenities')->updateOrInsert(
                ['alias' => $amenity['alias']],
                $amenity + [
                    'description' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_characteristics');

        Schema::table('amenities', function (Blueprint $table): void {
            $table->dropColumn('applies_to');
        });
    }
};
