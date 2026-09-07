<?php

namespace Tests\Feature\Admin;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGeographyTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_admin_can_create_and_update_city_and_district(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.geography.cities.store'), [
                'name' => 'Тестоград',
                'alias' => 'testograd',
                'short_name' => 'ТГ',
                'description' => 'Тестовый город.',
            ])
            ->assertRedirect();

        $city = City::query()->where('alias', 'testograd')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.geography.districts.store'), [
                'city_id' => $city->id,
                'name' => 'Северный район',
                'alias' => 'north',
                'short_name' => 'СР',
                'description' => 'Северная часть города.',
            ])
            ->assertRedirect();

        $district = District::query()->where('city_id', $city->id)->where('alias', 'north')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.geography.cities.update', $city), [
                'name' => 'Тестоград Новый',
                'alias' => 'testograd-new',
                'short_name' => 'ТГН',
                'description' => 'Обновлённое описание.',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->put(route('admin.geography.districts.update', $district), [
                'city_id' => $city->id,
                'name' => 'Северный округ',
                'alias' => 'north-area',
                'short_name' => 'СО',
                'description' => 'Обновлённый район.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cities', [
            'id' => $city->id,
            'name' => 'Тестоград Новый',
            'alias' => 'testograd-new',
        ]);
        $this->assertDatabaseHas('districts', [
            'id' => $district->id,
            'city_id' => $city->id,
            'name' => 'Северный округ',
            'alias' => 'north-area',
        ]);
    }

    public function test_used_geography_cannot_be_deleted_or_moved_to_another_city(): void
    {
        $admin = $this->admin();
        $city = City::factory()->create(['name' => 'Москва', 'alias' => 'moscow-admin-test']);
        $otherCity = City::factory()->create(['name' => 'Химки', 'alias' => 'khimki-admin-test']);
        $district = District::factory()->for($city)->create([
            'name' => 'Северный административный округ',
            'alias' => 'sao-admin-test',
        ]);
        Address::factory()->create([
            'city_id' => $city->id,
            'district_id' => $district->id,
            'city' => 'Москва',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.geography.districts.destroy', $district))
            ->assertSessionHasErrors('geography');

        $this->actingAs($admin)
            ->put(route('admin.geography.districts.update', $district), [
                'city_id' => $otherCity->id,
                'name' => $district->name,
                'alias' => $district->alias,
                'short_name' => $district->short_name,
                'description' => $district->description,
            ])
            ->assertSessionHasErrors('geography');

        $this->actingAs($admin)
            ->delete(route('admin.geography.cities.destroy', $city))
            ->assertSessionHasErrors('geography');

        $this->assertDatabaseHas('districts', [
            'id' => $district->id,
            'city_id' => $city->id,
        ]);
        $this->assertDatabaseHas('cities', ['id' => $city->id]);
    }

    public function test_unused_district_and_city_can_be_deleted(): void
    {
        $admin = $this->admin();
        $city = City::factory()->create();
        $district = District::factory()->for($city)->create();

        $this->actingAs($admin)
            ->delete(route('admin.geography.districts.destroy', $district))
            ->assertRedirect();
        $this->assertDatabaseMissing('districts', ['id' => $district->id]);

        $this->actingAs($admin)
            ->delete(route('admin.geography.cities.destroy', $city))
            ->assertRedirect();
        $this->assertDatabaseMissing('cities', ['id' => $city->id]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
    }
}
