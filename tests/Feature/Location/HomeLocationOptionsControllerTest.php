<?php

namespace Tests\Feature\Location;

use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeLocationOptionsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_public_endpoint_returns_cities_with_their_districts_and_address_urls(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);
        Carbon::setTestNow(Carbon::create(2026, 9, 7, 23, 30, 0, 'Europe/Moscow'));

        $moscow = City::factory()->create([
            'name' => 'Москва',
            'alias' => 'moscow',
            'short_name' => 'МСК',
        ]);
        $khimki = City::factory()->create([
            'name' => 'Химки',
            'alias' => 'khimki',
        ]);

        District::factory()->for($moscow)->create([
            'name' => 'Центральный административный округ',
            'alias' => 'tsao',
            'short_name' => 'ЦАО',
        ]);
        District::factory()->for($khimki)->create([
            'name' => 'Сходня',
            'alias' => 'skhodnya',
            'short_name' => null,
        ]);

        $response = $this->getJson(route('home.location-options'));

        $response
            ->assertOk()
            ->assertJsonPath('cities.0.name', 'Москва')
            ->assertJsonPath('cities.0.districts.0.short_name', 'ЦАО')
            ->assertJsonPath('cities.1.name', 'Химки')
            ->assertJsonPath('cities.1.districts.0.name', 'Сходня')
            ->assertJsonPath('address_suggest_url', route('integrations.address-suggest'))
            ->assertJsonPath('address_reverse_url', route('integrations.address-reverse'))
            ->assertJsonPath('timezone', 'Europe/Moscow')
            ->assertJsonPath('default_date_from', '2026-09-07')
            ->assertJsonPath('default_date_to', '2026-09-14');
    }
}
