<?php

namespace Tests\Feature\PlayInterest;

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueInterestGroupPageTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->previousTheme);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/'.$this->previousTheme.'/views'));
        parent::tearDown();
    }

    public function test_guest_sees_an_empty_group_at_stable_venue_id_url(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $url = route('venues.interest-group', ['venue' => $venue->id]);

        $response = $this->get($url)->assertOk()
            ->assertSee('Площадкой интересуются')
            ->assertSee('Пока никто не подписался')
            ->assertSee('Хочу здесь играть');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $venue->update(['alias' => 'updated-venue-alias']);
        $this->get($url)->assertOk()->assertSee('Площадкой интересуются');
    }

    public function test_group_rejects_unconfirmed_and_nonexistent_venues(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::UNCONFIRMED->value]);
        $this->get(route('venues.interest-group', ['venue' => $venue->id]))->assertNotFound();
        $this->get(route('venues.interest-group', ['venue' => 999999]))->assertNotFound();
    }
}
