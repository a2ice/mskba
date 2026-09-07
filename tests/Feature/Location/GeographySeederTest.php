<?php

namespace Tests\Feature\Location;

use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\City;
use Database\Seeders\GeographySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeographySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_initial_geography_and_backfills_known_address_cities(): void
    {
        $moscowAddress = Address::factory()->create(['city' => 'Москва', 'city_id' => null]);
        $khimkiAddress = Address::factory()->create(['city' => 'Химки', 'city_id' => null]);
        $unknownAddress = Address::factory()->create(['city' => 'Тверь', 'city_id' => null]);

        $this->seed(GeographySeeder::class);

        $moscow = City::query()->where('alias', 'moscow')->firstOrFail();
        $khimki = City::query()->where('alias', 'khimki')->firstOrFail();

        $this->assertSame(12, $moscow->districts()->count());
        $this->assertSame(9, $khimki->districts()->count());
        $this->assertSame((int) $moscow->id, (int) $moscowAddress->fresh()->city_id);
        $this->assertSame((int) $khimki->id, (int) $khimkiAddress->fresh()->city_id);
        $this->assertNull($unknownAddress->fresh()->city_id);
        $this->assertNull($moscowAddress->fresh()->district_id);
    }

    public function test_repeated_seed_does_not_overwrite_or_duplicate_admin_edited_directory_values(): void
    {
        $this->seed(GeographySeeder::class);

        $moscow = City::query()->where('alias', 'moscow')->firstOrFail();
        $sao = $moscow->districts()->where('alias', 'sao')->firstOrFail();
        $moscow->update([
            'alias' => 'moscow-admin',
            'description' => 'Изменено администратором',
        ]);
        $sao->update(['alias' => 'north-admin']);
        $newAddress = Address::factory()->create(['city' => 'Москва', 'city_id' => null]);

        $this->seed(GeographySeeder::class);

        $moscow->refresh();
        $sao->refresh();

        $this->assertSame('moscow-admin', $moscow->alias);
        $this->assertSame('Изменено администратором', $moscow->description);
        $this->assertSame('north-admin', $sao->alias);
        $this->assertSame(2, City::query()->count());
        $this->assertSame(12, $moscow->districts()->count());
        $this->assertSame((int) $moscow->id, (int) $newAddress->fresh()->city_id);
    }
}
