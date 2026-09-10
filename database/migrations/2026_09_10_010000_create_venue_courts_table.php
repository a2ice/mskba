<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_courts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('alias', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->boolean('supports_halves')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['venue_id', 'alias'], 'venue_courts_venue_alias_unique');
            $table->index(['venue_id', 'sort_order', 'id'], 'venue_courts_order_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX venue_courts_one_primary_per_venue '
                .'ON venue_courts (venue_id) WHERE is_primary = true AND deleted_at IS NULL'
            );
        }

        $hoopsByVenue = Schema::hasTable('venue_characteristics')
            ? DB::table('venue_characteristics')->pluck('hoops_count', 'venue_id')
            : collect();
        $now = now();

        DB::table('venues')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($venues) use ($hoopsByVenue, $now): void {
                $rows = collect($venues)->map(static fn ($venue): array => [
                    'venue_id' => (int) $venue->id,
                    'name' => 'Зал 1',
                    'alias' => 'zal-1',
                    'sort_order' => 10,
                    'is_primary' => true,
                    'supports_halves' => (int) ($hoopsByVenue[(int) $venue->id] ?? 0) >= 2,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('venue_courts')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_courts');
    }
};
