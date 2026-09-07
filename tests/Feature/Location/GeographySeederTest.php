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

    public function test_repeated_seed_does_not_overwrite_admin_edited_directory_values(): void
    {
        $this->seed(GeographySeeder::class);

        $moscow = City::query()->where('alias', 'moscow')->firstOrFail();
        $moscow->update(['description' => 'Изменено администратором']);

        $this->seed(GeographySeeder::class);

        $this->assertSame('Изменено администратором', $moscow->fresh()->description);
        $this->assertSame(2, City::query()->count());
    }
}
