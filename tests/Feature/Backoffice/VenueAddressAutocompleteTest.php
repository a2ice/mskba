<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Venues\VenueAddressAutocomplete;
use App\Filament\Backoffice\Resources\Venues\Pages\CreateVenue;
use App\Filament\Backoffice\Resources\Venues\VenueBackofficeEditor;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Venue\Presentation\Http\Resources\PublicVenueResource;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class VenueAddressAutocompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_yandex_selection_populates_display_and_all_structured_address_fields(): void
    {
        config(['integrations.yandex.api_key' => 'test-key']);
        $city = City::factory()->create(['name' => 'Москва', 'alias' => 'moscow']);
        $district = District::factory()->for($city)->create([
            'name' => 'Северный административный округ',
            'alias' => 'sao',
        ]);
        $station = MetroStation::factory()->create([
            'name' => 'Тушинская',
            'latitude' => 55.82,
            'longitude' => 37.46,
        ]);

        Http::fake([
            'suggest-maps.yandex.ru/*' => Http::response(['results' => []]),
            'geocode-maps.yandex.ru/*' => Http::response($this->yandexResponse()),
        ]);

        $service = app(VenueAddressAutocomplete::class);
        $options = $service->search('Москва Тушинская улица 16А');

        $this->assertCount(1, $options);
        $token = (string) array_key_first($options);
        $this->assertSame('125362, Россия, Москва, Тушинская улица, 16А', $options[$token]);
        $this->assertSame($options[$token], VenueAddressAutocomplete::label($token));

        $data = VenueAddressAutocomplete::fields($token);
        $this->assertSame('Тушинская улица, 16А', $data['raw_address']);
        $this->assertSame($options[$token], $data['location_full_address']);
        $this->assertSame($city->id, $data['location_city_id']);
        $this->assertSame($district->id, $data['location_district_id']);
        $this->assertSame('Москва', $data['location_city']);
        $this->assertSame('Тушинская улица', $data['location_street']);
        $this->assertSame('16А', $data['location_building']);
        $this->assertSame('125362', $data['location_postal_code']);
        $this->assertSame(55.821, $data['location_latitude']);
        $this->assertSame(37.451, $data['location_longitude']);
        $this->assertContains($station->id, $data['location_metro_ids']);
    }

    public function test_selecting_yandex_suggestion_updates_livewire_form_fields(): void
    {
        config(['integrations.yandex.api_key' => 'test-key']);
        Http::fake([
            'suggest-maps.yandex.ru/*' => Http::response(['results' => []]),
            'geocode-maps.yandex.ru/*' => Http::response($this->yandexResponse()),
        ]);
        $user = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);
        $this->actingAs($user);

        $options = app(VenueAddressAutocomplete::class)->search('Москва Тушинская улица 16А');
        $token = (string) array_key_first($options);

        Livewire::test(CreateVenue::class)
            ->set('data.address_yandex_selection', $token)
            ->assertSet('data.location_full_address', '125362, Россия, Москва, Тушинская улица, 16А')
            ->assertSet('data.raw_address', 'Тушинская улица, 16А')
            ->assertSet('data.location_street', 'Тушинская улица')
            ->assertSet('data.location_building', '16А')
            ->assertSet('data.location_latitude', 55.821)
            ->assertSet('data.location_longitude', 37.451);
    }

    public function test_full_and_display_addresses_are_stored_separately_and_preserved_on_edit(): void
    {
        Queue::fake();
        $user = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);
        $this->actingAs($user);

        $editor = app(VenueBackofficeEditor::class);
        $venue = $editor->save([
            'name' => 'Площадка Тушинская',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'location_full_address' => '125362, Россия, Москва, Тушинская улица, 16А',
            'raw_address' => 'Тушинская улица, 16А',
            'location_city' => 'Москва',
            'location_street' => 'Тушинская улица',
            'location_building' => '16А',
            'location_postal_code' => '125362',
            'location_latitude' => 55.821,
            'location_longitude' => 37.451,
        ]);

        $this->assertSame('Тушинская улица, 16А', $venue->raw_address);
        $public = new PublicVenueResource(
            $venue->load('location.address', 'media'),
            app(AddressDisplayFormatter::class),
        );
        $this->assertSame('Тушинская улица, 16А', $public->toArray(Request::create('/'))['address']);
        $this->assertSame('125362, Россия, Москва, Тушинская улица, 16А',
            $venue->location->address->full_address);

        $fill = $editor->fields($venue);
        $this->assertSame($venue->location->address->full_address,
            VenueAddressAutocomplete::label($fill['address_yandex_selection']));
        $this->assertSame([], VenueAddressAutocomplete::fields($fill['address_yandex_selection']));

        $originalLocationId = $venue->location_id;
        $updated = $editor->save(array_replace([
            'name' => $venue->name,
            'alias' => $venue->alias,
            'type' => $venue->type->value,
            'status' => $venue->status->value,
            'operational_status' => $venue->operational_status->value,
            'raw_address' => 'Тушинская улица, д. 16А',
        ], $fill, ['raw_address' => 'Тушинская улица, д. 16А']), $venue);

        $this->assertSame('Тушинская улица, д. 16А', $updated->raw_address);
        $this->assertSame($originalLocationId, $updated->location_id);
        $this->assertSame('125362, Россия, Москва, Тушинская улица, 16А',
            $updated->location->address->full_address);
    }

    public function test_existing_address_remains_visible_and_invalid_tokens_cannot_autofill(): void
    {
        $saved = VenueAddressAutocomplete::existing('Москва, Тушинская улица, 16А');
        $this->assertSame('Москва, Тушинская улица, 16А',
            VenueAddressAutocomplete::label($saved));
        $this->assertSame([], VenueAddressAutocomplete::fields($saved));
        $this->assertSame([], VenueAddressAutocomplete::fields('address:invalid'));
        $this->assertNull(VenueAddressAutocomplete::label(null));
    }

    private function yandexResponse(): array
    {
        return [
            'response' => ['GeoObjectCollection' => ['featureMember' => [[
                'GeoObject' => [
                    'metaDataProperty' => ['GeocoderMetaData' => [
                        'Address' => [
                            'postal_code' => '125362',
                            'Components' => [
                                ['kind' => 'country', 'name' => 'Россия'],
                                ['kind' => 'locality', 'name' => 'Москва'],
                                ['kind' => 'area', 'name' => 'Северный административный округ'],
                                ['kind' => 'street', 'name' => 'Тушинская улица'],
                                ['kind' => 'house', 'name' => '16А'],
                            ],
                        ],
                    ]],
                    'Point' => ['pos' => '37.451 55.821'],
                ],
            ]]]],
        ];
    }
}
