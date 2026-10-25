<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Application\Services\EffectiveVenueHours;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueCourtDetailInheritanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25 12:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_venue_overview_aggregates_photos_while_court_page_uses_own_or_parent_gallery(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $primary = $venue->primaryCourt()->firstOrFail();
        $other = $venue->courts()->create([
            'name' => 'Школьный зал', 'alias' => 'school-hall',
            'sort_order' => 20, 'allows_whole' => true,
        ]);
        $common = $this->photo($venue, 'common.webp');
        $primaryPhoto = $this->photo($primary, 'outdoor.webp');
        $otherPhoto = $this->photo($other, 'indoor.webp');

        $this->get(route('venues.show', $venue->routeIdentifier()))->assertOk()
            ->assertSee($common, false)
            ->assertSee($primaryPhoto, false)
            ->assertSee($otherPhoto, false)
            ->assertSee('Все залы · общая страница')
            ->assertSee(route('venues.courts.show', [$venue->routeIdentifier(), $primary->routeIdentifier()]), false)
            ->assertSee(route('venues.courts.show', [$venue->routeIdentifier(), $other->routeIdentifier()]), false);

        $this->get(route('venues.courts.show', [$venue->routeIdentifier(), $other->routeIdentifier()]))
            ->assertOk()->assertSee($otherPhoto, false)
            ->assertDontSee($common, false)->assertDontSee($primaryPhoto, false)
            ->assertSee('venue_court_id='.$other->id, false);

        $other->media()->delete();
        $this->get(route('venues.courts.show', [$venue->routeIdentifier(), $other->routeIdentifier()]))
            ->assertOk()->assertSee($common, false)
            ->assertDontSee($primaryPhoto, false);
    }

    public function test_individual_schedule_inherits_until_overridden_and_parent_closed_exception_always_wins(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $parent = $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        $parent->intervals()->create([
            'day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '21:00', 'sort_order' => 1,
        ]);
        $court = $venue->primaryCourt()->firstOrFail();
        $monday = CarbonImmutable::parse('2026-10-26 12:00:00', 'Europe/Moscow');
        $hours = app(EffectiveVenueHours::class);

        $this->assertSame('09:00', substr((string) $hours->intervals($venue, $court, $monday)->first()->starts_at, 0, 5));

        $court->schedule()->create([
            'weekly_intervals' => [
                ['day_of_week' => 1, 'starts_at' => '10:00', 'ends_at' => '18:00'],
            ],
            'exceptions' => [
                ['date' => '2026-11-02', 'is_closed' => false, 'starts_at' => '12:00', 'ends_at' => '14:00'],
            ],
        ]);
        $court->unsetRelation('schedule');
        $this->assertSame('10:00', $hours->intervals($venue, $court, $monday)->first()->starts_at);
        $availability = app(VenueEventAvailability::class);
        $this->expectAvailabilityError($availability, $venue, $court, '2026-10-26 09:30:00', '2026-10-26 10:30:00');
        $availability->assertAvailable($venue,
            CarbonImmutable::parse('2026-10-26 12:00:00', 'Europe/Moscow'),
            CarbonImmutable::parse('2026-10-26 13:00:00', 'Europe/Moscow'),
            checkBookings: false, court: $court);
        $this->assertTrue($hours->isException($venue, $court, $monday->addWeek()));

        $parent->exceptions()->create(['date' => '2026-11-02', 'is_closed' => true]);
        $venue->unsetRelation('schedule');
        $this->assertTrue($hours->intervals($venue, $court, $monday->addWeek())->isEmpty());
        $this->expectAvailabilityError($availability, $venue, $court, '2026-11-02 12:00:00', '2026-11-02 13:00:00');

        $this->get(route('venues.courts.show', [$venue->routeIdentifier(), $court->routeIdentifier()]))
            ->assertOk()->assertSee('10:00–18:00')
            ->assertSee('Исключение: выходной');

        $court->schedule()->delete();
        $court->unsetRelation('schedule');
        $this->assertSame('09:00', substr((string) $hours->intervals($venue, $court, $monday)->first()->starts_at, 0, 5));
    }

    private function expectAvailabilityError(
        VenueEventAvailability $availability, Venue $venue, VenueCourt $court,
        string $starts, string $ends,
    ): void {
        try {
            $availability->assertAvailable($venue,
                CarbonImmutable::parse($starts, 'Europe/Moscow'),
                CarbonImmutable::parse($ends, 'Europe/Moscow'),
                checkBookings: false, court: $court);
            $this->fail('Expected availability to reject closed/out-of-hours court.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    private function photo(Venue|VenueCourt $model, string $file): string
    {
        $media = $model->media()->create([
            'collection' => 'gallery', 'source' => 'upload',
            'disk' => 'public', 'path' => 'test/'.$file,
            'mime' => 'image/webp', 'size' => 100,
            'is_featured' => true, 'sort_order' => 0,
        ]);

        return $media->publicUrl();
    }
}
