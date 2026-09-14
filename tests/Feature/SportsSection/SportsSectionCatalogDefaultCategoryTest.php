<?php

namespace Tests\Feature\SportsSection;

use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionFormatEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingModeEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SportsSectionCatalogDefaultCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.sports_sections.enabled', true);
    }

    public function test_catalog_uses_default_category_and_maps_only_primary_venue_coordinates(): void
    {
        $venue = $this->venueWithCoordinates('Площадка каталога', 55.7558, 37.6176);
        $mapped = SportsSection::factory()->create([
            'name' => 'Секция на карте',
            'status' => SportsSectionStatusEnum::ACTIVE,
            'training_mode' => TrainingModeEnum::GROUP,
            'game_format' => SportsSectionFormatEnum::BASKETBALL,
            'pricing_type' => SectionPricingTypeEnum::PAID,
            'single_session_price_minor' => 150000,
            'primary_venue_id' => $venue->id,
            'accepts_trainee_requests' => true,
            'is_recruiting' => true,
        ]);
        $withoutCoordinates = SportsSection::factory()->create([
            'name' => 'Секция без карты',
            'status' => SportsSectionStatusEnum::ACTIVE,
            'training_mode' => TrainingModeEnum::INDIVIDUAL,
            'game_format' => SportsSectionFormatEnum::STREETBALL,
        ]);
        $team = $this->team($mapped, 'Команда секции');
        $mapped->teams()->attach($team->id);

        $response = $this->get(route('sports-sections.index', ['view' => 'map']));

        $response->assertOk()
            ->assertSee('data-default-category', false)
            ->assertSee('data-default-category-view="map"', false)
            ->assertSee('data-sports-section-category-map', false)
            ->assertSee('Секция на карте')
            ->assertSee('Секция без карты')
            ->assertSee('Команда секции')
            ->assertSee('Идёт набор')
            ->assertSee('Принимает заявки')
            ->assertSee('1 500 ₽ / занятие')
            ->assertSee('data-modal-target="auth-entry-classic"', false)
            ->assertSee('href="'.route('account.sports-sections.create').'"', false)
            ->assertDontSee('data-auth-redirect-url="/account/sections/create"', false);

        $html = $response->getContent();
        preg_match('/<script type="application\/json" data-sports-section-category-map-points>(.*?)<\/script>/s', $html, $matches);
        $this->assertArrayHasKey(1, $matches);
        $points = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(1, $points);
        $this->assertSame('Секция на карте', $points[0]['section']['name']);
        $this->assertSame('Площадка каталога', $points[0]['venue_name']);
        $this->assertSame(55.7558, $points[0]['latitude']);
        $this->assertSame(37.6176, $points[0]['longitude']);
    }

    public function test_filters_use_normalized_section_model_and_team_relation_and_keep_view_state(): void
    {
        $matching = SportsSection::factory()->create([
            'name' => 'Групповая школа',
            'status' => SportsSectionStatusEnum::ACTIVE,
            'training_mode' => TrainingModeEnum::GROUP,
            'game_format' => SportsSectionFormatEnum::BASKETBALL,
            'pricing_type' => SectionPricingTypeEnum::FREE,
            'accepts_trainee_requests' => true,
            'is_recruiting' => false,
        ]);
        $other = SportsSection::factory()->create([
            'name' => 'Индивидуальная школа',
            'status' => SportsSectionStatusEnum::ACTIVE,
            'training_mode' => TrainingModeEnum::INDIVIDUAL,
            'game_format' => SportsSectionFormatEnum::STREETBALL,
            'pricing_type' => SectionPricingTypeEnum::PAID,
            'accepts_trainee_requests' => false,
            'is_recruiting' => false,
        ]);
        $team = $this->team($matching, 'Связанная команда');
        $archivedTeam = $this->team($matching, 'Архивная команда', TeamStatusEnum::ARCHIVED);
        $matching->teams()->attach([$team->id, $archivedTeam->id]);

        $response = $this->get(route('sports-sections.index', [
            'training_mode' => TrainingModeEnum::GROUP->value,
            'game_format' => SportsSectionFormatEnum::BASKETBALL->value,
            'pricing_type' => SectionPricingTypeEnum::FREE->value,
            'team_id' => $team->id,
            'accepts_requests' => 1,
            'view' => 'list',
        ]));

        $response->assertOk()
            ->assertSee('Групповая школа')
            ->assertDontSee('Индивидуальная школа')
            ->assertSee('Связанная команда')
            ->assertDontSee('Архивная команда')
            ->assertSee('data-default-category-view="list"', false)
            ->assertSee('name="team_id" value="'.$team->id.'"', false)
            ->assertSee('Групповой')
            ->assertSee('Баскетбол')
            ->assertDontSee('Малая группа')
            ->assertDontSee('5×5');

        $this->get(route('sports-sections.index', ['team_id' => $other->id]))
            ->assertOk()
            ->assertDontSee('Групповая школа');
    }

    private function venueWithCoordinates(string $name, float $latitude, float $longitude): Venue
    {
        $address = Address::factory()->create([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'full_address' => 'Москва, тестовый адрес',
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);

        return Venue::factory()->create([
            'name' => $name,
            'raw_address' => 'Москва, тестовый адрес',
            'location_id' => $location->id,
        ]);
    }

    private function team(SportsSection $section, string $name, TeamStatusEnum $status = TeamStatusEnum::ACTIVE): Team
    {
        return Team::query()->create([
            'created_by_actor_id' => $section->created_by_actor_id,
            'name' => $name,
            'base_name' => $name,
            'normalized_name' => Str::lower($name).'-'.Str::lower(Str::random(6)),
            'name_sequence' => 1,
            'alias' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'status' => $status,
        ]);
    }
}
